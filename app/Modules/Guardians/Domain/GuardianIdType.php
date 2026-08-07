<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.1. Pairs with `id_number` (encrypted) and
 * `id_number_blind_index` - the first tier of the 7.7 duplicate-match key.
 */
enum GuardianIdType: string
{
    case NationalId = 'national_id';
    case Passport = 'passport';
    case ResidencePermit = 'residence_permit';
    case DriversLicence = 'drivers_licence';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
