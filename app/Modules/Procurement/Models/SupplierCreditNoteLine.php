<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/03-tax-procurement.md §4.8 - one line of an avoir, mirroring
 * the invoice line it corrects with the SAME tax_code_id and
 * expense_account_id, its own snapshotted rate, and the same CHECK-held
 * `deductible + non_deductible = tax` conservation.
 *
 * @property int $id
 * @property int $supplier_credit_note_id
 * @property int $line_no
 * @property int|null $supplier_invoice_line_id
 * @property string $description
 * @property string $quantity
 * @property string|null $unit_of_measure
 * @property int $unit_price_ht
 * @property int $amount_ht
 * @property int $tax_code_id
 * @property int $tax_rate_bp_applied
 * @property int $tax_amount
 * @property int $deductible_tax_amount
 * @property int $non_deductible_tax_amount
 * @property int $expense_account_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierCreditNoteLine extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price_ht' => 'integer',
            'amount_ht' => 'integer',
            'tax_rate_bp_applied' => 'integer',
            'tax_amount' => 'integer',
            'deductible_tax_amount' => 'integer',
            'non_deductible_tax_amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SupplierCreditNote, $this>
     */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(SupplierCreditNote::class, 'supplier_credit_note_id');
    }
}
