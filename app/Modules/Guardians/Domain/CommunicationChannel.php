<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.8. */
enum CommunicationChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
    case Whatsapp = 'whatsapp';
    case Push = 'push';
    case Call = 'call';
    case InPerson = 'in_person';
    case Letter = 'letter';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
