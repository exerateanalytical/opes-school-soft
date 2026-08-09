<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\StockMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.6 - APPEND-ONLY (I11): BEFORE
 * UPDATE/DELETE triggers reject at the engine level, so this model carries
 * no update path at all; corrections are compensating movements linked by
 * `reversal_of_movement_id` (UNIQUE - reversed at most once, and a reversal
 * is never itself reversed).
 *
 * `total_cost` is authoritative and signed like `quantity`; `unit_cost` is
 * the I12 descriptive snapshot - a total is never recomputed from it.
 *
 * @property int $id
 * @property StockMovementType $movement_type
 * @property int $item_id
 * @property int $store_location_id
 * @property string $quantity
 * @property int $unit_cost
 * @property int $total_cost
 * @property string $balance_qty_after
 * @property int $balance_value_after
 * @property Carbon $moved_on
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property string|null $document_ref
 * @property int|null $journal_entry_id
 * @property string|null $posting_deferred_reason
 * @property int|null $store_requisition_id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int $performed_by
 * @property int|null $reversal_of_movement_id
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 */
final class StockMovement extends Model
{
    /** Append-only: created_at is written explicitly on insert. */
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'movement_type', 'item_id', 'store_location_id',
        'quantity', 'unit_cost', 'total_cost',
        'balance_qty_after', 'balance_value_after', 'moved_on',
        'reference_type', 'reference_id', 'document_ref',
        'journal_entry_id', 'posting_deferred_reason',
        'store_requisition_id', 'fiscal_year_id', 'academic_year_id',
        'performed_by', 'reversal_of_movement_id', 'idempotency_key',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'movement_type' => StockMovementType::class,
            'quantity' => 'string',
            'unit_cost' => 'integer',
            'total_cost' => 'integer',
            'balance_qty_after' => 'string',
            'balance_value_after' => 'integer',
            'moved_on' => 'date',
            'created_at' => 'datetime',
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

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_movement_id');
    }
}
