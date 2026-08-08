<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Domain;

/**
 * The ranked cohort for one (period, rank scope).
 *
 * `$denominator` counts RANKED students only — `Rang : 5ᵉ / 62` where 62 is the
 * number of students who actually have an average, not the class size
 * (docs/specs/01-assessment.md §10.2, §10.4).
 */
final readonly class RankTable
{
    /**
     * @param  list<RankRow>  $rows  ranked rows first, in rank order; then the unranked, in input order
     */
    public function __construct(
        public array $rows,
        public int $denominator,
    ) {}

    public function rankOf(int|string $key): ?int
    {
        foreach ($this->rows as $row) {
            if ($row->key === $key) {
                return $row->rank;
            }
        }

        return null;
    }

    /** @return list<RankRow> */
    public function rankedRows(): array
    {
        return array_values(array_filter($this->rows, static fn (RankRow $row): bool => $row->isRanked()));
    }
}
