<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.1. */
enum MaritalStatus: string
{
    case Single = 'single';
    case Married = 'married';
    case Divorced = 'divorced';
    case Widowed = 'widowed';
    case Separated = 'separated';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
