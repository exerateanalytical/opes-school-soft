<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.7 - the void record. UNIQUE on
 * `supplier_payment_id` at the database: a payment is voided at most once,
 * and `is_voided` on the payment is DERIVED from this row's existence.
 * `recorded_by ≠ voided_by` is enforced by the Action (§11.14).
 *
 * @property int $id
 * @property int $supplier_payment_id
 * @property string $reason
 * @property int $voided_by
 * @property Carbon $voided_at
 * @property int|null $reversal_journal_entry_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierPaymentVoid extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SupplierPaymentVoid $void): void {
            throw new RuntimeException(
                'A payment void is an accounting record and is never deleted (03-tax-procurement 9).'
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
}
