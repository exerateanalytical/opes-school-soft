<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The statutory return set (docs/specs/05-hr-payroll.md 11.1-11.2, fixing
 * C5: v1 specified only DIPE and would have left the DGI monthly salary
 * return - the one with the most aggressive penalty regime - unfiled).
 */
enum DeclarationType: string
{
    case Dipe = 'dipe';
    case CnpsContributionSchedule = 'cnps_contribution_schedule';
    case DgiMonthlySalaryReturn = 'dgi_monthly_salary_return';
    case TdlRemittance = 'tdl_remittance';
    case AnnualSalaryReturn = 'annual_salary_return';
    case CnpsAnnual = 'cnps_annual';
    case StaffRegister = 'staff_register';
}
