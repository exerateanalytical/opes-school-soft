<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

/** docs/specs/07-students.md 7.8. */
enum MeetingType: string
{
    case ParentTeacher = 'parent_teacher';
    case Disciplinary = 'disciplinary';
    case Financial = 'financial';
    case Admission = 'admission';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
