<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Stage 5 output for one (enrollment, period), together with the totals row the
 * card must print (docs/specs/01-assessment.md §10.1, §13.6) — because that row
 * is where a Cameroonian reader derives the moyenne by hand and checks the
 * school's arithmetic.
 *
 * `$rounded` is NULL when Σcoef = 0 (invariant §18.7). NULL is not zero: such a
 * student is excluded from ranking, from the ranking denominator and from every
 * class statistic, and prints *Non évalué*.
 */
final readonly class GeneralAverage
{
    /**
     * @param  Score|null  $raw  the DECIMAL(6,3) value before display rounding
     * @param  Score|null  $rounded  rounded half-up to score_precision, ONCE (§18.9).
     *                               Rank, band and pass all read THIS value
     * @param  int  $sumCoefficientHundredths  the Σcoef printed on the card, 18.00 ⇒ 1800
     * @param  int  $weightedTotalScaled  Σ(score_thousandths × coefficient_hundredths)
     * @param  list<int|string>  $contributingSubjectKeys
     */
    public function __construct(
        public ?Score $raw,
        public ?Score $rounded,
        public int $sumCoefficientHundredths,
        public int $weightedTotalScaled,
        public array $contributingSubjectKeys,
    ) {}

    public static function notAssessed(): self
    {
        return new self(null, null, 0, 0, []);
    }

    public function isNotAssessed(): bool
    {
        return $this->rounded === null;
    }

    /**
     * The Σ(M×Coef) cell of the totals row, e.g. 234.250 for §10.1's table.
     *
     * Display only. The average itself divides the exact integers above, so the
     * printed total can never introduce a rounding error into the moyenne.
     */
    public function weightedTotal(): Score
    {
        return Score::ofThousandths(Arithmetic::divideHalfUp($this->weightedTotalScaled, 100));
    }
}
