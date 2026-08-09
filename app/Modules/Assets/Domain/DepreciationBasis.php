<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §4.2. Only the accounting basis posts to the ledger;
 * the fiscal basis feeds the DSF réintégrations working paper (§4.7) and
 * is not generated until the V10 CGI rates are verified and entered.
 */
enum DepreciationBasis: string
{
    case Accounting = 'accounting';
    case Fiscal = 'fiscal';
}
