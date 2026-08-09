<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 3.4. `hourly` is the vacataire path (C6) - a
 * first-class citizen, because most teaching staff at a typical Cameroonian
 * private school are paid by the hour.
 */
enum WorkingTime: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case Hourly = 'hourly';

    public function isHourly(): bool
    {
        return $this === self::Hourly;
    }
}
