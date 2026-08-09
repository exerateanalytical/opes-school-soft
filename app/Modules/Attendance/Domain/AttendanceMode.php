<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * docs/specs/07-students.md §9.7–§9.8. Mirrors class_groups.attendance_mode
 * (owned by Academics, set through SetClassGroupAttendanceMode). Module-local
 * on purpose: Attendance may not import Academics' enum any more than its
 * Models — the VALUE crosses the boundary via DB::table, this type gives it
 * a name on this side.
 *
 * Per-lesson is an 8× row multiplier and the only mode that can yield
 * heures d'absence; it is mandatory for MINESEC frameworks that require
 * absence hours.
 */
enum AttendanceMode: string
{
    case Daily = 'daily';
    case PerLesson = 'per_lesson';

    public function label(string $locale = 'en'): string
    {
        return __('attendance.mode.'.$this->value, [], $locale);
    }

    public function requiresSlot(): bool
    {
        return $this === self::PerLesson;
    }
}
