<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.5 - the locked row. COMPOSITE primary
 * key (item_id, store_location_id), which Eloquent cannot address for
 * writes: this model is a READ face for screens and tests. All writes go
 * through the Actions' MovesStock helper (query-builder UPDATE under
 * SELECT..FOR UPDATE), never through save() - saving this model would
 * build a broken WHERE on a single key column.
 *
 * `quantity_available` (on_hand - reserved) is derived, never stored.
 *
 * @property int $item_id
 * @property int $store_location_id
 * @property string $quantity_on_hand
 * @property string $quantity_reserved
 * @property int $value_on_hand
 * @property Carbon|null $last_movement_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockBalance extends Model
{
    protected $primaryKey = 'item_id';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_id' => 'integer',
            'store_location_id' => 'integer',
            'quantity_on_hand' => 'string',
            'quantity_reserved' => 'string',
            'value_on_hand' => 'integer',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * @return BelongsTo<StoreLocation, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(StoreLocation::class, 'store_location_id');
    }
}
