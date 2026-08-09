<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\MatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.5 - one line of a supplier invoice.
 *
 * Snapshots, never re-derived: `tax_code_id` + `tax_rate_bp_applied` from
 * the TaxCode version in force at invoice_date (§5.3), the prorata split
 * (`deductible + non_deductible = tax`, CHECK-enforced), and the §6.4
 * withholding resolution. Match state is PER LINE so the exception report
 * names the line, not the invoice (§4.4).
 *
 * @property int $id
 * @property int $supplier_invoice_id
 * @property int $line_no
 * @property int|null $purchase_order_line_id
 * @property int|null $goods_receipt_line_id
 * @property string $description
 * @property string $quantity
 * @property string|null $unit_of_measure
 * @property int $unit_price_ht
 * @property int $discount_rate_bp
 * @property int $amount_ht
 * @property int $tax_code_id
 * @property int $tax_rate_bp_applied
 * @property int $tax_amount
 * @property int $deductible_tax_amount
 * @property int $non_deductible_tax_amount
 * @property int $expense_account_id
 * @property bool $is_capitalised
 * @property int|null $asset_id
 * @property int|null $asset_category_id
 * @property int|null $inventory_item_id
 * @property int|null $withholding_rule_id
 * @property int $withholding_base
 * @property int $withholding_rate_bp_applied
 * @property int $withholding_amount
 * @property string|null $withholding_reason
 * @property string|null $withholding_exemption_ref
 * @property MatchStatus $match_status
 * @property string $matched_qty
 * @property int $price_variance
 * @property string $quantity_variance
 * @property string|null $match_exception_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierInvoiceLine extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'match_status' => MatchStatus::class,
            'unit_price_ht' => 'integer',
            'discount_rate_bp' => 'integer',
            'amount_ht' => 'integer',
            'tax_rate_bp_applied' => 'integer',
            'tax_amount' => 'integer',
            'deductible_tax_amount' => 'integer',
            'non_deductible_tax_amount' => 'integer',
            'withholding_base' => 'integer',
            'withholding_rate_bp_applied' => 'integer',
            'withholding_amount' => 'integer',
            'price_variance' => 'integer',
            'is_capitalised' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }

    /**
     * @return HasMany<SupplierInvoiceLineAnalytic, $this>
     */
    public function analytics(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLineAnalytic::class);
    }
}
