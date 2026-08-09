<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * docs/specs/03-tax-procurement.md §4.3 - `procurement.goods.received`,
 * dispatched AFTER the confirming transaction commits.
 *
 * Phase 9 (06-assets-stores.md) subscribes: Inventory creates the
 * StockMovement at PO unit cost for `inventory_item_id` lines, Assets a
 * provisional `pending_capitalisation` Asset for `asset_category_id` lines.
 * Until then the recorded intent lives on the receipt lines themselves -
 * this event deliberately POSTS NOTHING and moves no stock (posting on
 * receipt would double-count when the invoice arrives).
 */
final class GoodsReceived
{
    use Dispatchable;

    public const NAME = 'procurement.goods.received';

    public function __construct(
        public readonly int $goodsReceiptId,
        public readonly int $supplierId,
        public readonly ?int $purchaseOrderId,
    ) {}
}
