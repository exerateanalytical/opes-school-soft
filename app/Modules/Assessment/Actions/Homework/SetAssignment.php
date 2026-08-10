<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions\Homework;

use App\Modules\Assessment\Models\Assignment;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A teacher sets homework for a class group and subject.
 *
 * Gated on `marks.enter` - the same permission a teacher already holds to
 * grade marks for the classes assigned to them - rather than a new
 * permission, so the RBAC surface does not grow for a capability that maps
 * onto an existing one.
 *
 * Teaching assignment is checked against `subject_allocation_teachers`
 * (user-keyed), the same precedent `Attendance\Actions\OpenAttendanceRegister`
 * already established: a user who holds `marks.enter` through a leadership
 * role (Principal, Vice Principal) may set homework for any class, but an
 * ordinary teacher only for a class/subject they are actually allocated to.
 */
final class SetAssignment
{
    public function handle(
        int $classGroupId,
        int $subjectId,
        int $setByUserId,
        string $title,
        string $assignedOn,
        string $dueOn,
        ?string $instructions = null,
        ?float $maxScore = null,
    ): Assignment {
        Gate::authorize(Permission::MarksEnter->value);

        if ($dueOn < $assignedOn) {
            throw new DomainException('The due date cannot be before the date the assignment was set.');
        }

        $this->assertMayTeach($classGroupId, $subjectId, $setByUserId);

        return Assignment::query()->create([
            'class_group_id' => $classGroupId,
            'subject_id' => $subjectId,
            'set_by_user_id' => $setByUserId,
            'title' => $title,
            'instructions' => $instructions,
            'assigned_on' => $assignedOn,
            'due_on' => $dueOn,
            'max_score' => $maxScore,
            'is_published' => true,
        ]);
    }

    private function assertMayTeach(int $classGroupId, int $subjectId, int $userId): void
    {
        if (Gate::allows(Permission::AssessmentConfigure->value)) {
            return;
        }

        $group = DB::table('class_groups')->where('id', $classGroupId)->first();

        if ($group === null) {
            throw new DomainException("Class group {$classGroupId} does not exist.");
        }

        $streamIds = [0];

        if ($group->stream_id !== null) {
            $streamIds[] = (int) $group->stream_id;
        }

        $assigned = DB::table('subject_allocation_teachers as sat')
            ->join('subject_allocations as sa', 'sa.id', '=', 'sat.subject_allocation_id')
            ->where('sat.user_id', $userId)
            ->where('sa.subject_id', $subjectId)
            ->where('sa.academic_year_id', (int) $group->academic_year_id)
            ->where('sa.class_level_id', (int) $group->class_level_id)
            ->whereIn('sa.stream_id', $streamIds)
            ->where('sa.is_active', true)
            ->exists();

        if (! $assigned) {
            throw new DomainException(
                'You are not assigned to teach this subject for this class group.'
            );
        }
    }
}
