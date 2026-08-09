<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain;

/**
 * docs/specs/07-students.md §9.4 — why an absence was accepted as justified.
 * Recorded when the justification is RECEIVED (after the fact, §9.7); the
 * record's status does not change — an `absent` stays `absent`.
 */
enum JustificationType: string
{
    case Medical = 'medical';
    case Family = 'family';
    case Administrative = 'administrative';
    case Transport = 'transport';
    case Other = 'other';

    public function label(string $locale = 'en'): string
    {
        return __('attendance.justification.'.$this->value, [], $locale);
    }
}
