<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * What a statutory rate applies TO (docs/specs/05-hr-payroll.md 4.2).
 *
 * RAV bands key on GROSS while TDL bands key on BASIC (2.2) - one basis for
 * both shifts most of the staff a band, which is why the basis is a column
 * and not a convention. `cnps_capped` vs `cnps_uncapped` is the N1 fix at
 * the type level: RP applies to the uncapped base, always.
 */
enum RateBasis: string
{
    case Basic = 'basic';
    case Sbt = 'sbt';
    case Gross = 'gross';
    case Taxable = 'taxable';
    case CnpsCapped = 'cnps_capped';
    case CnpsUncapped = 'cnps_uncapped';
    case IrppAmount = 'irpp_amount';
}
