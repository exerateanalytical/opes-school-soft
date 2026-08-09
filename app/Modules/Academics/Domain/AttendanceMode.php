<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * How a class group takes its register (07-students §9.7–§9.8): one per day
 * or one per lesson. Owned by Academics because it is CLASS-GROUP
 * configuration — the Attendance module reads the column, it does not set it.
 * Per-lesson is mandatory (not optional) wherever the assessment framework
 * needs heures d'absence on the bulletin.
 */
enum AttendanceMode: string
{
    case Daily = 'daily';
    case PerLesson = 'per_lesson';

    public function label(string $locale = 'en'): string
    {
        return __('timetable.attendance_mode.'.$this->value, [], $locale);
    }
}
