<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\ReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.7 - holds no cost, posts nothing; only
 * moves `stock_balances.quantity_reserved` under the balance row lock.
 *
 * @property int $id
 * @property int $item_id
 * @property int $store_location_id
 * @property string $quantity
 * @property string $reserved_for_type
 * @property int $reserved_for_id
 * @property int $reserved_by
 * @property Carbon|null $expires_on
 * @property ReservationStatus $status
 * @property string|null $active_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockReservation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'item_id', 'store_location_id', 'quantity',
        'reserved_for_type', 'reserved_for_id', 'reserved_by',
        'expires_on', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'string',
            'expires_on' => 'date',
            'status' => ReservationStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
