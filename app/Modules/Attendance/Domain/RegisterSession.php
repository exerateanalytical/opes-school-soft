<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * docs/specs/07-students.md §9.3 — the daily-mode session. Per-lesson
 * registers keep `full_day` and are distinguished by their timetable slot
 * inside the same UNIQUE key (sentinel 0 for daily).
 */
enum RegisterSession: string
{
    case Morning = 'morning';
    case Afternoon = 'afternoon';
    case FullDay = 'full_day';

    public function label(string $locale = 'en'): string
    {
        return __('attendance.session.'.$this->value, [], $locale);
    }
}
