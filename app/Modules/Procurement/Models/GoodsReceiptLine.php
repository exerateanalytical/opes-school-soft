<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.3 - a receipt line. The DB CHECK
 * enforces qty_accepted + qty_rejected = qty_received; `qty_rejected > 0`
 * is what flips the header's `has_discrepancy` and blocks the three-way
 * match on the linked PO line until a credit note or amendment resolves it.
 *
 * @property int $id
 * @property int $goods_receipt_id
 * @property int $line_no
 * @property int|null $purchase_order_line_id
 * @property string $description
 * @property string $qty_ordered
 * @property string $qty_received
 * @property string $qty_accepted
 * @property string $qty_rejected
 * @property string|null $rejection_reason
 * @property int|null $inventory_item_id
 * @property int|null $asset_category_id
 * @property array<int, string>|null $serial_numbers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GoodsReceiptLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'goods_receipt_id',
        'line_no',
        'purchase_order_line_id',
        'description',
        'qty_ordered',
        'qty_received',
        'qty_accepted',
        'qty_rejected',
        'rejection_reason',
        'inventory_item_id',
        'asset_category_id',
        'serial_numbers',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'qty_ordered' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'qty_accepted' => 'decimal:3',
            'qty_rejected' => 'decimal:3',
            'serial_numbers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GoodsReceipt, $this>
     */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }
}
