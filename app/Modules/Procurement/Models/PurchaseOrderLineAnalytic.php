<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.2 - the PO line's analytic split,
 * mirroring journal_entry_line_analytics. `amount` shares sum to the line's
 * amount_ht (conservation via Money::allocate in the writing Action);
 * `share_bp` is 00-core §7.2 basis points (1_000_000 = 100%).
 *
 * @property int $id
 * @property int $purchase_order_line_id
 * @property int $analytic_axis_id
 * @property int $analytic_value_id
 * @property int $amount
 * @property int $share_bp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseOrderLineAnalytic extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_order_line_id',
        'analytic_axis_id',
        'analytic_value_id',
        'amount',
        'share_bp',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'share_bp' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }
}
