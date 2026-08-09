<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * The fifteen preflight checks of docs/specs/05-hr-payroll.md 9.1, as
 * stable identifiers - what PayrollPreflightResult.check_code carries and
 * what the run screen keys its checklist rows (and their fix links) on.
 * Every check is fatal except UnfiledPriorDeclarations, which warns: a
 * school in arrears still needs to pay its staff.
 */
enum PreflightCheckCode: string
{
    case EmployerProfileMissing = 'EMPLOYER_PROFILE_MISSING';
    case EmployerRegimeUnconfirmed = 'EMPLOYER_REGIME_UNCONFIRMED';
    case ProrationConventionUnconfigured = 'PRORATION_CONVENTION_UNCONFIGURED';
    case StatutoryRateResolution = 'STATUTORY_RATE_RESOLUTION';
    case StatutoryBandCoverage = 'STATUTORY_BAND_COVERAGE';
    case IrppBracketCoverage = 'IRPP_BRACKET_COVERAGE';
    case FormulaTests = 'FORMULA_TESTS';
    case CnpsNumberMissing = 'CNPS_NUMBER_MISSING';
    case TimesheetNotValidated = 'TIMESHEET_NOT_VALIDATED';
    case DaysWorkedUnavailable = 'DAYS_WORKED_UNAVAILABLE';
    case DeductionCapUnconfigured = 'DEDUCTION_CAP_UNCONFIGURED';
    case BenefitBaremeUnconfigured = 'BENEFIT_BAREME_UNCONFIGURED';
    case AccountingPeriodLocked = 'ACCOUNTING_PERIOD_LOCKED';
    case DuplicatePayrollMonth = 'DUPLICATE_PAYROLL_MONTH';
    case UnfiledPriorDeclarations = 'UNFILED_PRIOR_DECLARATIONS';

    public function isFatal(): bool
    {
        return $this !== self::UnfiledPriorDeclarations;
    }
}
