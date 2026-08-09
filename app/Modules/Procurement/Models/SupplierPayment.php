<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\SupplierFeeBearer;
use App\Modules\Procurement\Domain\SupplierPaymentClearingState;
use App\Modules\Procurement\Domain\SupplierPaymentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.7 - the paiement fournisseur, series
 * `PF/2026/000123`.
 *
 * Immutability: a PAID payment is frozen by the observer - only the void
 * lifecycle (status), clearing state, batch membership and version may
 * move. Corrections are `SupplierPaymentVoid` plus a fresh payment, never
 * an edit. Deletion is refused ALWAYS (§9 RESTRICT: a payment is never
 * deleted), by observer here and by the BEFORE DELETE trigger at the
 * database.
 *
 * @property int $id
 * @property string $payment_no
 * @property int $supplier_id
 * @property string $payment_date
 * @property string $payment_method
 * @property int $treasury_account_id
 * @property string|null $reference
 * @property int $gross_amount
 * @property int $withholding_amount
 * @property int $fee_amount
 * @property SupplierFeeBearer $fee_bearer
 * @property int|null $fee_expense_account_id
 * @property int $net_amount
 * @property SupplierPaymentStatus $status
 * @property SupplierPaymentClearingState $clearing_state
 * @property int $recorded_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $paid_by
 * @property Carbon|null $paid_at
 * @property int|null $journal_entry_id
 * @property int|null $batch_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int $accounting_period_id
 * @property string|null $notes
 * @property int $version
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierPayment extends Model
{
    /**
     * Columns the observer lets move once the payment is PAID: the void
     * lifecycle and housekeeping - never the money.
     */
    private const MUTABLE_AFTER_PAID = [
        'status',
        'clearing_state',
        'batch_id',
        'version',
        'updated_at',
    ];

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierPaymentStatus::class,
            'clearing_state' => SupplierPaymentClearingState::class,
            'fee_bearer' => SupplierFeeBearer::class,
            'gross_amount' => 'integer',
            'withholding_amount' => 'integer',
            'fee_amount' => 'integer',
            'net_amount' => 'integer',
            'version' => 'integer',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierPayment $payment): void {
            /** @var string|SupplierPaymentStatus|null $original */
            $original = $payment->getOriginal('status');
            $originalStatus = $original instanceof SupplierPaymentStatus
                ? $original
                : SupplierPaymentStatus::from((string) $original);

            if (in_array($originalStatus, [SupplierPaymentStatus::Paid, SupplierPaymentStatus::Voided], true)) {
                foreach (array_keys($payment->getDirty()) as $column) {
                    if (! in_array($column, self::MUTABLE_AFTER_PAID, true)) {
                        throw new RuntimeException(sprintf(
                            'Supplier payment %s is %s and immutable; [%s] can only change through a void '
                            .'plus a fresh payment (03-tax-procurement 4.7).',
                            (string) $payment->getOriginal('payment_no'),
                            $originalStatus->value,
                            $column,
                        ));
                    }
                }
            }
        });

        static::deleting(function (SupplierPayment $payment): void {
            throw new RuntimeException(sprintf(
                'Supplier payment %s can never be deleted - void it instead (03-tax-procurement 9).',
                $payment->payment_no,
            ));
        });
    }

    /**
     * @return HasMany<SupplierPaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isVoided(): bool
    {
        return $this->status === SupplierPaymentStatus::Voided;
    }
}
