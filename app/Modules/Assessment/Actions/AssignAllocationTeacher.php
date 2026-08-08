<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records who teaches a subject allocation - the first of the two assignment
 * sources Mark::mayEnter() resolves (docs/specs/01-assessment.md 7.5). Without
 * a row here (or an active delegation), a Teacher holding `marks.enter` is
 * still denied entry to this allocation: the permission is the outer gate,
 * the assignment is the scope.
 *
 * Cross-module note: subject_allocations belongs to Academics and users to
 * Identity, so both sides are validated through the query builder - never an
 * imported Model (tests/Architecture/ModuleBoundaryTest.php is absolute).
 */
final class AssignAllocationTeacher
{
    public const PERMISSION = Permission::AssessmentConfigure->value;

    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    public function handle(int $subjectAllocationId, int $userId, Actor $actor): void
    {
        Gate::authorize(self::PERMISSION);

        DB::transaction(function () use ($subjectAllocationId, $userId, $actor): void {
            $allocationExists = DB::table('subject_allocations')->where('id', $subjectAllocationId)->exists();

            if (! $allocationExists) {
                throw new DomainException(sprintf('Subject allocation %d does not exist.', $subjectAllocationId));
            }

            $userExists = DB::table('users')->where('id', $userId)->where('status', 'active')->exists();

            if (! $userExists) {
                throw new DomainException(sprintf('User %d does not exist or is not active.', $userId));
            }

            $alreadyAssigned = DB::table('subject_allocation_teachers')
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('user_id', $userId)
                ->exists();

            if ($alreadyAssigned) {
                return; // Idempotent: assigning the same teacher twice is a no-op, not an error.
            }

            DB::table('subject_allocation_teachers')->insert([
                'subject_allocation_id' => $subjectAllocationId,
                'user_id' => $userId,
                'assigned_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assessment',
                after: ['subject_allocation_id' => $subjectAllocationId, 'teacher_user_id' => $userId],
                actor: $actor,
            );
        });
    }

    public function revoke(int $subjectAllocationId, int $userId, Actor $actor): void
    {
        Gate::authorize(self::PERMISSION);

        DB::transaction(function () use ($subjectAllocationId, $userId, $actor): void {
            $deleted = DB::table('subject_allocation_teachers')
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('user_id', $userId)
                ->delete();

            if ($deleted === 0) {
                throw new DomainException('No such teacher assignment to revoke.');
            }

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Assessment',
                before: ['subject_allocation_id' => $subjectAllocationId, 'teacher_user_id' => $userId],
                actor: $actor,
            );
        });
    }
}
