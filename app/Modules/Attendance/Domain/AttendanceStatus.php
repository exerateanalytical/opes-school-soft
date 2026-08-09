<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * docs/specs/07-students.md §9.4 / §9.6. Six enum states over three
 * orthogonal concepts (§9.7) — do NOT collapse `excused` and `is_justified`:
 * `excused` is authorised in advance; justification arrives after the fact.
 *
 * §9.6 classifies each status exactly once:
 *  - `present`, `late`        → present (late is PRESENT in the rate)
 *  - `absent`, `sick`         → countable absences (numerator subtraction)
 *  - `excused`                → out of the numerator, IN the denominator
 *  - `suspended`              → out of BOTH (double-punishment rule)
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Late = 'late';
    case Excused = 'excused';
    case Sick = 'sick';
    case Suspended = 'suspended';

    public function label(string $locale = 'en'): string
    {
        return __('attendance.status.'.$this->value, [], $locale);
    }

    /** §9.6: `late` counts as present. */
    public function isPresentForRate(): bool
    {
        return $this === self::Present || $this === self::Late;
    }

    /** §9.6 `sessions_absent`: status ∈ (absent, sick). */
    public function isCountableAbsence(): bool
    {
        return $this === self::Absent || $this === self::Sick;
    }

    /** §9.7: statuses whose per-lesson minutes feed heures d'absence. */
    public function accruesAbsenceHours(): bool
    {
        return $this === self::Absent || $this === self::Sick || $this === self::Excused;
    }

    /** §9.7: statuses a received justification may attach to. */
    public function isJustifiable(): bool
    {
        return $this->accruesAbsenceHours();
    }

    /**
     * Storage rule §9.4: exception rows only — a `present` mark writes no
     * row; every other status does.
     */
    public function isExceptionRow(): bool
    {
        return $this !== self::Present;
    }
}
