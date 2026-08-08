<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Coefficient-weighted GPA over BANDED grade points
 * (docs/specs/01-assessment.md §11).
 *
 * ```
 * GPA = Σ( grade_point(subject_score) × coefficient ) / Σ coefficient
 * ```
 *
 * The band lookup itself needs `GradeBand` rows, so it stays outside the
 * Domain; this class takes the points already resolved. It is deliberately
 * coarser than the general average and the two do not track linearly — both are
 * printed and neither is derived from the other.
 *
 * `GradeBand.grade_point` is nullable. If ANY banded subject in scope has a
 * NULL point the GPA is NULL, rather than silently computed over a subset that
 * would flatter or punish the student depending on which band was misconfigured.
 */
final class Gpa
{
    /**
     * @param  list<array{0: Score|null, 1: int}>  $pointsByCoefficient
     *                                                                   [grade_point, coefficient_hundredths] pairs, grade_point as a Score
     *                                                                   (DECIMAL(4,2) fits thousandths exactly, e.g. 3.00 ⇒ Score::of('3.00'))
     */
    public static function compute(
        array $pointsByCoefficient,
        int $roundingDp = Rounding::DEFAULT_PRECISION,
    ): ?Score {
        $weighted = [];

        foreach ($pointsByCoefficient as [$point, $coefficientHundredths]) {
            if ($point === null) {
                return null;
            }

            if ($coefficientHundredths <= 0) {
                continue;
            }

            $weighted[] = [$point, $coefficientHundredths];
        }

        $raw = Score::weightedAverage($weighted);

        return $raw === null ? null : Rounding::halfUp($raw, $roundingDp);
    }
}
