<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * What a payroll component IS (docs/specs/05-hr-payroll.md 5.2). Employer
 * charges are computed in the same pass as everything else but are NEVER
 * subtracted from net (7.2 rule 4); informational components (NET) are
 * printed, never posted.
 */
enum ComponentType: string
{
    case Earning = 'earning';
    case EmployeeDeduction = 'employee_deduction';
    case EmployerCharge = 'employer_charge';
    case Informational = 'informational';
}
