<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * docs/specs/05-hr-payroll.md 11.6.
 */
enum CnpsClaimType: string
{
    case Maternity = 'maternity';
    case WorkAccident = 'work_accident';
    case Sickness = 'sickness';
    case FamilyAllowance = 'family_allowance';
}
