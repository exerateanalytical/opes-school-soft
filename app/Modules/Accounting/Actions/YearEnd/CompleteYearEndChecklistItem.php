<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §17.3 - the sign-off, and the two invariants
 * that make it mean something:
 *
 *  - **YE-3**: items complete in `sequence` order. A later item cannot be
 *    signed off while an earlier MANDATORY one is still pending. Signing
 *    off "trial balance validated" before the depreciation run has happened
 *    is not a checklist; it is a decoration.
 *  - **YE-4**: an automated item may not be ticked by a human at all. Its
 *    status is derived from the ledger by EvaluateYearEndChecklist. The
 *    human escape hatch for an automated item is a WAIVER, with a reason,
 *    on the record - never a tick.
 *
 * §17.8 segregation of duties: the user who RAN a step (`performed_by`,
 * stamped by the step Action) may not be the user who signs it off. That is
 * the maker-checker control the spec asks for, and it is the reason
 * `performed_by` is a separate column rather than a synonym for
 * `completed_by`.
 */
final class CompleteYearEndChecklistItem
{
    public const PERMISSION = Permission::LedgerConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $itemId, Actor $actor, ?string $evidenceType = null, ?int $evidenceId = null): YearEndChecklistItem
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($itemId, $actor, $evidenceType, $evidenceId): YearEndChecklistItem {
            /** @var YearEndChecklistItem $item */
            $item = YearEndChecklistItem::query()->whereKey($itemId)->lockForUpdate()->firstOrFail();

            if ($item->status === YearEndItemStatus::Completed) {
                throw new DomainException(sprintf('Step %d (%s) is already signed off.', $item->sequence, $item->title));
            }

            if ($item->status === YearEndItemStatus::Waived) {
                throw new DomainException(sprintf(
                    'Step %d (%s) was waived: "%s". A waiver is a recorded decision; it is not overwritten by a sign-off.',
                    $item->sequence,
                    $item->title,
                    (string) $item->waiver_reason,
                ));
            }

            if ($item->is_automated) {
                throw new DomainException(sprintf(
                    'Step %d (%s) completes itself when its validation returns clean (YE-4); it cannot be ticked by hand. Run the step, or waive it with a reason.',
                    $item->sequence,
                    $item->title,
                ));
            }

            // YE-3.
            /** @var YearEndChecklistItem|null $earlier */
            $earlier = YearEndChecklistItem::query()
                ->where('year_end_checklist_id', $item->year_end_checklist_id)
                ->where('sequence', '<', $item->sequence)
                ->where('is_mandatory', true)
                ->where('status', YearEndItemStatus::Pending->value)
                ->orderBy('sequence')
                ->first();

            if ($earlier !== null) {
                throw new DomainException(sprintf(
                    'Step %d (%s) cannot be completed while step %d (%s) is still pending. §17.2 is a sequence, and YE-3 enforces it.',
                    $item->sequence,
                    $item->title,
                    $earlier->sequence,
                    $earlier->title,
                ));
            }

            // §17.8 maker-checker.
            if ($item->performed_by !== null && $actor->id !== null && $item->performed_by === $actor->id) {
                throw new DomainException(sprintf(
                    'Step %d (%s) was performed by you. §17.8 requires a different user to sign it off.',
                    $item->sequence,
                    $item->title,
                ));
            }

            if ($actor->id === null) {
                throw new DomainException('A checklist sign-off must name a user; an unattended process cannot sign one.');
            }

            $item->forceFill([
                'status' => YearEndItemStatus::Completed->value,
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'evidence_type' => $evidenceType ?? $item->evidence_type,
                'evidence_id' => $evidenceType === null ? $item->evidence_id : $evidenceId,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: YearEndChecklistItem::class,
                auditableId: (int) $item->getKey(),
                after: ['status' => YearEndItemStatus::Completed->value, 'code' => $item->code],
                actor: $actor,
            );

            return $item;
        });
    }
}
