<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Whether a progressive bracket table is stated per month or per year
 * (docs/specs/05-hr-payroll.md 4.2, 6.3). IRPP brackets are ANNUAL and the
 * engine has exactly one path - ANNUALISE, APPLY, DIVIDE, ROUND ONCE - so a
 * seed/engine mismatch here is a 12x error. Making the basis data keeps
 * that mismatch detectable instead of implicit.
 */
enum BracketBasis: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
