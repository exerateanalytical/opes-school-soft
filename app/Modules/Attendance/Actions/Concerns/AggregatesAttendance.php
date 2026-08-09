<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The §9.6 aggregation, computed once and shared by the summary rebuild and
 * the promotion-facing rate door.
 *
 * "The enrollment was on r's roster" is reconstructed from the segment
 * history rather than persisted per row: exception-only storage (§9.4) means
 * present students have no row to carry membership, and segments are
 * immutable contiguous ranges (§5.2), so re-resolving them for r.date yields
 * the roster as it stood at open. The reconstruction predicate uses the
 * enrollment's TEMPORAL columns (enrolled_on/left_on) rather than §9.5's
 * live-status filter, so a student who later withdrew keeps the sessions
 * they genuinely owed while enrolled; never-live enrollments
 * (pending/cancelled) stay out.
 */
trait AggregatesAttendance
{
    /**
     * §9.6 for a set of enrollments over one register scope (an academic
     * year, optionally narrowed to a date range).
     *
     * @param  list<int>  $enrollmentIds
     * @return array<int, array{
     *     sessions_expected: int,
     *     sessions_present: int,
     *     sessions_absent: int,
     *     sessions_excused: int,
     *     sessions_late: int,
     *     sessions_suspended: int,
     *     hours_absent_justified: float,
     *     hours_absent_unjustified: float,
     *     retards: int,
     * }> keyed by enrollment id — EVERY requested id has an entry, all-zero
     *    when no register covers it (the caller decides that means NULL).
     */
    private function aggregateAttendance(
        int $academicYearId,
        array $enrollmentIds,
        ?string $from = null,
        ?string $to = null,
    ): array {
        $stats = [];

        foreach ($enrollmentIds as $enrollmentId) {
            $stats[$enrollmentId] = [
                'sessions_expected' => 0,
                'sessions_present' => 0,
                'sessions_absent' => 0,
                'sessions_excused' => 0,
                'sessions_late' => 0,
                'sessions_suspended' => 0,
                'hours_absent_justified' => 0.0,
                'hours_absent_unjustified' => 0.0,
                'retards' => 0,
            ];
        }

        if ($enrollmentIds === []) {
            return $stats;
        }

        // sessions_expected: |{ registers whose roster held the enrollment }|.
        $expectedRows = $this->takenRegisters($academicYearId, $from, $to)
            ->join('enrollment_segments as s', function ($join): void {
                $join->on('s.class_group_id', '=', 'r.class_group_id')
                    ->whereColumn('s.starts_on', '<=', 'r.date')
                    ->where(function (Builder $query): void {
                        $query->whereNull('s.ends_on')
                            ->orWhereColumn('s.ends_on', '>=', 'r.date');
                    });
            })
            ->join('enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->whereColumn('e.enrolled_on', '<=', 'r.date')
            ->where(function (Builder $query): void {
                $query->whereNull('e.left_on')
                    ->orWhereColumn('e.left_on', '>=', 'r.date');
            })
            ->whereNotIn('e.status', ['pending', 'cancelled'])
            ->whereIn('s.enrollment_id', $enrollmentIds)
            ->groupBy('s.enrollment_id')
            ->selectRaw('s.enrollment_id, COUNT(*) as expected')
            ->get();

        foreach ($expectedRows as $row) {
            $id = (int) $row->enrollment_id;
            $entry = $stats[$id] ?? null;

            if ($entry === null) {
                continue;
            }

            $entry['sessions_expected'] = (int) $row->expected;
            $stats[$id] = $entry;
        }

        // The exception rows, counted per status.
        $recordRows = $this->takenRegisters($academicYearId, $from, $to)
            ->join('attendance_records as rec', 'rec.attendance_register_id', '=', 'r.id')
            ->whereIn('rec.enrollment_id', $enrollmentIds)
            ->groupBy('rec.enrollment_id', 'rec.status')
            ->selectRaw('rec.enrollment_id, rec.status, COUNT(*) as c')
            ->get();

        foreach ($recordRows as $row) {
            $id = (int) $row->enrollment_id;
            $count = (int) $row->c;
            $entry = $stats[$id] ?? null;

            if ($entry === null) {
                continue;
            }

            match ((string) $row->status) {
                'absent', 'sick' => $entry['sessions_absent'] += $count,
                'excused' => $entry['sessions_excused'] += $count,
                'late' => $entry['sessions_late'] += $count,
                'suspended' => $entry['sessions_suspended'] += $count,
                default => null, // present rows are never stored (§9.4).
            };

            $stats[$id] = $entry;
        }

        // Heures d'absence (§9.7): per-lesson registers only, split by
        // is_justified. MySQL SUM() returns a string — cast.
        $hourRows = $this->takenRegisters($academicYearId, $from, $to)
            ->join('attendance_records as rec', 'rec.attendance_register_id', '=', 'r.id')
            ->where('r.mode', 'per_lesson')
            ->whereNotNull('r.lesson_duration_minutes')
            ->whereIn('rec.status', ['absent', 'sick', 'excused'])
            ->whereIn('rec.enrollment_id', $enrollmentIds)
            ->groupBy('rec.enrollment_id')
            ->selectRaw(
                'rec.enrollment_id, '
                .'SUM(CASE WHEN rec.is_justified = 1 THEN r.lesson_duration_minutes ELSE 0 END) as justified_minutes, '
                .'SUM(CASE WHEN rec.is_justified = 0 THEN r.lesson_duration_minutes ELSE 0 END) as unjustified_minutes'
            )
            ->get();

        foreach ($hourRows as $row) {
            $id = (int) $row->enrollment_id;
            $entry = $stats[$id] ?? null;

            if ($entry === null) {
                continue;
            }

            $entry['hours_absent_justified'] = round(((int) $row->justified_minutes) / 60, 2);
            $entry['hours_absent_unjustified'] = round(((int) $row->unjustified_minutes) / 60, 2);
            $stats[$id] = $entry;
        }

        foreach ($stats as $id => $entry) {
            // §9.6: present = expected − absent − excused − suspended.
            // `late` is NOT subtracted — late is present.
            $entry['sessions_present'] = max(0, $entry['sessions_expected']
                - $entry['sessions_absent']
                - $entry['sessions_excused']
                - $entry['sessions_suspended']);
            $entry['retards'] = $entry['sessions_late'];
            $stats[$id] = $entry;
        }

        return $stats;
    }

    /**
     * Registers that count in a denominator: actually TAKEN (submitted or
     * amended), never open drafts — a draft is not a taken register and must
     * not create expectation (§9.6).
     */
    private function takenRegisters(int $academicYearId, ?string $from, ?string $to): Builder
    {
        $query = DB::table('attendance_registers as r')
            ->where('r.academic_year_id', $academicYearId)
            ->whereIn('r.status', ['submitted', 'amended']);

        if ($from !== null) {
            $query->whereDate('r.date', '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate('r.date', '<=', $to);
        }

        return $query;
    }
}
