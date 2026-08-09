<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Fees\Actions\IssueCreditNote;
use App\Modules\Fees\Domain\CreditNoteReasonType;
use App\Modules\Fees\Domain\CreditNoteSettlementMode;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\FineStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\LibraryFine;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.6 - waivers are contra-revenue against the same
 * income account, require a permission and a reason, and THE APPROVER MAY
 * NOT BE THE PERSON WHO LEVIED THE FINE.
 *
 * An `assessed` fine waives in place (nothing ever posted). An `invoiced`
 * student fine waives through the Fees door as a credit note against the
 * fine's invoice line (`fee.credit_note.issued` - contra-revenue,
 * receivable relieved) so the single debt stream stays single.
 */
final class WaiveFine
{
    public function __construct(
        private readonly IssueCreditNote $creditNote,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     fine_id: int,
     *     reason: string,
     *     amount?: int|null,
     *     waived_on?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): LibraryFine
    {
        Gate::authorize(LibraryPermission::WAIVE_FINE);

        if (trim($data['reason']) === '') {
            throw new DomainException('A waiver requires a reason (§10.6).');
        }

        return DB::transaction(function () use ($data, $actor): LibraryFine {
            /** @var LibraryFine $fine */
            $fine = LibraryFine::query()->lockForUpdate()->findOrFail($data['fine_id']);

            // Segregation: the approver may not be the levier. The nightly
            // accrual has no levier until levy - then nobody is barred.
            if ($fine->levied_by !== null && $actor->id !== null && $fine->levied_by === $actor->id) {
                throw new DomainException(
                    'The waiver approver may not be the person who levied the fine (§10.6).'
                );
            }

            if (! in_array($fine->status, [FineStatus::Assessed, FineStatus::Invoiced], true)) {
                throw new DomainException(
                    "Fine {$fine->fine_no} is {$fine->status->value}; only assessed or invoiced fines waive."
                );
            }

            $remaining = $fine->amount - $fine->waived_amount;
            $waiveAmount = (int) ($data['amount'] ?? $remaining);

            if ($waiveAmount <= 0 || $waiveAmount > $remaining) {
                throw new DomainException(
                    "A waiver must be between 1 and the remaining {$remaining} FCFA of fine {$fine->fine_no}."
                );
            }

            $creditNoteId = null;

            if ($fine->status === FineStatus::Invoiced) {
                $creditNoteId = $this->creditInvoicedFine($fine, $waiveAmount, $data, $actor);
            }

            $newWaived = $fine->waived_amount + $waiveAmount;

            $fine->forceFill([
                'waived_amount' => $newWaived,
                'waived_by' => $actor->id,
                'waived_reason' => $data['reason'],
                'credit_note_id' => $creditNoteId ?? $fine->credit_note_id,
                'status' => $newWaived === $fine->amount ? FineStatus::Waived : $fine->status,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryFine::class,
                auditableId: (int) $fine->getKey(),
                after: [
                    'fine_no' => $fine->fine_no,
                    'waived_amount' => $waiveAmount,
                    'reason' => $data['reason'],
                    'credit_note_id' => $creditNoteId,
                ],
                actor: $actor,
            );

            return $fine->refresh();
        });
    }

    /**
     * §10.6: contra-revenue through the Fees door - the same income
     * account the levy credited, debited back by the credit-note rule.
     *
     * @param  array<string, mixed>  $data
     */
    private function creditInvoicedFine(LibraryFine $fine, int $waiveAmount, array $data, Actor $actor): int
    {
        if ($fine->invoice_id === null) {
            throw new DomainException("Fine {$fine->fine_no} is invoiced but carries no invoice; data is inconsistent.");
        }

        $lineId = DB::table('invoice_lines')
            ->where('invoice_id', $fine->invoice_id)
            ->orderBy('line_no')
            ->value('id');

        if ($lineId === null) {
            throw new DomainException("Invoice {$fine->invoice_id} has no lines to credit.");
        }

        $note = $this->creditNote->handle(
            invoiceId: $fine->invoice_id,
            lines: [['invoice_line_id' => (int) $lineId, 'amount' => $waiveAmount]],
            reasonType: CreditNoteReasonType::Goodwill,
            reasonNote: 'Library fine waiver: '.(string) $data['reason'],
            settlementMode: CreditNoteSettlementMode::ApplyToAccount,
            issueDate: (string) ($data['waived_on'] ?? Carbon::now()->toDateString()),
            actor: $actor,
            idempotencyKey: 'library-fine-waiver:'.$fine->fine_no,
        );

        return (int) $note->getKey();
    }
}
