<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\AdjustmentApplicationMethod;
use App\Modules\Fees\Domain\FeeAdjustmentReasonType;
use App\Modules\Fees\Domain\FeeAdjustmentStatus;
use App\Modules\Fees\Domain\InvoiceStatus;
use App\Modules\Fees\Models\FeeAdjustment;
use App\Modules\Fees\Models\Invoice;
use App\Modules\Fees\Models\InvoiceLine;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/04-fees.md §8 - C2. Records a PENDING, line-anchored, SIGNED
 * fee adjustment: positive reduces outstanding, negative is a surcharge and
 * increases it. Approval - and the ledger consequence - is a separate step
 * (ApproveFeeAdjustment) because granted_by and approved_by must differ.
 *
 * A reduction may not relieve more than remains billed: checked here under
 * the invoice lock (§5.3 shape). A surcharge has no such ceiling.
 *
 * @phpstan-type AdjustmentInput array{invoice_line_id: int, amount: int, reason_type: FeeAdjustmentReasonType, reason_note: string, adjustment_account_id: int, effective_date: string, application_method?: AdjustmentApplicationMethod, target_installment_id?: int|null, counterpart_account_id?: int|null, donor_id?: int|null}
 */
final class AdjustInvoice
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @phpstan-param AdjustmentInput $input
     */
    public function handle(array $input, Actor $actor): FeeAdjustment
    {
        Gate::authorize(Permission::FeeCollect->value);

        $amount = Money::of($input['amount']);

        if ($amount->amount() === 0) {
            throw new DomainException('A fee adjustment of zero is meaningless; the CHECK (amount <> 0) exists for a reason.');
        }

        $method = $input['application_method'] ?? AdjustmentApplicationMethod::EarliestFirst;
        $targetInstallmentId = $input['target_installment_id'] ?? null;

        if (($method === AdjustmentApplicationMethod::SpecificInstalment) !== ($targetInstallmentId !== null)) {
            throw new DomainException('target_installment_id is required exactly when application_method is specific_instalment.');
        }

        return DB::transaction(function () use ($input, $amount, $method, $targetInstallmentId, $actor): FeeAdjustment {
            /** @var InvoiceLine $line */
            $line = InvoiceLine::query()->lockForUpdate()->findOrFail($input['invoice_line_id']);

            /** @var Invoice $invoice */
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($line->invoice_id);

            if ($invoice->status !== InvoiceStatus::Issued) {
                throw new DomainException("Invoice {$invoice->id} is {$invoice->status->value}; adjustments target ISSUED invoices - a draft is simply edited.");
            }

            if ($amount->amount() > 0) {
                $this->assertRelievable($line, $amount);
            }

            $year = Carbon::parse($input['effective_date'])->format('Y');
            $number = $this->sequence->allocate('fee_adjustment_no');

            $adjustment = FeeAdjustment::query()->create([
                'reference_no' => sprintf('ADJ/%s/%06d', $year, $number),
                'invoice_line_id' => $line->id,
                'enrollment_id' => $invoice->enrollment_id,
                'student_id' => $invoice->student_id,
                'academic_year_id' => $invoice->academic_year_id,
                'fiscal_year_id' => $invoice->fiscal_year_id,
                'amount' => $amount->amount(),
                'reason_type' => $input['reason_type'],
                'reason_note' => $input['reason_note'],
                'adjustment_account_id' => $input['adjustment_account_id'],
                'counterpart_account_id' => $input['counterpart_account_id'] ?? null,
                'donor_id' => $input['donor_id'] ?? null,
                'application_method' => $method,
                'target_installment_id' => $targetInstallmentId,
                'effective_date' => $input['effective_date'],
                'status' => FeeAdjustmentStatus::Pending,
                'granted_by' => $actor->id,
            ]);

            $this->audit->handle(
                AuditAction::Created,
                'fees',
                FeeAdjustment::class,
                (int) $adjustment->getKey(),
                null,
                ['reference_no' => $adjustment->reference_no, 'amount' => $amount->amount(), 'status' => 'pending'],
                $actor,
            );

            return $adjustment;
        });
    }

    /**
     * §5.3 shape under the lock: a REDUCTION may not exceed what remains
     * billed on the line after prior approved adjustments and credit notes.
     */
    private function assertRelievable(InvoiceLine $line, Money $amount): void
    {
        $invoiced = Money::of($line->amount + $line->tax_amount);

        $adjusted = (int) DB::table('fee_adjustments')
            ->where('invoice_line_id', $line->id)
            ->where('status', 'approved')
            ->sum('amount');

        $credited = (int) DB::table('credit_note_lines')
            ->join('credit_notes', 'credit_notes.id', '=', 'credit_note_lines.credit_note_id')
            ->where('credit_note_lines.invoice_line_id', $line->id)
            ->where('credit_notes.status', 'issued')
            ->sum('credit_note_lines.amount');

        $remaining = $invoiced->minus(Money::of($adjusted))->minus(Money::of($credited));

        if ($amount->amount() > $remaining->amount()) {
            throw new DomainException(sprintf(
                "Adjustment of %d exceeds the %d remaining billed on line '%s'.",
                $amount->amount(),
                $remaining->amount(),
                $line->description,
            ));
        }
    }
}
