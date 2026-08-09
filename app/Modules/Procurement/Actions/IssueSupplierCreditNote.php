<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\SupplierCreditNoteReasonType;
use App\Modules\Procurement\Domain\SupplierCreditNoteStatus;
use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Models\SupplierCreditNote;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierInvoiceLine;
use App\Modules\Tax\Actions\ComputeLineTax;
use App\Modules\Tax\Actions\ResolveTaxCodeFor;
use App\Modules\Tax\Domain\TaxDirection;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/03-tax-procurement.md §4.8 - issue an avoir fournisseur,
 * series AVF, created and POSTED in one act through PostFromEvent (event
 * `supplier.credit_note.received`) - the reversal of the §4.6 scheme:
 *
 *   Dr 401 / 481x (balancing, supplier partner)
 *       Cr 60x expense       - per line
 *       Cr 4451 déductible   - the TVA déductible REDUCTION
 *       Cr 6xx non déductible
 *
 * The TVA reduction belongs to the declaration period of the CREDIT
 * NOTE's date (§4.8), which is why every line snapshots its own rate at
 * credit_note_date when standalone, or mirrors the ORIGINAL invoice
 * line's snapshot when it corrects one - never a restatement.
 *
 * Against an invoice, Σ credit notes ≤ invoice.total_ttc, checked under
 * FOR UPDATE on the invoice (§4.8).
 *
 * @phpstan-type CreditLine array{description?: string, quantity?: string, unit_price_ht?: int, amount_ht?: int, tax_code_id?: int, expense_account_id?: int, supplier_invoice_line_id?: int|null}
 */
