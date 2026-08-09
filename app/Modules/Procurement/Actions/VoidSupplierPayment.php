<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Accounting\Actions\ReverseJournalEntry;
use App\Modules\Accounting\Actions\UnletterGroup;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use App\Modules\Procurement\Domain\SupplierPaymentPermission;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use App\Modules\Procurement\Domain\SupplierRetentionStatus;
use App\Modules\Procurement\Models\SupplierInvoice;
use App\Modules\Procurement\Models\SupplierPayment;
use App\Modules\Procurement\Models\SupplierPaymentAllocation;
use App\Modules\Procurement\Models\SupplierPaymentVoid;
use App\Modules\Procurement\Models\SupplierRetention;
use App\Modules\Tax\Actions\CancelWithholdingAttestation;
use App\Modules\Tax\Domain\AttestationStatus;
use App\Modules\Tax\Domain\WithholdingRecognition;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.7 / §11.9 - THE payment correction
 * path. A paid payment is immutable; this Action reverses its every
 * consequence in ONE transaction, in order:
 *
 *  1. payment row FOR UPDATE; a second void is refused (UNIQUE at the DB);
 *  2. SoD: `recorded_by ≠ voided_by`, hard, no permission overrides it;
 *  3. every live allocation stamped reversed - never deleted;
 *  4. lettering groups undone through the real UnletterGroup Action;
 *  5. ledger reversal of THE stored entries via ReverseJournalEntry (C9:
 *     dated in the earliest OPEN period, never the original date) - the
 *     settlement entry AND this payment's retention reclass;
 *  6. issued attestations for this payment CANCELLED in the same
 *     transaction - an attestation for a payment that never happened is a
 *     false tax document (§6.6 invariant 2). An attestation already in a
 *     FILED declaration blocks the void (amend the declaration first);
 *  7. invoices re-opened (posted / partially_paid recomputed);
 *  8. the SupplierPaymentVoid record, then status = voided.
 *
 * A draft/approved payment (no ledger trace yet) voids as a plain
 * cancellation: steps 3, 7 and 8 only.
 */
final class VoidSupplierPayment
{
    private const MIN_REASON_LENGTH = 10;

