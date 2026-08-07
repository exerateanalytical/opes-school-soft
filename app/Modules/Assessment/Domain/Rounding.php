<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Rounding half-up to `framework.score_precision`, applied exactly ONCE at the
 * end of aggregation (docs/specs/01-assessment.md §10.1, invariant §18.9).
 *
 * Rank and grade band then read the ROUNDED value, so the number printed on the
 * bulletin always explains the rank and the mention printed beside it. This is
 * a correctness rule, not a display rule: two students printing 13.01 must not
 * diverge on decimals the parent cannot see (§10.4, T11).
 *
 * The 3-dp intermediate is not a second rounding — `Mark.score` and every
 * derived average are DECIMAL(6,3) columns, so thousandths ARE the stored raw
 * value that §10.1 says to round from.
 */
final class Rounding
{
    public const DEFAULT_PRECISION = 2;

    public static function halfUp(Score $score, int $precision = self::DEFAULT_PRECISION): Score
    {
        if ($precision < 0 || $precision > 3) {
            throw AssessmentException::unsupportedPrecision($precision);
        }

        $factor = 10 ** (3 - $precision);

        if ($factor === 1) {
            return $score;
        }

        return Score::ofThousandths(
            Arithmetic::divideHalfUp($score->thousandths(), $factor) * $factor,
        );
    }

    public static function halfUpOrNull(?Score $score, int $precision = self::DEFAULT_PRECISION): ?Score
    {
        return $score === null ? null : self::halfUp($score, $precision);
    }
}
