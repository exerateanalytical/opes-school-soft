<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §2.1 / §5 - the school's tax regime.
 *
 * §2.2 invariant 2: TVA registration requires Reel. Whether the régime
 * simplifié may be TVA-registered NEEDS VERIFICATION; until verified only
 * Reel is permitted and the rule text is shown.
 */
enum TaxRegime: string
{
    case Reel = 'reel';
    case Simplifie = 'simplifie';
    case Liberatoire = 'liberatoire';
    case NonAssujetti = 'non_assujetti';
}
