<?php

declare(strict_types=1);

namespace App\Modules\Academics\Domain;

/**
 * What a school date IS (07-students §9.2). Attendance registers may only be
 * opened on `teaching` or `exam` days — opening one on a public holiday
 * requires an override permission and a reason, because Cameroonian schools
 * do hold Saturday classes and refusing outright is worse than recording the
 * exception (§9.3).
 */
enum CalendarDayType: string
{
    case Teaching = 'teaching';
    case Weekend = 'weekend';
    case PublicHoliday = 'public_holiday';
    case SchoolHoliday = 'school_holiday';
    case Exam = 'exam';
    case StaffDay = 'staff_day';
    case Closure = 'closure';

    /**
     * §9.3: the only two day types a register can be opened on without an
     * override.
     */
    public function allowsRegister(): bool
    {
        return $this === self::Teaching || $this === self::Exam;
    }

    public function label(string $locale = 'en'): string
    {
        return __('timetable.day_type.'.$this->value, [], $locale);
    }
}
