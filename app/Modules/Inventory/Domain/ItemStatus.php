<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * docs/specs/06-assets-stores.md §7.3 invariant I5: `discontinued` blocks
 * receipts and sales but PERMITS issues, transfers and stock-takes - you
 * must be able to run down the remaining stock. `archived` blocks everything
 * and requires zero on hand at every location.
 */
enum ItemStatus: string
{
    case Active = 'active';
    case Discontinued = 'discontinued';
    case Archived = 'archived';
}
