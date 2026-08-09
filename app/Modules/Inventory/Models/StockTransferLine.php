<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.9 - two movements per line at the
 * SENDING location's derived cost.
 *
 * @property int $id
 * @property int $stock_transfer_id
 * @property int $item_id
 * @property string $quantity
 * @property int $transfer_cost
 * @property int|null $out_movement_id
 * @property int|null $in_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockTransferLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'stock_transfer_id', 'item_id', 'quantity', 'transfer_cost',
        'out_movement_id', 'in_movement_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'transfer_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockTransfer, $this>
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
