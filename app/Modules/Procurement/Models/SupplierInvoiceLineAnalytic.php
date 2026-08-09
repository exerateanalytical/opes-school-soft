<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.5 - the analytic split of a supplier
 * invoice line, pivot rows summing to `amount_ht` (02-accounting H).
 * Conservation is the Action's job, via Money::allocate.
 *
 * @property int $id
 * @property int $supplier_invoice_line_id
 * @property int $analytic_axis_id
 * @property int $analytic_value_id
 * @property int $amount
 * @property int $share_bp
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierInvoiceLineAnalytic extends Model
{
    /** @var list<string> */
    protected $guarded = [];

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
     * @return BelongsTo<SupplierInvoiceLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoiceLine::class, 'supplier_invoice_line_id');
    }
}
