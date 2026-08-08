<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * One collected Mark row, flattened to exactly what stages 2–3 need
 * (docs/specs/01-assessment.md §6.1, §6.4). A plain value object: the caller
 * maps its Eloquent row onto this and the Domain never sees a Model.
 */
final readonly class ComponentMark
{
    /**
     * @param  string  $componentCode  AssessmentComponent.code — `CA`, `EXAM`, `TP`, `ORAL`
     * @param  Score|null  $score  NULL unless $state is Scored (§18.4)
     * @param  Score|null  $componentMax  AssessmentComponent.max_score; NULL falls through to framework max
     * @param  int  $weight  ComponentWeight.weight — integer percentage points, Σ = 100 (§18.1)
     * @param  bool  $hasJustifiedAbsenceOnAssessmentDate  the ONLY input that lets
     *                                                     `redistribute` treat a pending component as exempt (§6.4)
     */
    public function __construct(
        public string $componentCode,
        public MarkState $state,
        public ?Score $score,
        public ?Score $componentMax,
        public int $weight,
        public bool $hasJustifiedAbsenceOnAssessmentDate = false,
    ) {
        if ($weight < 0) {
            throw AssessmentException::negativeComponentWeight($componentCode);
        }

        if ($state === MarkState::Scored && $score === null) {
            throw AssessmentException::scoredMarkNeedsScore($componentCode);
        }

        if ($state !== MarkState::Scored && $score !== null) {
            throw AssessmentException::unscoredMarkCarriesScore($componentCode);
        }
    }

    public static function scored(string $componentCode, Score $score, ?Score $componentMax, int $weight): self
    {
        return new self($componentCode, MarkState::Scored, $score, $componentMax, $weight);
    }

    public static function inState(
        string $componentCode,
        MarkState $state,
        ?Score $componentMax,
        int $weight,
        bool $hasJustifiedAbsenceOnAssessmentDate = false,
    ): self {
        return new self($componentCode, $state, null, $componentMax, $weight, $hasJustifiedAbsenceOnAssessmentDate);
    }
}
