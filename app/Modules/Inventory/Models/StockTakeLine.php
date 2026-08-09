<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.10. `variance_quantity` is a GENERATED
 * STORED column (counted - system); `variance_value` is priced at the
 * frozen derived cost with the §7.1 empty-bin rule, shortage negative.
 *
 * @property int $id
 * @property int $stock_take_id
 * @property int $item_id
 * @property string $system_quantity
 * @property int $system_value
 * @property string|null $counted_quantity
 * @property string|null $variance_quantity
 * @property int|null $variance_value
 * @property string|null $reason_code
 * @property string|null $note
 * @property int|null $loss_account_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockTakeLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'stock_take_id', 'item_id', 'system_quantity', 'system_value',
        'counted_quantity', 'variance_value', 'reason_code', 'note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'system_quantity' => 'string',
            'system_value' => 'integer',
            'counted_quantity' => 'string',
            'variance_quantity' => 'string',
            'variance_value' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StockTake, $this>
     */
    public function take(): BelongsTo
    {
        return $this->belongsTo(StockTake::class, 'stock_take_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
