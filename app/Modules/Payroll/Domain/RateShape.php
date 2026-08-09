<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * How a statutory rate row expresses its amount (docs/specs/05-hr-payroll.md
 * 4.2, fixing H1): percentage rows carry basis points, flat bands carry a
 * whole-FCFA amount per band (RAV, TDL), progressive brackets carry a rate
 * per band on an annual basis (IRPP).
 */
enum RateShape: string
{
    case Percentage = 'percentage';
    case FlatBand = 'flat_band';
    case ProgressiveBracket = 'progressive_bracket';
}
