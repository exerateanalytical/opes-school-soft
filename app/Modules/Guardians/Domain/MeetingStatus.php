<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.8. */
enum MeetingStatus: string
{
    case Scheduled = 'scheduled';
    case Held = 'held';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
