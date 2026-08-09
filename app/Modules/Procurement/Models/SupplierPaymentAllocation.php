<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.7 - one payment settling one invoice
 * by `amount` (TTC), of which `withholding_amount` was settled by
 * withholding rather than cash.
 *
 * A void never deletes an allocation - it stamps `reversed_*` and the row
 * survives for the statement (04-fees §11.5 discipline). Deletion is
 * refused by observer.
 *
 * @property int $id
 * @property int $supplier_payment_id
 * @property int $supplier_invoice_id
 * @property int $amount
 * @property int $withholding_amount
 * @property int|null $lettering_id
 * @property string|null $letter_code
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierPaymentAllocation extends Model
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
            'withholding_amount' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SupplierPaymentAllocation $allocation): void {
            throw new RuntimeException(
                'A payment allocation is never deleted; a void stamps it reversed (03-tax-procurement 4.7).'
            );
        });
    }

    /**
     * @return BelongsTo<SupplierPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SupplierPayment::class, 'supplier_payment_id');
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
