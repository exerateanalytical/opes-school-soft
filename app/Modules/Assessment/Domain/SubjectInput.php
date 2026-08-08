<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Stage 1 output: everything collected for one
 * (enrollment, subject_allocation, period) triple.
 *
 * `SubjectAllocation.coefficient` is DECIMAL(5,2), so it crosses into the
 * Domain as integer HUNDREDTHS. Coefficients only ever appear as both a
 * numerator factor and a denominator term, so the common factor of 100 cancels
 * and the average is identical to one computed from the decimal — with no
 * float in the middle.
 */
final readonly class SubjectInput
{
    /**
     * @param  int|string  $key  the caller's identifier for this allocation; echoed back on the result
     * @param  list<ComponentMark>  $components  every DECLARED component (`required_components`),
     *                                           including the pending ones — "no row" is unreachable (§6.2)
     * @param  int  $coefficientHundredths  4.00 ⇒ 400
     * @param  Score|null  $maxScoreOverride  SubjectAllocation.max_score_override (§6.3)
     * @param  bool  $countsTowardAverage  a subject reported but excluded from the moyenne (§5.1)
     */
    public function __construct(
        public int|string $key,
        public array $components,
        public int $coefficientHundredths,
        public ?Score $maxScoreOverride = null,
        public bool $countsTowardAverage = true,
    ) {
        if ($coefficientHundredths < 0) {
            throw AssessmentException::negativeCoefficient();
        }
    }
}
