<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

use App\Support\Score\Score;

/**
 * A composed term or annual value, plus the divisor that produced it.
 *
 * The count is not decoration: docs/specs/01-assessment.md §9.2 requires the
 * card to print `Moyenne annuelle (5 séq.)` when a sequence was missed, so the
 * reader can see which divisor the school used.
 */
final readonly class CompositionResult
{
    public function __construct(
        public ?Score $score,
        public int $participatingCount,
    ) {}

    public static function unassessed(): self
    {
        return new self(null, 0);
    }

    public function isUnassessed(): bool
    {
        return $this->score === null;
    }
}
