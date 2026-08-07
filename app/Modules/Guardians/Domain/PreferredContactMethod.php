<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.1.
 *
 * A DELIVERY preference, not an authorization (7.4). Nothing in
 * GuardianScopeMatrix may ever read it.
 */
enum PreferredContactMethod: string
{
    case Phone = 'phone';
    case Sms = 'sms';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case InPerson = 'in_person';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
