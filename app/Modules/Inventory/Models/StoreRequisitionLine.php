<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.8 - requested/approved/issued
 * quantities per item.
 *
 * @property int $id
 * @property int $store_requisition_id
 * @property int $item_id
 * @property string $quantity_requested
 * @property string|null $quantity_approved
 * @property string $quantity_issued
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StoreRequisitionLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'store_requisition_id', 'item_id',
        'quantity_requested', 'quantity_approved', 'quantity_issued',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_requested' => 'string',
            'quantity_approved' => 'string',
            'quantity_issued' => 'string',
        ];
    }

    /**
     * @return BelongsTo<StoreRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(StoreRequisition::class, 'store_requisition_id');
    }

    /**
     * @return BelongsTo<Item, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
