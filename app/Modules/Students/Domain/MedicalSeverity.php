<?php

declare(strict_types=1);

namespace App\Modules\Students\Domain;

/** docs/specs/07-students.md 8.2. */
enum MedicalSeverity: string
{
    case Low = 'low';
    case Moderate = 'moderate';
    case High = 'high';
}
