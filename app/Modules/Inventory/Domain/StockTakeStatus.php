<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** docs/specs/06-assets-stores.md §7.10. */
enum StockTakeStatus: string
{
    case Draft = 'draft';
    case Counting = 'counting';
    case Counted = 'counted';
    case Approved = 'approved';
    case Posted = 'posted';
    case Cancelled = 'cancelled';
}
