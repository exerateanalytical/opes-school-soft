<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\TimesheetStatus;
use App\Modules\HR\Domain\WorkingTime;
use App\Modules\HR\Models\StaffContract;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Seeds `teaching_hours_logs` for a payroll month from the Academics
 * timetable (docs/specs/05-hr-payroll.md 5.5: hours_planned comes FROM the
 * timetable; it remains a PROPOSAL until validated).
 *
 * Timetable rows are Academics-owned and read via DB::table only (00-core
 * 6.2); the write lands in HR's own table. Idempotent through the
 * `uq_thl_segment` UNIQUE - re-seeding a month never duplicates and never
 * touches rows a validator already moved past `draft`.
 */
final class SeedTeachingHoursFromTimetable
{
    /**
     * @return int number of segment rows seeded
     */
    public function handle(string $payrollMonth, ?Actor $actor = null): int
    {
        Gate::authorize(HrPermission::TIMESHEET_VALIDATE);

        $monthStart = Carbon::parse($payrollMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        return DB::transaction(function () use ($monthStart, $monthEnd): int {
            $academicYearIds = DB::table('academic_years')
                ->where('starts_on', '<=', $monthEnd->toDateString())
                ->where('ends_on', '>=', $monthStart->toDateString())
                ->pluck('id');

            if ($academicYearIds->isEmpty()) {
                return 0;
            }

            $slots = DB::table('timetable_slots')
                ->join('timetable_periods', 'timetable_periods.id', '=', 'timetable_slots.timetable_period_id')
                ->whereIn('timetable_slots.academic_year_id', $academicYearIds)
                ->select([
                    'timetable_slots.id as slot_id',
                    'timetable_slots.staff_member_id',
                    'timetable_slots.class_group_id',
                    'timetable_slots.subject_id',
                    'timetable_slots.day_of_week',
                    'timetable_periods.duration_minutes',
                ])
                ->get();

            $seeded = 0;

            foreach ($slots as $slot) {
                // The staff member's hourly teaching contract in force at
                // month end; salaried teachers are paid by grade, not hours.
                /** @var StaffContract|null $contract */
                $contract = StaffContract::query()
                    ->where('staff_member_id', $slot->staff_member_id)
                    ->where('working_time', WorkingTime::Hourly->value)
                    ->inForceOn($monthEnd->toDateString())
                    ->orderByDesc('starts_on')
                    ->first();

                if ($contract === null) {
                    continue;
                }

                $occurrences = $this->weekdayOccurrences($monthStart, $monthEnd, (int) $slot->day_of_week);

                if ($occurrences === 0) {
                    continue;
                }

                $hoursPlanned = round($occurrences * ((int) $slot->duration_minutes) / 60, 2);

                $seeded += DB::table('teaching_hours_logs')->insertOrIgnore([
                    'staff_contract_id' => $contract->id,
                    'payroll_month' => $monthStart->toDateString(),
                    'class_group_id' => $slot->class_group_id,
                    'subject_id' => $slot->subject_id,
                    'timetable_slot_id' => $slot->slot_id,
                    'hours_planned' => number_format($hoursPlanned, 2, '.', ''),
                    'hours_taught' => '0.00',
                    'hours_validated' => null,
                    'status' => TimesheetStatus::Draft->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $seeded;
        });
    }

    /** How many times ISO weekday $dayOfWeek (1=Mon..7=Sun) falls in the month. */
    private function weekdayOccurrences(Carbon $monthStart, Carbon $monthEnd, int $dayOfWeek): int
    {
        $count = 0;
        $cursor = $monthStart->copy();

        while (! $cursor->gt($monthEnd)) {
            if ($cursor->isoWeekday() === $dayOfWeek) {
                $count++;
            }
            $cursor->addDay();
        }

        return $count;
    }
}
