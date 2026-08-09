<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * docs/specs/07-students.md §8 rows 19-20: guardians always see the case
 * LIST (date, category, outcome), but the narrative detail only when the
 * school marks the case `guardian`. Cases involving another named minor are
 * `internal` and invisible in detail to EVERY guardian — including the
 * offender's own.
 */
enum DisciplineVisibility: string
{
    case Internal = 'internal';
    case Guardian = 'guardian';
}
