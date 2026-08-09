<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineSanction;

/**
 * READ DOOR (docs/plans/phase-08.md F3, signature fixed by the plan §3):
 * the promotion engine's `discipline` criterion (07-students §10.4 —
 * "count/severity of DisciplineCase in the year filtered by enrollment_id")
 * and, via `sanctionCountsBetween`, the report card's consignes/exclusions
 * line (§9.7).
 *
 * Counting rules, stated once:
 * - `dismissed` cases do NOT count — the school found them baseless.
 * - `is_positive` entries do NOT count — a merit cannot cost a promotion.
 * - open, under_investigation and resolved cases ALL count: the criterion
 *   measures what happened during the year, not paperwork state.
 * - `max_severity` is the highest category severity among counted cases,
 *   0 when there are none (count 0 is its own honest signal — unlike
 *   attendance there is no C5 trap here, because "no casework" genuinely
 *   means a clean record).
 *
 * Deliberately not Gate-checked: consumed inside Actions that carry their
 * own authorization (EvaluatePromotionRun), the ResolveCalendarDay pattern.
 */
final class GetDisciplineCountsForEnrollments
{
    /**
     * @param  array<int, int>  $enrollmentIds
     * @return array<int, array{count: int, max_severity: int}> enrollment
     *         id => counts. Every requested id is present in the result;
     *         ids not enrolled in $academicYearId report {0, 0}.
     */
    public function handle(int $academicYearId, array $enrollmentIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = ['count' => 0, 'max_severity' => 0];
        }

        if ($ids === []) {
            return $result;
        }

        $rows = DisciplineCase::query()
            ->join(
                'discipline_categories',
                'discipline_categories.id', '=', 'discipline_cases.discipline_category_id'
            )
            // The year filter is a guard, not a redundancy: an enrollment id
            // belongs to exactly one year, and joining on it means a caller
            // passing last year's ids gets zeros, never cross-year counts.
            ->join('enrollments', 'enrollments.id', '=', 'discipline_cases.enrollment_id')
            ->where('enrollments.academic_year_id', $academicYearId)
            ->whereIn('discipline_cases.enrollment_id', $ids)
            ->where('discipline_cases.is_positive', false)
            ->where('discipline_cases.status', '!=', 'dismissed')
            ->groupBy('discipline_cases.enrollment_id')
            ->selectRaw(
                'discipline_cases.enrollment_id as enrollment_id, '
                .'COUNT(*) as case_count, '
                .'MAX(discipline_categories.severity) as max_severity'
            )
            ->get();

        foreach ($rows as $row) {
            // MySQL aggregates come back as strings — cast.
            $result[(int) $row->getAttribute('enrollment_id')] = [
                'count' => (int) $row->getAttribute('case_count'),
                'max_severity' => (int) $row->getAttribute('max_severity'),
            ];
        }

        return $result;
    }

    /**
     * §9.7's report-card line: `consignes` = sanctions of type detention or
     * consigne (the same punishment family under its two names), `exclusions`
     * = sanctions of type exclusion, counted by sanction start date within
     * the assessment period's window. Dismissed cases are excluded here too —
     * a sanction on a case later found baseless must not print.
     *
     * @param  array<int, int>  $enrollmentIds
     * @return array<int, array{consignes: int, exclusions: int}>
     */
    public function sanctionCountsBetween(array $enrollmentIds, string $fromDate, string $toDate): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        $result = [];

        foreach ($ids as $id) {
            $result[$id] = ['consignes' => 0, 'exclusions' => 0];
        }

        if ($ids === []) {
            return $result;
        }

        $rows = DisciplineSanction::query()
            ->join(
                'discipline_cases',
                'discipline_cases.id', '=', 'discipline_sanctions.discipline_case_id'
            )
            ->whereIn('discipline_cases.enrollment_id', $ids)
            ->where('discipline_cases.status', '!=', 'dismissed')
            ->whereIn('discipline_sanctions.type', ['detention', 'consigne', 'exclusion'])
            ->whereDate('discipline_sanctions.starts_on', '>=', $fromDate)
            ->whereDate('discipline_sanctions.starts_on', '<=', $toDate)
            ->groupBy('discipline_cases.enrollment_id', 'discipline_sanctions.type')
            ->selectRaw(
                'discipline_cases.enrollment_id as enrollment_id, '
                .'discipline_sanctions.type as sanction_type, '
                .'COUNT(*) as sanction_count'
            )
            ->get();

        foreach ($rows as $row) {
            $enrollmentId = (int) $row->getAttribute('enrollment_id');
            $count = (int) $row->getAttribute('sanction_count');

            $current = $result[$enrollmentId] ?? ['consignes' => 0, 'exclusions' => 0];

            if ((string) $row->getAttribute('sanction_type') === 'exclusion') {
                $current['exclusions'] += $count;
            } else {
                $current['consignes'] += $count;
            }

            $result[$enrollmentId] = $current;
        }

        return $result;
    }
}
