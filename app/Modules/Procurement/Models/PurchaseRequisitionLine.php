<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.1 - a requisition line. Quantities
 * are DECIMAL(12,3) and surface here as strings (exact, never floats);
 * money is whole-FCFA BIGINT (00-core §7.1).
 *
 * `qty_ordered` is maintained only by CreatePurchaseOrder inside its
 * transaction, so `partially_ordered`/`ordered` on the header is always
 * derivable from the lines.
 *
 * @property int $id
 * @property int $requisition_id
 * @property int $line_no
 * @property string $description
 * @property int|null $inventory_item_id
 * @property int|null $asset_category_id
 * @property string $quantity
 * @property string|null $unit_of_measure
 * @property int $estimated_unit_price
 * @property int $estimated_amount
 * @property int $expense_account_id
 * @property string $qty_ordered
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseRequisitionLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'requisition_id',
        'line_no',
        'description',
        'inventory_item_id',
        'asset_category_id',
        'quantity',
        'unit_of_measure',
        'estimated_unit_price',
        'estimated_amount',
        'expense_account_id',
        'qty_ordered',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estimated_unit_price' => 'integer',
            'estimated_amount' => 'integer',
            'quantity' => 'decimal:3',
            'qty_ordered' => 'decimal:3',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'requisition_id');
    }
}
