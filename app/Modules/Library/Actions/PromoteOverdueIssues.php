<?php

declare(strict_types=1);

namespace App\Modules\Library\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Library\Domain\IssueStatus;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Models\LibraryIssue;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §10.4 - the nightly `open → overdue` promotion.
 * Overdue is a PERSISTED state, which is what makes "87 Overdue Books" a
 * queryable fact and fine accrual deterministic. Idempotent: an already-
 * overdue issue is untouched.
 *
 * Runs unattended from the scheduler (Actor::system(), no signed-in
 * user), so the Gate is only consulted for a human actor.
 */
final class PromoteOverdueIssues
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /** @return int the number of issues promoted */
    public function handle(string $asOf, Actor $actor): int
    {
        if ($actor->id !== null) {
            Gate::authorize(LibraryPermission::CIRCULATE);
        }

        return DB::transaction(function () use ($asOf, $actor): int {
            /** @var list<int> $ids */
            $ids = LibraryIssue::query()
                ->where('status', IssueStatus::Open->value)
                ->where('due_on', '<', $asOf)
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            if ($ids === []) {
                return 0;
            }

            LibraryIssue::query()->whereIn('id', $ids)
                ->update(['status' => IssueStatus::Overdue->value]);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Library',
                auditableType: LibraryIssue::class,
                auditableId: null,
                after: ['promoted_to_overdue' => count($ids), 'as_of' => $asOf],
                actor: $actor,
            );

            return count($ids);
        });
    }
}
