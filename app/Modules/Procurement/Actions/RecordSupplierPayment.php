<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierFeeBearer;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Domain\SupplierPaymentClearingState;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Models\Supplier;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Procurement\Models\SupplierPaymentAllocation;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.7 - record a supplier payment DRAFT:
 * which invoices, how much against each, by what method.
 *
 * Concurrency: allocation takes `SELECT … FOR UPDATE` on every target
 * `SupplierInvoice` row (in key order - no deadlock by ordering) and
 * recomputes outstanding INSIDE the lock - the payables mirror of 00-core
 * §11's payment-allocation rule. A draft's allocation RESERVES the amount:
 * two clerks paying the same invoice see each other's reservations.
 *
 * Withholding (on_payment): resolved at the PAYMENT date (§6.3) and
 * recorded per allocation as the portion of the allocated amount settled
 * by withholding rather than cash - the §6.4 preview the payer confirms.
 * Amounts are re-verified at pay time by PaySupplierPayment; nothing posts
 * here (SoD §11.14: the recorder drafts, an approver signs, then it pays).
 *
 * One payment settles ONE payable family: invoices with different payable
 * accounts (401 vs 481x) are separate payments, mirroring §3.3's "never a
 * single payable line spanning both families".
 */
final class RecordSupplierPayment
{
    public function __construct(
        private readonly ComputeInvoiceSettlement $settlement,
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     supplier_id: int,
     *     payment_method: string,
     *     treasury_account_id: int,
     *     allocations: list<array{supplier_invoice_id: int, amount: int}>,
     *     payment_date?: string,
     *     reference?: string|null,
     *     fee_amount?: int,
     *     fee_bearer?: string,
     *     fee_expense_account_id?: int|null,
     *     notes?: string|null,
     *     idempotency_key?: string|null,
     * } $input
     */
    public function handle(array $input, Actor $actor): SupplierPayment
    {
        Gate::authorize(SupplierPaymentPermission::RECORD);

        $method = PaymentMethod::from($input['payment_method']);
        $paymentDate = $input['payment_date'] ?? BusinessDate::today();
        $feeAmount = (int) ($input['fee_amount'] ?? 0);
        $feeBearer = SupplierFeeBearer::from($input['fee_bearer'] ?? SupplierFeeBearer::School->value);
        $reference = isset($input['reference']) ? trim((string) $input['reference']) : '';

        if ($input['allocations'] === []) {
            throw ValidationException::withMessages([
                'allocations' => 'A payment must allocate at least one invoice (03-tax-procurement 4.7).',
            ]);
        }

        if ($method->requiresReference() && $reference === '') {
            throw ValidationException::withMessages([
                'reference' => sprintf('A %s payment requires the transaction reference (04-fees 2.4).', $method->value),
            ]);
        }

        if ($feeAmount < 0) {
            throw ValidationException::withMessages(['fee_amount' => 'An operator fee cannot be negative.']);
        }

        if ($feeAmount > 0 && $feeBearer === SupplierFeeBearer::School && ($input['fee_expense_account_id'] ?? null) === null) {
            throw ValidationException::withMessages([
                'fee_expense_account_id' => 'A school-borne operator fee needs its expense account (6317 family, 02-accounting).',
            ]);
        }

        $recognition = $this->settlement->recognitionBasis();

        return DB::transaction(function () use ($input, $actor, $method, $paymentDate, $feeAmount, $feeBearer, $reference, $recognition): SupplierPayment {
            $idempotencyKey = $input['idempotency_key'] ?? null;

            if ($idempotencyKey !== null) {
                /** @var SupplierPayment|null $existing */
                $existing = SupplierPayment::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail($input['supplier_id']);

            if (! $supplier->is_active || $supplier->is_archived) {
                throw new DomainException("Supplier {$supplier->code} is not active; payments to it are blocked (03-tax-procurement 3.1).");
            }

            // §9: FOR UPDATE in key order; outstanding recomputed inside.
            $targets = $input['allocations'];
            usort($targets, static fn (array $a, array $b): int => $a['supplier_invoice_id'] <=> $b['supplier_invoice_id']);

            $rows = [];
            $gross = 0;
            $withholding = 0;
            $payableAccountId = null;
            $firstInvoice = null;

            foreach ($targets as $target) {
                /** @var SupplierInvoice $invoice */
                $invoice = SupplierInvoice::query()
                    ->whereKey($target['supplier_invoice_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                $firstInvoice ??= $invoice;
                $amount = $target['amount'];

                if ($amount <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => "Allocation against {$invoice->internal_no} must be positive.",
                    ]);
                }

                if ($invoice->supplier_id !== $supplier->getKey()) {
                    throw new DomainException("Invoice {$invoice->internal_no} belongs to another supplier.");
                }

                if (! in_array($invoice->status, [SupplierInvoiceStatus::Posted, SupplierInvoiceStatus::PartiallyPaid], true)) {
                    throw new DomainException(sprintf(
                        'Invoice %s is %s; only a posted invoice with an outstanding balance can be paid.',
                        $invoice->internal_no,
                        $invoice->status->value,
                    ));
                }

                $payableAccountId ??= $invoice->payable_account_id;

                if ($invoice->payable_account_id !== $payableAccountId) {
                    throw new DomainException(
                        'One payment settles one payable family; record separate payments for 401 and 481x invoices (03-tax-procurement 3.3).'
                    );
                }

                $settleable = $this->settlement->settleableOf($invoice, $recognition);
                $outstanding = $settleable - $this->settlement->allocatedOf((int) $invoice->getKey());

                if ($amount > $outstanding) {
                    throw ValidationException::withMessages([
                        'allocations' => sprintf(
                            'Invoice %s has %d outstanding (recomputed under lock); an allocation of %d over-settles it (03-tax-procurement 4.7).',
                            $invoice->internal_no,
                            $outstanding,
                            $amount,
                        ),
                    ]);
                }

                $allocationWithholding = 0;

                if ($recognition === WithholdingRecognition::OnPayment) {
                    $resolved = $this->settlement->withholdingAt($invoice, $paymentDate);
                    $allocationWithholding = $this->settlement->withholdingShare(
                        allocation: $amount,
                        outstandingBefore: $outstanding,
                        settleable: $settleable,
                        fullWithholding: $resolved['total'],
                        alreadyWithheld: $this->settlement->withheldOf((int) $invoice->getKey()),
                    );
                }

                $rows[] = [
                    'invoice' => $invoice,
                    'amount' => $amount,
                    'withholding_amount' => $allocationWithholding,
                ];

                $gross += $amount;
                $withholding += $allocationWithholding;
            }

            /** @var SupplierInvoice $firstInvoice */
            $period = $this->periodContaining($paymentDate);

            $paymentNo = sprintf(
                'PF/%s/%06d',
                Carbon::parse($paymentDate)->format('Y'),
                $this->sequences->allocate('PF'),
            );

            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->create([
                'payment_no' => $paymentNo,
                'supplier_id' => $supplier->getKey(),
                'payment_date' => $paymentDate,
                'payment_method' => $method->value,
                'treasury_account_id' => $input['treasury_account_id'],
                'reference' => $reference === '' ? null : $reference,
                'gross_amount' => $gross,
                'withholding_amount' => $withholding,
                'fee_amount' => $feeAmount,
                'fee_bearer' => $feeBearer,
                'fee_expense_account_id' => $input['fee_expense_account_id'] ?? null,
                'net_amount' => $gross - $withholding,
                'status' => SupplierPaymentStatus::Draft,
                'clearing_state' => SupplierPaymentClearingState::NotApplicable,
                'recorded_by' => $actor->id,
                'academic_year_id' => $firstInvoice->academic_year_id,
                'fiscal_year_id' => $period['fiscal_year_id'],
                'accounting_period_id' => $period['id'],
                'notes' => $input['notes'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($rows as $row) {
                SupplierPaymentAllocation::query()->create([
                    'supplier_payment_id' => $payment->getKey(),
                    'supplier_invoice_id' => $row['invoice']->getKey(),
                    'amount' => $row['amount'],
                    'withholding_amount' => $row['withholding_amount'],
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: SupplierPayment::class,
                auditableId: (int) $payment->getKey(),
                after: [
                    'payment_no' => $paymentNo,
                    'supplier_id' => (int) $supplier->getKey(),
                    'gross_amount' => $gross,
                    'withholding_amount' => $withholding,
                    'invoices' => array_map(
                        static fn (array $row): string => $row['invoice']->internal_no,
                        $rows,
                    ),
                ],
                actor: $actor,
            );

            return $payment->refresh();
        });
    }

    /**
     * @return array{id: int, fiscal_year_id: int}
     */
    private function periodContaining(string $date): array
    {
        $row = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first(['id', 'fiscal_year_id']);

        if ($row === null) {
            throw new DomainException("No accounting period covers {$date}; open the calendar first (02-accounting 5).");
        }

        return ['id' => (int) $row->id, 'fiscal_year_id' => (int) $row->fiscal_year_id];
    }
}
