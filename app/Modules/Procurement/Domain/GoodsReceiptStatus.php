<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.3. Confirming is the moment the
 * `procurement.goods.received` event fires and PO line `qty_received`
 * advances under lock - and the moment deletion stops being possible (§9).
 */
enum GoodsReceiptStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
}
