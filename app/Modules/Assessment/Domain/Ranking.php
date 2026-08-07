<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * Stage 6 (docs/specs/01-assessment.md §10.4).
 *
 * ```
 * sort key = ROUNDED general_avg DESC
 * tie rule = COMPETITION RANKING (1, 2, 2, 4)
 * ```
 *
 * **Round first, then order.** Two students at raw 13.0138 and 13.0072 both
 * print 13.01 and must therefore share rank 4, with rank 5 skipped (T11). If
 * ordering read the raw value they would print the same number and be ranked
 * one above the other, and no parent holding the two cards could be told why.
 *
 * A NULL average is not last: it is excluded from the ordering AND from the
 * denominator (§10.2, C3). Callers pass NULL for NC students (§10.5) exactly as
 * they do for Σcoef = 0; the two reasons differ but the treatment is identical.
 */
final class Ranking
{
    /**
     * @param  array<int|string, Score|null>  $averages  keyed by the caller's student identifier
     */
    public static function rank(
        array $averages,
        int $precision = Rounding::DEFAULT_PRECISION,
    ): RankTable {
        $ranked = [];
        $unranked = [];

        foreach ($averages as $key => $average) {
            if ($average === null) {
                $unranked[] = new RankRow($key, null, null);

                continue;
            }

            $ranked[] = ['key' => $key, 'score' => Rounding::halfUp($average, $precision)];
        }

        // PHP's sort is stable since 8.0, so students tied on the rounded value
        // keep their input order — which keeps a re-render of the same snapshot
        // byte-identical rather than merely equivalent.
        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score']->thousandths() <=> $a['score']->thousandths(),
        );

        $rows = [];
        $currentRank = 0;
        $previous = null;

        foreach ($ranked as $index => $entry) {
            $score = $entry['score'];

            if ($previous === null || ! $score->equals($previous)) {
                // Competition ranking: the rank is the 1-based position, so the
                // ranks following a tie are skipped (1, 2, 2, 4).
                $currentRank = $index + 1;
            }

            $rows[] = new RankRow($entry['key'], $score, $currentRank);
            $previous = $score;
        }

        return new RankTable([...$rows, ...$unranked], count($ranked));
    }
}
