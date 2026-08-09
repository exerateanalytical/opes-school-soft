<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * What a component's percentage or band applies to (docs/specs/05-hr-payroll.md
 * 5.2). A superset of RateBasis: components may additionally reference `net`.
 */
enum ComponentBasis: string
{
    case Basic = 'basic';
    case Gross = 'gross';
    case Sbt = 'sbt';
    case Taxable = 'taxable';
    case CnpsCapped = 'cnps_capped';
    case CnpsUncapped = 'cnps_uncapped';
    case IrppAmount = 'irpp_amount';
    case Net = 'net';
}
