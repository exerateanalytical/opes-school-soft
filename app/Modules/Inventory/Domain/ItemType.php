<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * docs/specs/06-assets-stores.md §7.3 - the mockup's Item Type filter.
 * `equipment` MAY carry an asset_category_id (I4), in which case its receipt
 * is the §8.6 capitalisation handoff rather than a stock entry.
 */
enum ItemType: string
{
    case Consumable = 'consumable';
    case Equipment = 'equipment';
    case Merchandise = 'merchandise';
}
