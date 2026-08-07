<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * One child period's contribution to its parent (docs/specs/01-assessment.md
 * §9.1).
 *
 * `AssessmentPeriod.weight` is DECIMAL(8,4) and ARBITRARY POSITIVE — it is only
 * ever meaningful relative to its siblings — so it crosses into the Domain as
 * integer TEN-THOUSANDTHS and is normalised by Σ over PARTICIPATING children.
 */
final readonly class PeriodContribution
{
    /**
     * @param  Score|null  $score  NULL ⇒ the child does not participate; it renormalises the
     *                             others rather than entering as a zero (§9.1: 13.50, not 6.75)
     * @param  int  $weightTenThousandths  weight 1.0000 ⇒ 10000
     * @param  bool  $countsTowardParent  false for a Bac blanc / GCE mock (§4.1, §16.5)
     */
    public function __construct(
        public int|string $key,
        public ?Score $score,
        public int $weightTenThousandths,
        public bool $countsTowardParent = true,
    ) {
        if ($weightTenThousandths <= 0) {
            throw AssessmentException::nonPositivePeriodWeight();
        }
    }

    public function participates(): bool
    {
        return $this->countsTowardParent && $this->score !== null;
    }
}
