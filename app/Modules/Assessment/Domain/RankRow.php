<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * One line of a rank cohort (docs/specs/01-assessment.md §10.4).
 *
 * `$rank` NULL means the student is NC / *Non évalué*: they still receive a
 * full report card with their marks, but no rank, and they are absent from the
 * denominator printed beside everyone else's.
 */
final readonly class RankRow
{
    public function __construct(
        public int|string $key,
        /** The ROUNDED average — the same number printed on the card (§18.9). */
        public ?Score $score,
        public ?int $rank,
    ) {}

    public function isRanked(): bool
    {
        return $this->rank !== null;
    }
}
