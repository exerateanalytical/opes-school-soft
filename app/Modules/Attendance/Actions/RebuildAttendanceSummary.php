<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Actions\Concerns\AggregatesAttendance;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Attendance\Models\AttendanceSummary;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the persisted per-period rollups (07-students §9.8) touched by
 * one register: every assessment period whose date span covers the
 * register's date × every enrollment the register could have concerned.
 * Full recomputation keyed on UNIQUE(enrollment_id, assessment_period_id) —
 * idempotent, so the queued job may run twice and converge.
 *
 * Deliberately NOT Gate-checked: it runs from the queue on behalf of a
 * submit/amend/justify that carried its own authorization (the read-door
 * pattern of ResolveCalendarDay).
 */
final class RebuildAttendanceSummary
{
    use AggregatesAttendance;

    /** @return int summaries written */
    public function handle(int $registerId): int
    {
        $register = AttendanceRegister::query()->find($registerId);

        if ($register === null) {
            return 0;
        }

        $date = $register->date->toDateString();

        // assessment_periods is Academics-owned — DB::table only. A date can
        // sit inside several nested periods (year ⊃ term ⊃ sequence); each
        // gets its own row, because the report card reads whichever period
        // it prints.
        $periods = DB::table('assessment_periods')
            ->where('academic_year_id', $register->academic_year_id)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->get(['id', 'starts_on', 'ends_on']);

        if ($periods->isEmpty()) {
            return 0;
        }

        $enrollmentIds = $this->affectedEnrollmentIds($register, $date);

        if ($enrollmentIds === []) {
            return 0;
        }

        $written = 0;

        foreach ($periods as $period) {
            $stats = $this->aggregateAttendance(
                $register->academic_year_id,
                $enrollmentIds,
                (string) $period->starts_on,
                (string) $period->ends_on,
            );

            foreach ($stats as $enrollmentId => $stat) {
                AttendanceSummary::query()->updateOrCreate(
                    [
                        'enrollment_id' => $enrollmentId,
                        'assessment_period_id' => (int) $period->id,
                    ],
                    [...$stat, 'computed_at' => now()],
                );
                $written++;
            }
        }

        return $written;
    }

    /**
     * Everyone this register can move a number for: the reconstructed roster
     * at its date, plus anyone holding an exception row (covers a
     * roster-corrected amendment that removed someone).
     *
     * @return list<int>
     */
    private function affectedEnrollmentIds(AttendanceRegister $register, string $date): array
    {
        $rosterIds = DB::table('enrollment_segments as s')
            ->join('enrollments as e', 'e.id', '=', 's.enrollment_id')
            ->where('s.class_group_id', $register->class_group_id)
            ->whereDate('s.starts_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('s.ends_on')->orWhereDate('s.ends_on', '>=', $date);
            })
            ->whereDate('e.enrolled_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('e.left_on')->orWhereDate('e.left_on', '>=', $date);
            })
            ->whereNotIn('e.status', ['pending', 'cancelled'])
            ->pluck('s.enrollment_id');

        $recordIds = AttendanceRecord::query()
            ->where('attendance_register_id', $register->getKey())
            ->pluck('enrollment_id');

        return array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            [...$rosterIds->all(), ...$recordIds->all()],
        )));
    }
}
