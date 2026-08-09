<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Livewire;

use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;

/**
 * Attendance Management — 09-ui §8.7: KPI row (Total · Present Today ·
 * Absent Today · Late Today · Rate this month), Attendance Overview donut,
 * today's registers, Class Calendar. Every figure renders "—" when no
 * register backs it — NEVER 0% (C5): "no fee collected" and "not recorded"
 * are different facts, and this screen exists because v1 confused them.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    public function mount(): void
    {
        Gate::authorize(Permission::AttendanceView->value);
    }

    private function currentYearId(): ?int
    {
        $id = DB::table('academic_years')->where('is_current', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Month-to-date §9.6 rate from the headers: (present + late) over
     * (expected − suspended-rows). NULL — not zero — when no register has
     * been taken this month.
     *
     * @param  array<int, int>  $registerIds
     */
    private function monthRate(array $registerIds): ?float
    {
        if ($registerIds === []) {
            return null;
        }

        $headers = DB::table('attendance_registers')
            ->whereIn('id', $registerIds)
            ->selectRaw(
                'SUM(expected_count) as expected, SUM(present_count) as present, SUM(late_count) as late'
            )
            ->first();

        $suspended = DB::table('attendance_records')
            ->whereIn('attendance_register_id', $registerIds)
            ->where('status', 'suspended')
            ->count();

        // MySQL SUM() returns strings — cast.
        $expected = (int) ($headers->expected ?? 0);
        $present = (int) ($headers->present ?? 0);
        $late = (int) ($headers->late ?? 0);

        $denominator = $expected - $suspended;

        if ($denominator <= 0) {
            return null;
        }

        return round(($present + $late) / $denominator, 4);
    }

    /**
     * @return list<stdClass>
     */
    private function calendarDays(?int $yearId, Carbon $today): array
    {
        if ($yearId === null) {
            return [];
        }

        /** @var list<stdClass> */
        return DB::table('school_calendar_days')
            ->where('academic_year_id', $yearId)
            ->whereBetween('date', [
                $today->copy()->startOfMonth()->toDateString(),
                $today->copy()->endOfMonth()->toDateString(),
            ])
            ->orderBy('date')
            ->orderBy('school_section_id')
            ->get(['date', 'day_type', 'school_section_id'])
            ->values()
            ->all();
    }

    public function render(): mixed
    {
        $yearId = $this->currentYearId();
        $today = now();
        $todayKey = $today->toDateString();

        $totalStudents = $yearId === null ? null : DB::table('enrollments')
            ->where('academic_year_id', $yearId)
            ->whereIn('status', ['active', 'suspended'])
            ->count();

        // Today's TAKEN registers drive the day KPIs; drafts do not count.
        $todayTaken = $yearId === null
            ? collect()
            : AttendanceRegister::query()
                ->where('academic_year_id', $yearId)
                ->whereDate('date', $todayKey)
                ->whereIn('status', ['submitted', 'amended'])
                ->get();

        $hasToday = $todayTaken->isNotEmpty();

        $presentToday = $hasToday
            ? (int) $todayTaken->sum('present_count') + (int) $todayTaken->sum('late_count')
            : null;
        $absentToday = $hasToday ? (int) $todayTaken->sum('absent_count') : null;
        $lateToday = $hasToday ? (int) $todayTaken->sum('late_count') : null;
        $excusedToday = $hasToday ? (int) $todayTaken->sum('excused_count') : null;

        $monthRegisterIds = $yearId === null ? [] : AttendanceRegister::query()
            ->where('academic_year_id', $yearId)
            ->whereBetween('date', [
                $today->copy()->startOfMonth()->toDateString(),
                $todayKey,
            ])
            ->whereIn('status', ['submitted', 'amended'])
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        $monthRate = $this->monthRate($monthRegisterIds);

        // Today's register list, with the class names read cross-module.
        $registerRows = $yearId === null ? [] : DB::table('attendance_registers as r')
            ->join('class_groups as cg', 'cg.id', '=', 'r.class_group_id')
            ->join('users as u', 'u.id', '=', 'r.taken_by')
            ->where('r.academic_year_id', $yearId)
            ->whereDate('r.date', $todayKey)
            ->orderBy('cg.name')
            ->get([
                'r.id', 'r.session', 'r.mode', 'r.status',
                'r.expected_count', 'r.present_count', 'r.absent_count',
                'r.late_count', 'r.excused_count',
                'cg.name as class_group_name', 'u.name as taken_by_name',
            ])
            ->values()
            ->all();

        return view('livewire.attendance.index', [
            'totalStudents' => $totalStudents,
            'presentToday' => $presentToday,
            'absentToday' => $absentToday,
            'lateToday' => $lateToday,
            'excusedToday' => $excusedToday,
            'monthRate' => $monthRate,
            'registerRows' => $registerRows,
            'calendarDays' => $this->calendarDays($yearId, $today),
            'today' => $today,
            'hasYear' => $yearId !== null,
        ]);
    }
}
