<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.1. */
enum ResidentialStatus: string
{
    case OwnHouse = 'own_house';
    case Rented = 'rented';
    case FamilyHouse = 'family_house';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
