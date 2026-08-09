<?php

declare(strict_types=1);

namespace App\Modules\Students\Support;

use Illuminate\Support\Facades\DB;

/**
 * docs/specs/07-students.md §10.3 — the inputs hash.
 *
 * `inputs_hash = SHA-256` over a CANONICAL, ORDERED serialisation of, per
 * enrollment in the run: the enrollment id, the `version` of every Mark row
 * in scope, the annual average to 3 dp, PeriodPublication status +
 * snapshot_batch_id for every period in the year, AttendanceSummary
 * computed_at + the six counts for every period, the id and status of every
 * DisciplineCase in the year — plus the criteria set id and its version.
 *
 * Canonical means: fixed key order, enrollments sorted by id, every list
 * sorted by its natural key, values JSON-encoded the same way every time.
 * Evaluate stores the hash; apply recomputes it and REFUSES on mismatch,
 * naming the enrollments whose per-enrollment fingerprint changed (the
 * fingerprints ride in each decision's criteria_results at evaluate time).
 *
 * Marks, publications, summaries and discipline cases are other modules'
 * tables — read via DB::table only, never their Models
 * (tests/Architecture/ModuleBoundaryTest.php).
 */
final class PromotionInputsHasher
{
    /**
     * @param  array<int, int>  $enrollmentIds
     * @param  array<int, string|null>  $annualAverages  enrollment id => average to 3 dp (Assessment door output)
     * @return array{hash: string, fingerprints: array<int, string>}
     */
    public function handle(
        int $academicYearId,
        int $classGroupId,
        int $criteriaSetId,
        int $criteriaSetVersion,
        array $enrollmentIds,
        array $annualAverages,
    ): array {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        sort($ids);

        $periodIds = [];

        foreach (DB::table('assessment_periods')->where('academic_year_id', $academicYearId)->orderBy('id')->pluck('id') as $periodId) {
            $periodIds[] = (int) $periodId;
        }

        $context = [
            'criteria_set_id' => $criteriaSetId,
            'criteria_set_version' => $criteriaSetVersion,
            'publications' => $this->publications($periodIds, $classGroupId),
        ];

        $marks = $this->marks($ids, $periodIds);
        $summaries = $this->summaries($ids, $periodIds);
        $cases = $this->disciplineCases($ids);

        $fingerprints = [];
        $enrollmentPayloads = [];

        foreach ($ids as $enrollmentId) {
            $payload = [
                'enrollment_id' => $enrollmentId,
                'marks' => $marks[$enrollmentId] ?? [],
                'annual_average' => $annualAverages[$enrollmentId] ?? null,
                'attendance' => $summaries[$enrollmentId] ?? [],
                'discipline' => $cases[$enrollmentId] ?? [],
            ];

            $fingerprints[$enrollmentId] = hash('sha256', $this->canonical($payload));
            $enrollmentPayloads[] = $payload;
        }

        $hash = hash('sha256', $this->canonical([
            'context' => $context,
            'enrollments' => $enrollmentPayloads,
        ]));

        return ['hash' => $hash, 'fingerprints' => $fingerprints];
    }

    /**
     * @param  list<int>  $periodIds
     * @return list<array{period_id: int, status: string|null, snapshot_batch_id: string|null}>
     */
    private function publications(array $periodIds, int $classGroupId): array
    {
        if ($periodIds === []) {
            return [];
        }

        $rows = DB::table('period_publications')
            ->whereIn('assessment_period_id', $periodIds)
            ->where('class_group_id', $classGroupId)
            ->orderBy('assessment_period_id')
            ->orderBy('id')
            ->get(['assessment_period_id', 'status', 'snapshot_batch_id']);

        $publications = [];

        foreach ($rows as $row) {
            $publications[] = [
                'period_id' => (int) $row->assessment_period_id,
                'status' => $row->status === null ? null : (string) $row->status,
                'snapshot_batch_id' => $row->snapshot_batch_id === null
                    ? null
                    : (string) $row->snapshot_batch_id,
            ];
        }

        return $publications;
    }

    /**
     * §10.3 bullet 1: id => version of every Mark row in scope (00-core
     * §10.6's optimistic version column — an amendment bumps it, so an
     * amended mark drifts the hash without this class reading scores).
     *
     * @param  list<int>  $enrollmentIds
     * @param  list<int>  $periodIds
     * @return array<int, list<array{id: int, version: int}>>
     */
    private function marks(array $enrollmentIds, array $periodIds): array
    {
        if ($enrollmentIds === [] || $periodIds === []) {
            return [];
        }

        $rows = DB::table('marks')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('assessment_period_id', $periodIds)
            ->orderBy('id')
            ->get(['id', 'enrollment_id', 'version']);

        $marks = [];

        foreach ($rows as $row) {
            $marks[(int) $row->enrollment_id][] = [
                'id' => (int) $row->id,
                'version' => (int) $row->version,
            ];
        }

        return $marks;
    }

    /**
     * §10.3 bullet 4: computed_at and the six counts for every period.
     *
     * @param  list<int>  $enrollmentIds
     * @param  list<int>  $periodIds
     * @return array<int, list<array<string, int|string>>>
     */
    private function summaries(array $enrollmentIds, array $periodIds): array
    {
        if ($enrollmentIds === [] || $periodIds === []) {
            return [];
        }

        $rows = DB::table('attendance_summaries')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('assessment_period_id', $periodIds)
            ->orderBy('assessment_period_id')
            ->get([
                'enrollment_id',
                'assessment_period_id',
                'computed_at',
                'sessions_expected',
                'sessions_present',
                'sessions_absent',
                'sessions_excused',
                'sessions_late',
                'sessions_suspended',
            ]);

        $summaries = [];

        foreach ($rows as $row) {
            $summaries[(int) $row->enrollment_id][] = [
                'period_id' => (int) $row->assessment_period_id,
                'computed_at' => (string) $row->computed_at,
                'expected' => (int) $row->sessions_expected,
                'present' => (int) $row->sessions_present,
                'absent' => (int) $row->sessions_absent,
                'excused' => (int) $row->sessions_excused,
                'late' => (int) $row->sessions_late,
                'suspended' => (int) $row->sessions_suspended,
            ];
        }

        return $summaries;
    }

    /**
     * §10.3 bullet 5: the id and status of every DisciplineCase in the year —
     * keyed by enrollment_id, so a case opened, resolved or dismissed between
     * evaluate and apply drifts exactly the students it concerns.
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, list<array{id: int, status: string}>>
     */
    private function disciplineCases(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $rows = DB::table('discipline_cases')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->orderBy('id')
            ->get(['id', 'enrollment_id', 'status']);

        $cases = [];

        foreach ($rows as $row) {
            $cases[(int) $row->enrollment_id][] = [
                'id' => (int) $row->id,
                'status' => (string) $row->status,
            ];
        }

        return $cases;
    }

    /**
     * One JSON dialect for every hash: no escaped slashes, no unicode
     * escapes, keys in the fixed order the arrays above build them.
     *
     * @param  array<array-key, mixed>  $payload
     */
    private function canonical(array $payload): string
    {
        return (string) json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
