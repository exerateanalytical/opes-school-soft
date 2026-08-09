<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.2 - required by the 03-tax-procurement prorata
 * rule: when input VAT is non-recoverable it is capitalised and the cost
 * basis says so.
 */
enum CostBasis: string
{
    case Ht = 'ht';
    case TtcNonRecoverableVatCapitalised = 'ttc_non_recoverable_vat_capitalised';
}
