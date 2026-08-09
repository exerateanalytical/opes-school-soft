<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §2.1 - centre des impôts de rattachement.
 * Load-bearing: selects the DSF due date (§7.6): DGE → 15 March, CIME →
 * 15 April, CDI/CSI/other → 15 May of year+1.
 */
enum TaxCentreType: string
{
    case Dge = 'DGE';
    case Cime = 'CIME';
    case Cdi = 'CDI';
    case Csi = 'CSI';
}
