<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The ported v1 `SanctionLadder` (design doc "Discipline"): suggests an
 * escalation step from the student's prior-case count within a lookback
 * window. ADVISORY ONLY, NEVER AUTOMATIC — the design doc says so in bold,
 * because an automatic ladder turns a data-entry mistake into an expulsion.
 * ApplySanction shows the suggestion; a human picks the sanction.
 */
final class SanctionLadder
{
    /**
     * Rungs in escalation order. Detention stands in for the whole rank-1
     * family (consigne / community service are peer alternatives the form
     * offers alongside it).
     *
     * @var list<SanctionType>
     */
    private const RUNGS = [
        SanctionType::Warning,
        SanctionType::Detention,
        SanctionType::GuardianSummons,
        SanctionType::Suspension,
        SanctionType::Exclusion,
    ];

    /**
     * @param  int  $priorCaseCount  countable prior cases for the STUDENT
     *                               (cross-year — that is why DisciplineCase
     *                               keys student_id, C3) within the lookback
     *                               window, excluding dismissed and positive
     *                               entries.
     * @param  SanctionType|null  $categoryDefault  the category's suggested
     *                                              starting point; the ladder
     *                                              never suggests BELOW it.
     */
    public function suggest(int $priorCaseCount, ?SanctionType $categoryDefault = null): SanctionType
    {
        $floor = $categoryDefault?->ladderRank() ?? 0;

        // First offence starts at the category's default rung; each prior
        // case climbs one rung; the ladder tops out at exclusion.
        $rank = min(
            max($floor, 0) + max($priorCaseCount, 0),
            count(self::RUNGS) - 1,
        );

        foreach (self::RUNGS as $rung) {
            if ($rung->ladderRank() >= $rank) {
                return $rung;
            }
        }

        return SanctionType::Exclusion;
    }
}
