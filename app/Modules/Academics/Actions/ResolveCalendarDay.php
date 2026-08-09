<?php

declare(strict_types=1);

namespace App\Modules\Academics\Actions;

use App\Modules\Academics\Domain\CalendarDayType;
use App\Modules\Academics\Models\SchoolCalendarDay;
use Illuminate\Support\Carbon;

/**
 * THE cross-module door for the school calendar (docs/plans/phase-08.md F1).
 *
 * Attendance's OpenAttendanceRegister gates register creation on the day
 * type (07-students §9.3: `teaching`/`exam` only, holiday needs an override)
 * and must not import an Academics Model to do it — so the answer crosses the
 * boundary as a plain array, not as SchoolCalendarDay.
 *
 * Resolution is section-specific over all-sections (§9.2): a row for the
 * caller's section shadows the SECTION_ALL sentinel row on the same date.
 * A NULL return means the calendar has not been seeded for that date — the
 * caller must BLOCK with a clear message, never default to "teaching".
 *
 * Deliberately not Gate-checked: it is a read-only helper consumed inside
 * Actions that carry their own authorization (the pattern of the other read
 * doors, e.g. Students\Actions\LogStudentActivity).
 */
final class ResolveCalendarDay
{
    /**
     * @return array{
     *     day_type: string,
     *     allows_register: bool,
     *     label: string|null,
     *     label_fr: string|null,
     *     school_section_id: int,
     * }|null
     */
    public function handle(int $academicYearId, string $date, ?int $schoolSectionId = null): ?array
    {
        $day = Carbon::parse($date)->toDateString();

        $candidates = SchoolCalendarDay::query()
            ->where('academic_year_id', $academicYearId)
            ->whereDate('date', $day)
            ->whereIn('school_section_id', array_values(array_unique([
                $schoolSectionId ?? SchoolCalendarDay::SECTION_ALL,
                SchoolCalendarDay::SECTION_ALL,
            ])))
            // Specific-over-all: the larger school_section_id (the real one)
            // sorts after the 0 sentinel, so DESC puts the specific row first.
            ->orderByDesc('school_section_id')
            ->get();

        $row = $candidates->first();

        if ($row === null) {
            return null;
        }

        /** @var CalendarDayType $type */
        $type = $row->day_type;

        return [
            'day_type' => $type->value,
            'allows_register' => $type->allowsRegister(),
            'label' => $row->label,
            'label_fr' => $row->label_fr,
            'school_section_id' => $row->school_section_id,
        ];
    }
}
