<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Livewire;

use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Register coverage — 07-students §9.6: "registers taken ÷ teaching days,
 * per class group per period, a FIRST-CLASS screen, not a diagnostic",
 * precisely because a class whose teacher takes no registers is the C5
 * failure mode. This is where "0 of 62 registers taken in Form 3C" surfaces
 * as a supervision problem in the principal's office instead of as a silent
 * 100% attendance pass.
 */
#[Layout('layouts.app')]
final class CoverageReport extends Component
{
    #[Url]
    public string $periodId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AttendanceView->value);
    }

    /**
     * Teaching days per section for a period: calendar rows resolved
     * section-specific over the all-sections sentinel, counted where the
     * day type admits a register (teaching/exam — §9.3).
     *
     * @return array<int, int> school_section_id => teaching-day count
     *         (key 0 = the all-sections resolution)
     */
    private function teachingDaysBySection(int $yearId, string $from, string $to): array
    {
        $rows = DB::table('school_calendar_days')
            ->where('academic_year_id', $yearId)
            ->whereBetween('date', [$from, $to])
            ->get(['date', 'day_type', 'school_section_id']);

        /** @var array<string, array<int, string>> $byDate date => section => type */
        $byDate = [];
        $sectionIds = [0];

        foreach ($rows as $row) {
            $sectionId = (int) $row->school_section_id;
            $byDate[(string) $row->date][$sectionId] = (string) $row->day_type;

            if (! in_array($sectionId, $sectionIds, true)) {
                $sectionIds[] = $sectionId;
            }
        }

        $counts = [];

        foreach ($sectionIds as $sectionId) {
            $count = 0;

            foreach ($byDate as $types) {
                // Section-specific shadows the 0 sentinel (§9.2).
                $type = $types[$sectionId] ?? $types[0] ?? null;

                if ($type === 'teaching' || $type === 'exam') {
                    $count++;
                }
            }

            $counts[$sectionId] = $count;
        }

        return $counts;
    }

    public function render(): mixed
    {
        $yearId = DB::table('academic_years')->where('is_current', true)->value('id');
        $yearId = $yearId === null ? null : (int) $yearId;

        $periods = $yearId === null ? collect() : DB::table('assessment_periods')
            ->where('academic_year_id', $yearId)
            ->orderBy('starts_on')
            ->orderBy('order_index')
            ->get(['id', 'name', 'starts_on', 'ends_on']);

        $period = null;

        if ($periods->isNotEmpty()) {
            $period = $this->periodId === ''
                ? $periods->first()
                : ($periods->firstWhere('id', (int) $this->periodId) ?? $periods->first());
        }

        $rows = [];

        if ($yearId !== null && $period !== null) {
            $from = (string) $period->starts_on;
            $to = (string) $period->ends_on;

            $teachingDays = $this->teachingDaysBySection($yearId, $from, $to);

            $groups = DB::table('class_groups as cg')
                ->join('class_levels as cl', 'cl.id', '=', 'cg.class_level_id')
                ->where('cg.academic_year_id', $yearId)
                ->orderBy('cg.name')
                ->get(['cg.id', 'cg.name', 'cg.attendance_mode', 'cl.school_section_id']);

            // Distinct DATES with a taken register: in per-lesson mode many
            // registers share a date, and coverage measures days covered,
            // not lessons logged.
            $takenByGroup = DB::table('attendance_registers')
                ->where('academic_year_id', $yearId)
                ->whereBetween('date', [$from, $to])
                ->whereIn('status', ['submitted', 'amended'])
                ->groupBy('class_group_id')
                ->selectRaw('class_group_id, COUNT(DISTINCT date) as days_taken')
                ->pluck('days_taken', 'class_group_id');

            foreach ($groups as $group) {
                $sectionId = (int) $group->school_section_id;
                $expectedDays = $teachingDays[$sectionId] ?? $teachingDays[0] ?? 0;
                $taken = (int) ($takenByGroup[$group->id] ?? 0);

                $rows[] = [
                    'class_group_id' => (int) $group->id,
                    'name' => (string) $group->name,
                    'mode' => (string) $group->attendance_mode,
                    'teaching_days' => $expectedDays,
                    'days_taken' => $taken,
                    // NULL when the calendar has no teaching days — "—",
                    // never a fake 0% or 100%.
                    'coverage' => $expectedDays > 0 ? round($taken / $expectedDays, 4) : null,
                ];
            }
        }

        return view('livewire.attendance.coverage-report', [
            'periods' => $periods,
            'period' => $period,
            'rows' => $rows,
            'hasYear' => $yearId !== null,
        ]);
    }
}
