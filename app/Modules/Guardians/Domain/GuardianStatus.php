<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * The "Active Guardian" badge on the profile mockup (docs/specs/07-students.md
 * 7.1).
 *
 * Not merely cosmetic: 7.5's reading rules make `status = 'active'` a
 * conjunctive condition on EVERY authorization grant, so deactivating a
 * guardian closes the portal for them across all their children at once,
 * without touching a single link row.
 */
enum GuardianStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
