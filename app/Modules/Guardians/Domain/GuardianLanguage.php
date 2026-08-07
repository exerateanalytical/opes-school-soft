<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/**
 * docs/specs/07-students.md 7.1: "drives every message and document sent to
 * them". Cameroon is officially bilingual and a guardian's language is a
 * property of the person, not of the school section their child attends.
 */
enum GuardianLanguage: string
{
    case English = 'en';
    case French = 'fr';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
