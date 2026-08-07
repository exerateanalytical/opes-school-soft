<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.8. */
enum FollowUpStatus: string
{
    case None = 'none';
    case Open = 'open';
    case Done = 'done';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
