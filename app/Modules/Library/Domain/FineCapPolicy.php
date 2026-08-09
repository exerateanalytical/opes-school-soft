<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/**
 * docs/specs/06-assets-stores.md §10.5 - a 200 FCFA/day fine on a
 * 6,000 FCFA book must not reach 40,000. Per membership class.
 */
enum FineCapPolicy: string
{
    case ReplacementCost = 'replacement_cost';
    case Uncapped = 'uncapped';
}
