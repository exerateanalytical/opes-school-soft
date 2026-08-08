<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Stage 4 output for one (enrollment, subject_allocation, period).
 *
 * `$score` NULL means UNASSESSED, never zero: the subject leaves the numerator
 * AND its coefficient leaves the denominator, and it prints `n/e` or `Disp.`
 * (docs/specs/01-assessment.md §6.4 case 3, §10.2).
 */
final readonly class SubjectResult
{
    /**
     * @param  Score|null  $score  framework-scaled subject score, DECIMAL(6,3) precision
     * @param  list<ComponentOutcome>  $componentOutcomes
     * @param  list<string>  $blockingComponentCodes  pending components under `block_publication`
     */
    public function __construct(
        public int|string $key,
        public ?Score $score,
        public int $coefficientHundredths,
        public bool $countsTowardAverage,
        public array $componentOutcomes,
        public array $blockingComponentCodes = [],
    ) {}

    /** Pending components met `block_publication`; publication must refuse (§6.4, §13.2). */
    public function isBlocked(): bool
    {
        return $this->blockingComponentCodes !== [];
    }

    /** Every surviving component weight was removed: the subject has no score at all (§6.4 case 3). */
    public function isUnassessed(): bool
    {
        return $this->score === null && ! $this->isBlocked();
    }

    /** Only these subjects enter Σ(M×Coef) and Σcoef at stage 5 (§10.1). */
    public function contributesToAverage(): bool
    {
        return $this->score !== null && $this->countsTowardAverage;
    }
}
