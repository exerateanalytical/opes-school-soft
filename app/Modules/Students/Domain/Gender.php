<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/**
 * docs/specs/07-students.md 3.1.
 *
 * Two values, matching the ENUM in the students table and the Cameroonian
 * official record forms the school files against. The KPI cards on the student
 * management screen (11.1) count exactly these.
 */
enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
}
