<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Who may sleep in a hostel building. Deliberately NOT the Students
 * `Gender` enum (that names a person; this names a building's admission
 * rule, which has a third state). AllocateBed maps a student's gender to
 * the admissible hostels: boys -> male, girls -> female, mixed -> anyone.
 */
enum HostelGender: string
{
    case Boys = 'boys';
    case Girls = 'girls';
    case Mixed = 'mixed';

    /** Whether a student of the given gender ('male'/'female') may board here. */
    public function admits(string $studentGender): bool
    {
        return match ($this) {
            self::Boys => $studentGender === 'male',
            self::Girls => $studentGender === 'female',
            self::Mixed => true,
        };
    }
}
