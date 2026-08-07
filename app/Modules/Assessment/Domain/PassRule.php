<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Rate\Rate;
use App\Support\Score\Score;

/**
 * The single source of "pass" (docs/specs/01-assessment.md §10.3, invariant
 * §18.10).
 *
 * **`score >= framework.pass_score`. One expression, one place.**
 *
 * `GradeBand.is_pass` is display metadata and is never consulted by the
 * pass-rate statistic. v1 had a band flag, a hard-coded 10 and a percentage
 * threshold in the promotion code — three sources that disagreed exactly at the
 * boundary, where it matters most. T23 forbids a pass-threshold literal
 * anywhere outside this class; there is deliberately no default here to import,
 * because `pass_score` is per-framework configuration and never a constant.
 *
 * The comparison is made on the value ROUNDED to `score_precision`, so a
 * student printing exactly the pass mark passes (§10.1: rank and band read the
 * rounded value, and pass must agree with what the card shows).
 */
final class PassRule
{
    public static function passes(
        Score $score,
        Score $passScore,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): bool {
        return Rounding::halfUp($score, $precision)->thousandths()
            >= Rounding::halfUp($passScore, $precision)->thousandths();
    }

    /**
     * A NULL average is NOT a fail — it is "not assessed" (§10.2). It is
     * excluded from the pass count and from the pass-rate denominator alike.
     */
    public static function passesOrNull(
        ?Score $score,
        Score $passScore,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): bool {
        return $score !== null && self::passes($score, $passScore, $precision);
    }

    /**
     * @param  list<Score|null>  $scores
     */
    public static function countPassing(
        array $scores,
        Score $passScore,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): int {
        $count = 0;

        foreach ($scores as $score) {
            if (self::passesOrNull($score, $passScore, $precision)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * `pass_count / n` over non-NULL averages only (§10.7). NULL when n = 0 —
     * a cohort nobody assessed has no pass rate, and 0 % would be a lie.
     *
     * @param  list<Score|null>  $scores
     */
    public static function passRate(
        array $scores,
        Score $passScore,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): ?Rate {
        $assessed = 0;

        foreach ($scores as $score) {
            if ($score !== null) {
                $assessed++;
            }
        }

        if ($assessed === 0) {
            return null;
        }

        $passing = self::countPassing($scores, $passScore, $precision);

        return Rate::ofBasisPoints(
            Arithmetic::divideHalfUp(Arithmetic::multiply($passing, Rate::SCALE), $assessed),
        );
    }
}
