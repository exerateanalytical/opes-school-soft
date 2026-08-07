<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.1. Two cases, matching the column definition and
 * the Cameroonian civil-status forms the data is transcribed from.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
