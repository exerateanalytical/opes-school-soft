<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.2 - a PO line.
 *
 * After the parent PO is approved, the only columns that may move are the
 * fulfilment counters `qty_received` / `qty_invoiced` - and those only
 * inside a FOR UPDATE window held by ConfirmGoodsReceipt or the invoice
 * capture (§9 concurrency table). Everything commercial changes only via
 * AmendPurchaseOrder's amendment window (invariant 5), same as the header.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $line_no
 * @property int|null $requisition_line_id
 * @property string $description
 * @property int|null $inventory_item_id
 * @property int|null $asset_category_id
 * @property bool $is_capitalised
 * @property string $quantity
 * @property string|null $unit_of_measure
 * @property int $unit_price_ht
 * @property int $discount_rate_bp
 * @property int $amount_ht
 * @property int|null $tax_code_id
 * @property int $tax_amount
 * @property int $amount_ttc
 * @property int $expense_account_id
 * @property string $qty_received
 * @property string $qty_invoiced
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseOrderLine extends Model
{
    private const MUTABLE_AFTER_APPROVAL = [
        'qty_received',
        'qty_invoiced',
        'updated_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id',
        'line_no',
        'requisition_line_id',
        'description',
        'inventory_item_id',
        'asset_category_id',
        'is_capitalised',
        'quantity',
        'unit_of_measure',
        'unit_price_ht',
        'discount_rate_bp',
        'amount_ht',
        'tax_code_id',
        'tax_amount',
        'amount_ttc',
        'expense_account_id',
        'qty_received',
        'qty_invoiced',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_capitalised' => 'boolean',
            'unit_price_ht' => 'integer',
            'discount_rate_bp' => 'integer',
            'amount_ht' => 'integer',
            'tax_amount' => 'integer',
            'amount_ttc' => 'integer',
            'quantity' => 'decimal:3',
            'qty_received' => 'decimal:3',
            'qty_invoiced' => 'decimal:3',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrderLine $line): void {
            if (PurchaseOrder::amendmentWindowIsOpen()) {
                return;
            }

            /** @var PurchaseOrder|null $po */
            $po = $line->purchaseOrder()->first();

            if ($po === null || $po->status->isPreApproval()) {
                return;
            }

            foreach (array_keys($line->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE_AFTER_APPROVAL, true)) {
                    throw new RuntimeException(sprintf(
                        'PO line %d of %s is approved and immutable; column [%s] changes only through '
                        .'a PurchaseOrderAmendment (03-tax-procurement 4.2 invariant 5).',
                        $line->line_no,
                        $po->po_no,
                        $column,
                    ));
                }
            }
        });
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<PurchaseOrderLineAnalytic, $this>
     */
    public function analytics(): HasMany
    {
        return $this->hasMany(PurchaseOrderLineAnalytic::class);
    }
}
