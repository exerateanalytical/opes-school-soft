<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions\Homework;

use App\Modules\Assessment\Models\Assignment;
use App\Modules\Assessment\Models\AssignmentSubmission;
use DomainException;

/**
 * A student (via their guardian, or staff on their behalf) submits an
 * assignment.
 *
 * No permission gate: submitting your own child's homework is not a
 * privileged act, and the guardian portal's own scope check (does this
 * guardian's link cover this student) is what actually controls who may
 * call this - the same screen-vs-write split the rest of the product uses,
 * just with the gate living in the caller rather than an RBAC permission.
 *
 * Upserts on `UNIQUE(assignment_id, enrollment_id)`: a resubmission updates
 * the existing row so a teacher grading always sees the CURRENT attempt,
 * never a stale earlier one sitting alongside it.
 */
final class SubmitAssignment
{
    public function handle(int $assignmentId, int $enrollmentId, string $note): AssignmentSubmission
    {
        /** @var Assignment $assignment */
        $assignment = Assignment::query()->findOrFail($assignmentId);

        $now = now();
        $isLate = $now->toDateString() > $assignment->due_on->toDateString();

        // A graded submission is a teacher's decision on the record; a
        // resubmission after grading must not silently erase it.
        $existing = AssignmentSubmission::query()
            ->where('assignment_id', $assignmentId)
            ->where('enrollment_id', $enrollmentId)
            ->first();

        if ($existing !== null && $existing->graded_at !== null) {
            throw new DomainException(
                'This assignment has already been graded; contact the teacher for a resubmission.'
            );
        }

        return AssignmentSubmission::query()->updateOrCreate(
            ['assignment_id' => $assignmentId, 'enrollment_id' => $enrollmentId],
            [
                'submission_note' => $note,
                'submitted_at' => $now,
                'is_late' => $isLate,
            ],
        );
    }
}
