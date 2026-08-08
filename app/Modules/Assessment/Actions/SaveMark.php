<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Models\Mark;
use DomainException;

/**
 * One cell of the entry grid (docs/specs/01-assessment.md §17).
 *
 * Deliberately a thin delegate to SaveMarkBatch rather than a second
 * implementation: the range check, the entry window, the optimistic lock and
 * the audit entry must behave identically whether a teacher saves one mark or
 * sixty-two, and two code paths would eventually disagree about exactly the
 * rules that are expensive to get wrong.
 */
final class SaveMark
{
    public function __construct(private readonly SaveMarkBatch $batch)
    {
    }

    /**
     * @param  string|null  $score  the mark as a decimal string — never a float
     *                              (00-core §7.1); NULL for every state but `scored`
     * @return array{mark_id: int, expected_version: int, current_version: int, their_score: string|null, their_state: string, their_actor_id: int|null, their_actor_name: string, changed_at: string|null, message: string}|null
     *                                                                                                                                                                                                                          the conflict, or NULL when the save succeeded
     */
    public function handle(
        Mark $mark,
        MarkState $state,
        ?string $score = null,
        ?string $comment = null,
        ?int $expectedVersion = null,
    ): ?array {
        $result = $this->batch->handle(
            subjectAllocationId: $mark->subject_allocation_id,
            assessmentPeriodId: $mark->assessment_period_id,
            rows: [[
                'mark_id' => (int) $mark->getKey(),
                'version' => $expectedVersion ?? $mark->version,
                'state' => $state->value,
                'score' => $score,
                'comment' => $comment,
            ]],
        );

        if ($result['conflicts'] !== []) {
            return $result['conflicts'][0];
        }

        if ($result['saved'] === []) {
            throw new DomainException('The mark was neither saved nor reported as a conflict.');
        }

        return null;
    }
}
