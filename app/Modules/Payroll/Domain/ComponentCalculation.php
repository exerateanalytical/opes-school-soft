<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * How a payroll component computes (docs/specs/05-hr-payroll.md 5.2).
 * `statutory` resolves through StatutoryRateResolver - never a stored
 * amount; `formula` is the 5.4 whitelisted grammar parsed at save (H4:
 * the alternative was eval() reachable from a settings screen).
 */
enum ComponentCalculation: string
{
    case Fixed = 'fixed';
    case Percentage = 'percentage';
    case Hourly = 'hourly';
    case Table = 'table';
    case Formula = 'formula';
    case Statutory = 'statutory';
}
