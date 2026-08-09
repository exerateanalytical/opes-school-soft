<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Fees\Domain\PaymentVoidStatus;
use App\Modules\Fees\Models\Payment;
use App\Modules\Fees\Models\PaymentAllocation;
use App\Modules\Fees\Models\PaymentVoid;
use App\Modules\Fees\Models\Receipt;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/04-fees.md §11.5 - THE correction path. A recorded payment is
 * never edited; it is voided (this Action) and, if money genuinely changed
 * hands, re-recorded correctly.
 *
 * The ledger reversal goes through the Phase 4 ReverseJournalEntry Action
 * against the payment's stored `journal_entry_id` - NEVER a hand-built
 * contra entry - so the reversal carries `reverses_entry_id`, lands in the
 * earliest OPEN period (02-accounting C9), unletters the original's
 * groups, and both halves stay visible in every statement, netting to
 * zero (§15.4).
 *
 * Void cascade, in order, one transaction (§11.5):
 *  1. payment row FOR UPDATE;
 *  2. every live allocation stamped reversed_* - never deleted;
 *  3. unallocated_amount -> 0 (the payment contributes nothing);
 *  4. the confirmed PaymentVoid row (generated-column UNIQUE: once);
 *  5. ledger reversal via ReverseJournalEntry;
 *  6. every Receipt issuance flagged voided - reprint blocked, printed
 *     copies discoverable.
 */
final class VoidPayment
{
    private const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly ReverseJournalEntry $reverse,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $paymentId, PaymentVoidReason $reason, string $reasonNote, Actor $actor): PaymentVoid
    {
        Gate::authorize(Permission::FeeVoid->value);

        if (mb_strlen(trim($reasonNote)) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason_note' => sprintf('A void reason must be at least %d characters (04-fees §11.5).', self::MIN_REASON_LENGTH),
            ]);
        }

        return DB::transaction(function () use ($paymentId, $reason, $reasonNote, $actor): PaymentVoid {
            $payment = Payment::lockForAllocation($paymentId);

            if ($payment->isVoided()) {
                throw new DomainException(sprintf(
                    'Payment %s is already voided; a payment is voided at most once (04-fees §11.5).',
                    $payment->receipt_no,
                ));
            }

            // Segregation of duties, rule 1: hard, no override.
            if ($payment->received_by === $actor->id) {
                throw new DomainException(
                    'The cashier who recorded a payment cannot void it (04-fees §11.5 segregation of duties).'
                );
            }

            // Cascade step 2: reverse every live allocation - rows survive.
            /** @var list<PaymentAllocation> $allocations */
            $allocations = PaymentAllocation::query()
                ->where('payment_id', $payment->getKey())
                ->whereNull('reversed_at')
                ->orderBy('invoice_id')
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($allocations as $allocation) {
                $allocation->reversed_at = now();
                $allocation->reversed_by = $actor->id;
                $allocation->reversal_reason = 'payment_void: '.$reason->value;
                $allocation->save();
            }

            // Cascade step 3.
            $payment->unallocated_amount = 0;
            $payment->save();

            // Cascade step 5: the reversal of THE original entry. A payment
            // recorded by this module always carries its entry; only a
            // migration-imported payment may legitimately have none.
            $reversalEntryId = null;

            if ($payment->journal_entry_id !== null) {
                $reversal = $this->reverse->handle(
                    $payment->journal_entry_id,
                    sprintf('Void of receipt %s (%s): %s', $payment->receipt_no, $reason->value, $reasonNote),
                    $actor,
                );
                $reversalEntryId = (int) $reversal->getKey();
            }

            // Cascade step 4: the void record itself, born confirmed - the
            // generated-column UNIQUE makes a second confirmed void a
            // constraint violation, not a race.
            /** @var PaymentVoid $void */
            $void = PaymentVoid::query()->create([
                'payment_id' => $payment->getKey(),
                'reason_type' => $reason,
                'reason_note' => $reasonNote,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'reversal_journal_entry_id' => $reversalEntryId,
                'status' => PaymentVoidStatus::Confirmed,
            ]);

            // Cascade step 6: block reprints of every issued copy.
            /** @var list<Receipt> $receipts */
            $receipts = Receipt::query()
                ->where('payment_id', $payment->getKey())
                ->where('is_voided', false)
                ->get()
                ->all();

            foreach ($receipts as $receipt) {
                $receipt->is_voided = true;
                $receipt->save();
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: PaymentVoid::class,
                auditableId: (int) $void->getKey(),
                after: [
                    'payment_id' => (int) $payment->getKey(),
                    'receipt_no' => $payment->receipt_no,
                    'reason_type' => $reason->value,
                    'reason_note' => $reasonNote,
                    'allocations_reversed' => count($allocations),
                    'reversal_journal_entry_id' => $reversalEntryId,
                ],
                actor: $actor,
            );

            return $void;
        });
    }
}