final class IssueSupplierCreditNote
{
    public function __construct(
        private readonly ResolveTaxCodeFor $resolveTaxCode,
        private readonly ComputeLineTax $computeLineTax,
        private readonly PostFromEvent $post,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @phpstan-param list<CreditLine> $lines
     */
    public function handle(array $header, array $lines, Actor $actor): SupplierCreditNote
    {
        Gate::authorize(SupplierInvoicePermission::CREATE);

        if ($lines === []) {
            throw new DomainException('A credit note needs at least one line.');
        }

        return DB::transaction(function () use ($header, $lines, $actor): SupplierCreditNote {
            $idempotencyKey = isset($header['idempotency_key']) ? (string) $header['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var SupplierCreditNote|null $existing */
                $existing = SupplierCreditNote::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $creditNoteDate = Carbon::parse((string) $header['credit_note_date'])->toDateString();
            $reasonType = SupplierCreditNoteReasonType::from((string) $header['reason_type']);

            $originalInvoice = null;

            if (isset($header['original_invoice_id'])) {
                // §4.8: the over-credit check runs under the invoice lock.
                /** @var SupplierInvoice $originalInvoice */
                $originalInvoice = SupplierInvoice::query()
                    ->whereKey((int) $header['original_invoice_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! in_array($originalInvoice->status, [
                    SupplierInvoiceStatus::Posted,
                    SupplierInvoiceStatus::PartiallyPaid,
                    SupplierInvoiceStatus::Paid,
                ], true)) {
                    throw new DomainException(sprintf(
                        'Invoice %s is %s; a credit note corrects a POSTED invoice (03-tax-procurement 4.8).',
                        $originalInvoice->internal_no,
                        $originalInvoice->status->value,
                    ));
                }
            }

            $supplierId = $originalInvoice->supplier_id ?? (int) $header['supplier_id'];
            $payableAccountId = $originalInvoice->payable_account_id
                ?? (isset($header['payable_account_id'])
                    ? (int) $header['payable_account_id']
                    : (int) DB::table('suppliers')->where('id', $supplierId)->value('payable_account_id'));

            $computed = $this->computeLines($lines, $creditNoteDate, $originalInvoice);

            if ($originalInvoice !== null) {
                $alreadyCredited = (int) DB::table('supplier_credit_notes')
                    ->where('original_invoice_id', $originalInvoice->getKey())
                    ->where('status', SupplierCreditNoteStatus::Issued->value)
                    ->sum('total_ttc');

                if ($alreadyCredited + $computed['total_ttc']->amount() > $originalInvoice->total_ttc) {
                    throw new DomainException(sprintf(
                        'Σ credit notes against %s would reach %s, exceeding the invoiced %s (03-tax-procurement 4.8).',
                        $originalInvoice->internal_no,
                        Money::of($alreadyCredited)->plus($computed['total_ttc'])->format(),
                        Money::of($originalInvoice->total_ttc)->format(),
                    ));
                }
            }

            $calendar = $this->calendarFor($creditNoteDate);

            $year = Carbon::parse($creditNoteDate)->format('Y');
            $creditNoteNo = sprintf('AVF/%s/%06d', $year, $this->sequence->allocate('AVF'));

            $creditNote = SupplierCreditNote::query()->create([
                'credit_note_no' => $creditNoteNo,
                'supplier_reference' => isset($header['supplier_reference']) ? (string) $header['supplier_reference'] : null,
                'supplier_id' => $supplierId,
                'original_invoice_id' => $originalInvoice?->getKey(),
                'reason_type' => $reasonType,
                'reason_note' => (string) $header['reason_note'],
                'credit_note_date' => $creditNoteDate,
                'received_date' => isset($header['received_date'])
                    ? Carbon::parse((string) $header['received_date'])->toDateString()
                    : null,
                'subtotal_ht' => $computed['subtotal_ht']->amount(),
                'tax_total' => $computed['tax_total']->amount(),
                'total_ttc' => $computed['total_ttc']->amount(),
                'payable_account_id' => $payableAccountId,
                'status' => SupplierCreditNoteStatus::Draft,
                'academic_year_id' => $calendar['academic_year_id'],
                'fiscal_year_id' => $calendar['fiscal_year_id'],
                'accounting_period_id' => $calendar['accounting_period_id'],
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($computed['rows'] as $row) {
                $creditNote->lines()->create($row);
            }

            $entry = $this->post->handle(
                PostingEvent::SupplierCreditNoteReceived->value,
                [
                    'document' => [
                        'total' => $computed['total_ttc']->amount(),
                        'reference' => $creditNoteNo,
                        'partner' => ['type' => 'supplier', 'id' => $supplierId],
                        'payable_account_id' => $payableAccountId,
                        'lines' => $computed['legs'],
                    ],
                ],
                $creditNoteDate,
                $actor,
                $creditNoteNo,
            );

            $creditNote->forceFill([
                'status' => SupplierCreditNoteStatus::Issued,
                'posted_at' => now(),
                'journal_entry_id' => (int) $entry->getKey(),
            ])->save();

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: SupplierCreditNote::class,
                auditableId: (int) $creditNote->getKey(),
                after: [
                    'credit_note_no' => $creditNoteNo,
                    'supplier_id' => $supplierId,
                    'original_invoice_id' => $originalInvoice?->getKey(),
                    'total_ttc' => $computed['total_ttc']->amount(),
                ],
                actor: $actor,
            );

            return $creditNote->refresh();
        });
    }

    /**
     * Lines either MIRROR an original invoice line (same tax_code_id,
     * expense_account_id and rate snapshot - §4.8) or are standalone with
     * their own snapshot at credit_note_date.
     *
     * @phpstan-param list<CreditLine> $lines
     *
     * @return array{rows: list<array<string, mixed>>, legs: list<array{amount: int, expense_account_id: int, label: string}>, subtotal_ht: Money, tax_total: Money, total_ttc: Money}
     */
    private function computeLines(array $lines, string $creditNoteDate, ?SupplierInvoice $originalInvoice): array
    {
        $rows = [];
        $legsByAccount = [];
        $subtotal = Money::zero();
        $taxTotal = Money::zero();

        foreach ($lines as $index => $input) {
            $originalLine = null;

            if (($input['supplier_invoice_line_id'] ?? null) !== null) {
                if ($originalInvoice === null) {
                    throw new DomainException('A credit-note line can only reference an invoice line when the credit note names the invoice.');
                }

                /** @var SupplierInvoiceLine $originalLine */
                $originalLine = SupplierInvoiceLine::query()->findOrFail((int) $input['supplier_invoice_line_id']);

                if ($originalLine->supplier_invoice_id !== (int) $originalInvoice->getKey()) {
                    throw new DomainException("Invoice line {$originalLine->id} does not belong to invoice {$originalInvoice->internal_no}.");
                }
            }

            $quantity = (string) ($input['quantity'] ?? '1');
            $unitPrice = (int) ($input['unit_price_ht'] ?? $originalLine->unit_price_ht ?? 0);
            $amountHt = isset($input['amount_ht'])
                ? (int) $input['amount_ht']
                : LineAmount::compute($quantity, $unitPrice);

            if ($amountHt <= 0) {
                throw new DomainException('A credit note line must carry a positive HT amount.');
            }

            $taxCodeId = (int) ($input['tax_code_id'] ?? $originalLine->tax_code_id ?? 0);
            $expenseAccountId = (int) ($input['expense_account_id'] ?? $originalLine->expense_account_id ?? 0);

            if ($taxCodeId === 0 || $expenseAccountId === 0) {
                throw new DomainException(sprintf(
                    'Credit note line %d needs a tax code and an expense account (mirrored from the invoice line or given).',
                    $index + 1,
                ));
            }

            if ($originalLine !== null) {
                // Mirror the original snapshot: the avoir undoes tax at the
                // rate the invoice APPLIED, whatever today's rate is.
                $rateBp = $originalLine->tax_rate_bp_applied;
                $tax = $this->proportionalTax($originalLine, $amountHt);
            } else {
                $taxCode = $this->resolveTaxCode->handle($taxCodeId, $creditNoteDate);
                $rateBp = $taxCode->rate_bp;
                $lineTax = $this->computeLineTax->handle($amountHt, $taxCodeId, $creditNoteDate, TaxDirection::Input);
                $tax = ['tax' => $lineTax->taxAmount, 'deductible' => $lineTax->deductible, 'non_deductible' => $lineTax->nonDeductible];
            }

            $rows[] = [
                'line_no' => $index + 1,
                'supplier_invoice_line_id' => $originalLine?->id,
                'description' => (string) ($input['description'] ?? $originalLine->description ?? 'Avoir'),
                'quantity' => $quantity,
                'unit_price_ht' => $unitPrice,
                'amount_ht' => $amountHt,
                'tax_code_id' => $taxCodeId,
                'tax_rate_bp_applied' => $rateBp,
                'tax_amount' => $tax['tax'],
                'deductible_tax_amount' => $tax['deductible'],
                'non_deductible_tax_amount' => $tax['non_deductible'],
                'expense_account_id' => $expenseAccountId,
            ];

            // The credit legs of the reversal posting (negative amounts on
            // the signed iterating rule line; the balancing Dr hits the
            // payable).
            $this->accumulateLeg($legsByAccount, $expenseAccountId, $amountHt, $rows[$index]['description']);

            if ($tax['deductible'] > 0) {
                $accountId = $this->deductibleAccountFor($taxCodeId);
                $this->accumulateLeg($legsByAccount, $accountId, $tax['deductible'], 'TVA déductible - reprise');
            }

            if ($tax['non_deductible'] > 0) {
                $accountId = $this->nonDeductibleAccountFor($taxCodeId);
                $this->accumulateLeg($legsByAccount, $accountId, $tax['non_deductible'], 'TVA non déductible - reprise');
            }

            $subtotal = $subtotal->plus(Money::of($amountHt));
            $taxTotal = $taxTotal->plus(Money::of($tax['tax']));
        }

        $legs = [];

        foreach ($legsByAccount as $leg) {
            $legs[] = ['amount' => -$leg['amount'], 'expense_account_id' => $leg['account_id'], 'label' => $leg['label']];
        }

        return [
            'rows' => $rows,
            'legs' => $legs,
            'subtotal_ht' => $subtotal,
            'tax_total' => $taxTotal,
            'total_ttc' => $subtotal->plus($taxTotal),
        ];
    }

    /**
     * The credited share of the original line's tax, allocated
     * proportionally and CONSERVED: deductible by allocation, tax by the
     * same rate share, non-deductible by subtraction.
     *
     * @return array{tax: int, deductible: int, non_deductible: int}
     */
    private function proportionalTax(SupplierInvoiceLine $originalLine, int $amountHt): array
    {
        if ($originalLine->amount_ht <= 0 || $originalLine->tax_amount === 0) {
            return ['tax' => 0, 'deductible' => 0, 'non_deductible' => 0];
        }

        if ($amountHt > $originalLine->amount_ht) {
            throw new DomainException(sprintf(
                'A credit of %d HT exceeds the original line\'s %d HT.',
                $amountHt,
                $originalLine->amount_ht,
            ));
        }

        if ($amountHt === $originalLine->amount_ht) {
            return [
                'tax' => $originalLine->tax_amount,
                'deductible' => $originalLine->deductible_tax_amount,
                'non_deductible' => $originalLine->non_deductible_tax_amount,
            ];
        }

        // Integer-proportional, rounded half up ONCE per component; the
        // non-deductible remainder comes by subtraction (00-core §7.3).
        $tax = intdiv($originalLine->tax_amount * $amountHt + intdiv($originalLine->amount_ht, 2), $originalLine->amount_ht);
        $deductible = intdiv($originalLine->deductible_tax_amount * $amountHt + intdiv($originalLine->amount_ht, 2), $originalLine->amount_ht);
        $deductible = min($deductible, $tax);

        return ['tax' => $tax, 'deductible' => $deductible, 'non_deductible' => $tax - $deductible];
    }

    /**
     * @param  array<int, array{account_id: int, amount: int, label: string}>  $legs
     */
    private function accumulateLeg(array &$legs, int $accountId, int $amount, string $label): void
    {
        if (isset($legs[$accountId])) {
            $legs[$accountId]['amount'] += $amount;
        } else {
            $legs[$accountId] = ['account_id' => $accountId, 'amount' => $amount, 'label' => $label];
        }
    }

    private function deductibleAccountFor(int $taxCodeId): int
    {
        $id = DB::table('tax_codes')->where('id', $taxCodeId)->value('deductible_account_id');

        if ($id === null) {
            throw new DomainException('The tax code has no TVA déductible account configured (03-tax-procurement 5.3).');
        }

        return (int) $id;
    }

    private function nonDeductibleAccountFor(int $taxCodeId): int
    {
        $id = DB::table('tax_codes')->where('id', $taxCodeId)->value('non_deductible_expense_account_id');

        if ($id === null) {
            throw new DomainException('The tax code has no non-deductible TVA expense account configured (03-tax-procurement 5.3).');
        }

        return (int) $id;
    }

    /**
     * @return array{fiscal_year_id: int, accounting_period_id: int, academic_year_id: int}
     */
    private function calendarFor(string $date): array
    {
        $period = DB::table('accounting_periods')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->first(['id', 'fiscal_year_id']);

        if ($period === null) {
            throw new DomainException("No accounting period covers {$date}; the fiscal-year calendar is misconfigured.");
        }

        $academicYearId = (int) DB::table('academic_years')
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->orderBy('starts_on')
            ->value('id');

        if ($academicYearId === 0) {
            throw new DomainException("No academic year covers {$date} (02-accounting C3 dual calendar).");
        }

        return [
            'fiscal_year_id' => (int) $period->fiscal_year_id,
            'accounting_period_id' => (int) $period->id,
            'academic_year_id' => $academicYearId,
        ];
    }
}
