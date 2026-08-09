<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * IRPP engine mode (docs/specs/05-hr-payroll.md 6.5, fixing H10):
 * `ytd_cumulative` is the DEFAULT because this-month-times-twelve is exact
 * only for perfectly flat pay; `annualised` exists for the flat-pay
 * equivalence property the tests assert.
 */
enum IrppMode: string
{
    case YtdCumulative = 'ytd_cumulative';
    case Annualised = 'annualised';
}
