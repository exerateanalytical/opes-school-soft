<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Actions\Concerns\AggregatesAttendance;

/**
 * READ DOOR (docs/plans/phase-08.md F2, signature fixed by the plan §3):
 * the promotion engine's attendance criterion. Computed live from the
 * register headers + exception rows for the whole academic year — the
 * §9.6 formula, one formula, stated once.
 *
 * NULL is the load-bearing value (C5): an enrollment with zero taken
 * registers has NO rate — not 0, not 100 — and §10 refuses to evaluate the
 * attendance criterion on it, routing the class to the coverage report
 * instead of silently passing everyone.
 *
 * Deliberately not Gate-checked: consumed inside Actions that carry their
 * own authorization (EvaluatePromotionRun), the ResolveCalendarDay pattern.
 */
final class GetAttendanceRateForEnrollments
{
    use AggregatesAttendance;

    /**
     * @param  array<int, int>  $enrollmentIds
     * @return array<int, float|null> enrollment id => rate in [0, 1], or
     *         NULL when sessions_expected − sessions_suspended = 0. Every
     *         requested id is present in the result.
     */
    public function handle(int $academicYearId, array $enrollmentIds): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (int|string $id): int => (int) $id,
            $enrollmentIds,
        )));

        $stats = $this->aggregateAttendance($academicYearId, $ids);

        $rates = [];

        foreach ($stats as $enrollmentId => $stat) {
            $denominator = $stat['sessions_expected'] - $stat['sessions_suspended'];

            $rates[$enrollmentId] = $denominator <= 0
                ? null
                : round($stat['sessions_present'] / $denominator, 4);
        }

        return $rates;
    }
}
