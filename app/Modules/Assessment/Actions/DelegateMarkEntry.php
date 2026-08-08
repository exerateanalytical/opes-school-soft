<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delegates marks entry for one allocation to another user - the second
 * assignment source Mark::mayEnter() resolves (docs/specs/01-assessment.md
 * 7.5). The spec's motivating case: a teacher who resigns in November would
 * otherwise make their subject permanently unenterable and the whole class
 * unpublishable.
 *
 * A delegation is audited and, per 7.5, printed in the period's publication
 * dossier - so the reason is mandatory here, not decorative.
 */
final class DelegateMarkEntry
{
    public const PERMISSION = Permission::AssessmentConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(
        int $subjectAllocationId,
        int $delegateUserId,
        string $reason,
        ?string $validFrom = null,
        ?string $validTo = null,
        ?int $classGroupId = null,
        ?int $assessmentPeriodId = null,
        ?Actor $actor = null,
    ): int {
        Gate::authorize(self::PERMISSION);

        if (trim($reason) === '') {
            throw new DomainException('A delegation requires a stated reason - it is printed in the publication dossier.');
        }

        if ($validFrom !== null && $validTo !== null && $validTo < $validFrom) {
            throw new DomainException('A delegation cannot end before it starts.');
        }

        $actor ??= Actor::system();

        return DB::transaction(function () use (
            $subjectAllocationId, $delegateUserId, $reason, $validFrom, $validTo,
            $classGroupId, $assessmentPeriodId, $actor
        ): int {
            if (! DB::table('subject_allocations')->where('id', $subjectAllocationId)->exists()) {
                throw new DomainException(sprintf('Subject allocation %d does not exist.', $subjectAllocationId));
            }

            if (! DB::table('users')->where('id', $delegateUserId)->where('status', 'active')->exists()) {
                throw new DomainException(sprintf('User %d does not exist or is not active.', $delegateUserId));
            }

            if ($actor->id === null) {
                throw new DomainException('A delegation must be granted by a real user, not the system.');
            }

            $id = (int) DB::table('mark_entry_delegations')->insertGetId([
                'subject_allocation_id' => $subjectAllocationId,
                'class_group_id' => $classGroupId,
                'assessment_period_id' => $assessmentPeriodId,
                'delegate_user_id' => $delegateUserId,
                'granted_by' => $actor->id,
                'reason' => $reason,
                'valid_from' => $validFrom ?? BusinessDate::today(),
                'valid_to' => $validTo,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assessment',
                after: [
                    'delegation_id' => $id,
                    'subject_allocation_id' => $subjectAllocationId,
                    'delegate_user_id' => $delegateUserId,
                    'reason' => $reason,
                    'valid_from' => $validFrom,
                    'valid_to' => $validTo,
                ],
                actor: $actor,
            );

            return $id;
        });
    }

    /** Ending a delegation is an end-dating, never a delete - the dossier cites it. */
    public function end(int $delegationId, Actor $actor): void
    {
        Gate::authorize(self::PERMISSION);

        DB::transaction(function () use ($delegationId, $actor): void {
            $updated = DB::table('mark_entry_delegations')
                ->where('id', $delegationId)
                ->update(['valid_to' => BusinessDate::today(), 'updated_at' => now()]);

            if ($updated === 0) {
                throw new DomainException(sprintf('Delegation %d does not exist.', $delegationId));
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                after: ['delegation_id' => $delegationId, 'ended_on' => BusinessDate::today()],
                actor: $actor,
            );
        });
    }
}
