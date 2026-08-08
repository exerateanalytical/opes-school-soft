<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\Lettering;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/02-accounting.md §10.4, quoted exactly because the one-line
 * summary in §4.2's field table ("lettering_id | FK -> Lettering, ON
 * DELETE SET NULL (unlettering deletes the group)") is imprecise read on
 * its own:
 *
 *   "Unlettering is an explicit Action requiring accounting.unletter, a
 *    mandatory reason, recording unlettered_by/at. It sets lettering_id =
 *    NULL on member lines (ON DELETE SET NULL covers the delete path) and
 *    RETAINS the Lettering row as a historical record with status and its
 *    unlettering metadata - the row is never hard-deleted (§15)."
 *
 * So: this Action does NOT delete the `Lettering` row. It nulls the
 * member lines' `lettering_id` (the migration's `fk_jel_lettering ... ON
 * DELETE SET NULL` is the belt for the separate case where the ROW itself
 * is ever removed by some other path - never exercised by this Action) and
 * stamps `unlettered_by`/`unlettered_at`/`unletter_reason` on the row,
 * leaving its `status` (partial/full) exactly as it was at the moment of
 * unlettering, as history.
 *
 * Called directly by an operator, and internally by ReverseJournalEntry
 * (§9.2 step 11) with reason "reversal" when the entry being reversed had
 * lettered lines.
 */
final class UnletterGroup
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $letteringId, string $reason, Actor $actor): Lettering
    {
        // §11: gated on `ledger.post`, per this agent's brief - Permission.php
        // does not yet declare a dedicated `accounting.unletter` case that
        // §10.4's prose names.
        Gate::authorize(Permission::LedgerPost->value);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An unlettering reason is required.',
            ]);
        }

        return DB::transaction(function () use ($letteringId, $reason, $actor): Lettering {
            $lettering = Lettering::lockForLettering($letteringId);

            if ($lettering->unlettered_at !== null) {
                throw new DomainException('This lettering group has already been unlettered.');
            }

            $lineIds = JournalEntryLine::query()
                ->where('lettering_id', $lettering->getKey())
                ->pluck('id')
                ->all();

            JournalEntryLine::query()
                ->where('lettering_id', $lettering->getKey())
                ->update(['lettering_id' => null]);

            $lettering->forceFill([
                'unlettered_by' => $actor->id,
                'unlettered_at' => now(),
                'unletter_reason' => $reason,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: Lettering::class,
                auditableId: (int) $lettering->getKey(),
                after: [
                    'unlettered_by' => $actor->id,
                    'unletter_reason' => $reason,
                    'line_ids' => $lineIds,
                ],
                actor: $actor,
            );

            return $lettering->refresh();
        });
    }
}
