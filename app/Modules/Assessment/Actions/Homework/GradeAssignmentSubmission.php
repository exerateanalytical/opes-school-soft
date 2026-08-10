<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions\Homework;

use App\Modules\Assessment\Models\Assignment;
use App\Modules\Assessment\Models\AssignmentSubmission;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * A teacher grades a submission.
 *
 * The score is bounded by the assignment's own `max_score` - a number with
 * no ceiling is not a grade, it is an arbitrary integer, and this is the
 * point of `assignments.max_score` existing as a column at all.
 */
final class GradeAssignmentSubmission
{
    public function handle(
        int $submissionId,
        float $score,
        int $gradedByUserId,
        ?string $feedback = null,
    ): AssignmentSubmission {
        Gate::authorize(Permission::MarksEnter->value);

        /** @var AssignmentSubmission $submission */
        $submission = AssignmentSubmission::query()->findOrFail($submissionId);

        /** @var Assignment $assignment */
        $assignment = Assignment::query()->findOrFail($submission->assignment_id);

        if ($assignment->max_score !== null && $score > (float) $assignment->max_score) {
            throw new DomainException(sprintf(
                'The score (%s) cannot exceed this assignment\'s maximum (%s).',
                $score,
                $assignment->max_score,
            ));
        }

        if ($score < 0) {
            throw new DomainException('The score cannot be negative.');
        }

        $submission->forceFill([
            'score' => $score,
            'feedback' => $feedback,
            'graded_by_user_id' => $gradedByUserId,
            'graded_at' => now(),
        ])->save();

        return $submission->refresh();
    }
}
