<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** docs/specs/06-assets-stores.md §7.9. */
enum StockTransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case Received = 'received';
    case Cancelled = 'cancelled';
}