    public function __construct(
        private readonly ComputeInvoiceSettlement $settlement,
        private readonly ReverseJournalEntry $reverse,
        private readonly UnletterGroup $unletter,
        private readonly CancelWithholdingAttestation $cancelAttestation,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $paymentId, string $reason, Actor $actor): SupplierPaymentVoid
    {
        Gate::authorize(SupplierPaymentPermission::VOID);

        if (mb_strlen(trim($reason)) < self::MIN_REASON_LENGTH) {
            throw ValidationException::withMessages([
                'reason' => sprintf('A void reason must be at least %d characters (03-tax-procurement 4.7).', self::MIN_REASON_LENGTH),
            ]);
        }

        return DB::transaction(function () use ($paymentId, $reason, $actor): SupplierPaymentVoid {
            /** @var SupplierPayment $payment */
            $payment = SupplierPayment::query()->whereKey($paymentId)->lockForUpdate()->firstOrFail();

            if ($payment->isVoided()) {
                throw new DomainException(sprintf(
                    'Payment %s is already voided; a payment is voided at most once (03-tax-procurement 4.7).',
                    $payment->payment_no,
                ));
            }

            if ($payment->recorded_by === $actor->id) {
                throw new DomainException(
                    'The clerk who recorded a payment cannot void it (03-tax-procurement 11.14 segregation of duties).'
                );
            }

            $wasPaid = $payment->status === SupplierPaymentStatus::Paid;

            // Retention released downstream? The reclass cannot be unwound.
            /** @var list<SupplierRetention> $retentions */
            $retentions = SupplierRetention::query()
                ->where('supplier_payment_id', $payment->getKey())
                ->lockForUpdate()
                ->get()
                ->all();

            foreach ($retentions as $retention) {
                if ($retention->status === SupplierRetentionStatus::Released) {
                    throw new DomainException(sprintf(
                        'Payment %s withheld a retention that has since been RELEASED; the chain cannot be unwound by a void (03-tax-procurement 3.3).',
                        $payment->payment_no,
                    ));
                }
            }

            // 6. Attestations first: a filed declaration blocks the whole
            // void before anything is mutated.
            /** @var list<int> $attestationIds */
            $attestationIds = DB::table('withholding_attestations')
                ->where('supplier_payment_id', $payment->getKey())
                ->where('status', AttestationStatus::Issued->value)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            foreach ($attestationIds as $attestationId) {
                $this->cancelAttestation->handle(
                    $attestationId,
                    sprintf('Void of payment %s: %s', $payment->payment_no, $reason),
                    $actor,
                );
            }

            // 3 + 4. Allocations reversed, lettering undone.
            /** @var list<SupplierPaymentAllocation> $allocations */
            $allocations = $payment->allocations()
                ->whereNull('reversed_at')
                ->orderBy('supplier_invoice_id')
                ->lockForUpdate()
                ->get()
                ->all();

            $unletteredGroups = [];

            foreach ($allocations as $allocation) {
                if ($allocation->lettering_id !== null && ! in_array($allocation->lettering_id, $unletteredGroups, true)) {
                    $this->unletter->handle(
                        $allocation->lettering_id,
                        sprintf('Void of payment %s', $payment->payment_no),
                        $actor,
                    );
                    $unletteredGroups[] = $allocation->lettering_id;
                }

                $allocation->forceFill([
                    'reversed_at' => now(),
                    'reversed_by' => $actor->id,
                    'reversal_reason' => 'payment_void',
                ])->save();
            }

            // 5. Ledger reversal of THE entries - never a hand-built contra.
            $reversalEntryId = null;

            if ($wasPaid && $payment->journal_entry_id !== null) {
                $reversal = $this->reverse->handle(
                    $payment->journal_entry_id,
                    sprintf('Void of payment %s: %s', $payment->payment_no, $reason),
                    $actor,
                );
                $reversalEntryId = (int) $reversal->getKey();
            }

            foreach ($retentions as $retention) {
                if ($retention->status !== SupplierRetentionStatus::Withheld) {
                    continue;
                }

                $this->reverse->handle(
                    $retention->withheld_journal_entry_id,
                    sprintf('Void of payment %s: retention reclass undone', $payment->payment_no),
                    $actor,
                );

                $retention->forceFill(['status' => SupplierRetentionStatus::Cancelled])->save();
            }

            // 7. Re-open the invoices.
            $recognition = $this->settlement->recognitionBasis();

            foreach ($allocations as $allocation) {
                /** @var SupplierInvoice $invoice */
                $invoice = SupplierInvoice::query()
                    ->whereKey($allocation->supplier_invoice_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $allocated = $this->settlement->allocatedOf((int) $invoice->getKey(), (int) $payment->getKey());
                $settleable = $this->settlement->settleableOf($invoice, $recognition);

                $status = SupplierInvoiceStatus::Posted;

                if ($allocated > 0) {
                    $status = $allocated >= $settleable ? SupplierInvoiceStatus::Paid : SupplierInvoiceStatus::PartiallyPaid;
                }

                $invoice->forceFill([
                    'status' => $status,
                    'version' => $invoice->version + 1,
                ])->save();
            }

            // 8. The void record; the generated UNIQUE makes a concurrent
            // second void a constraint violation, not a race.
            /** @var SupplierPaymentVoid $void */
            $void = SupplierPaymentVoid::query()->create([
                'supplier_payment_id' => $payment->getKey(),
                'reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'reversal_journal_entry_id' => $reversalEntryId,
            ]);

            $payment->forceFill([
                'status' => SupplierPaymentStatus::Voided,
                'version' => $payment->version + 1,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: SupplierPaymentVoid::class,
                auditableId: (int) $void->getKey(),
                after: [
                    'payment_no' => $payment->payment_no,
                    'reason' => $reason,
                    'allocations_reversed' => count($allocations),
                    'attestations_cancelled' => count($attestationIds),
                    'reversal_journal_entry_id' => $reversalEntryId,
                ],
                actor: $actor,
            );

            return $void;
        });
    }
}
