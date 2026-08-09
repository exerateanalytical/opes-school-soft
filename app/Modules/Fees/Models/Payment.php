<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\ClearingState;
use App\Modules\Fees\Domain\FeeBearer;
use App\Modules\Fees\Domain\PaymentMethod;
use App\Modules\Fees\Domain\PaymentVoidStatus;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/04-fees.md §11.1 - conceptually immutable (A3).
 *
 * The observer below is the enforcement, following the Student matricule
 * pattern (07-students 6.4): registered in booted() so it cannot be
 * forgotten from a console command, a queued job or a test that never
 * boots the HTTP kernel. After insert the ONLY legitimate writes are:
 *
 *  - the clearing-state machine (§11.4): clearing_state, bounced_on,
 *    bounce_reason;
 *  - the `unallocated_amount` derived cache (§12.3), maintained only
 *    inside the §11.2 lock;
 *  - a once-only stamp of `journal_entry_id` (null -> id, set by
 *    RecordPayment after PostFromEvent returns);
 *  - `notes`.
 *
 * Everything else - the money, the payer, the dates, the receipt number -
 * throws. A correction is a VOID plus a re-record (§11.5), never an edit.
 *
 * @property int $id
 * @property string $receipt_no
 * @property int $student_id
 * @property int|null $enrollment_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property PaymentMethod $payment_method
 * @property int $amount
 * @property int $fee_amount
 * @property FeeBearer $fee_bearer
 * @property string|null $reference
 * @property string $payer_name
 * @property string|null $payer_phone
 * @property string $value_date
 * @property string $posting_date
 * @property ClearingState $clearing_state
 * @property string|null $bounced_on
 * @property string|null $bounce_reason
 * @property int $unallocated_amount
 * @property string|null $notes
 * @property bool $is_migration
 * @property int|null $journal_entry_id
 * @property string|null $idempotency_key
 * @property int $received_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory;

    /**
     * The columns the observer allows to change after insert. Everything
     * NOT in this list is financial and frozen.
     */
    private const MUTABLE_AFTER_INSERT = [
        'clearing_state',
        'bounced_on',
        'bounce_reason',
        'unallocated_amount',
        'notes',
        'updated_at',
    ];

    /** @var list<string> */
    protected $fillable = [
        'receipt_no',
        'student_id',
        'enrollment_id',
        'academic_year_id',
        'fiscal_year_id',
        'payment_method',
        'amount',
        'fee_amount',
        'fee_bearer',
        'reference',
        'payer_name',
        'payer_phone',
        'value_date',
        'posting_date',
        'clearing_state',
        'bounced_on',
        'bounce_reason',
        'unallocated_amount',
        'notes',
        'is_migration',
        'journal_entry_id',
        'idempotency_key',
        'received_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payment_method' => PaymentMethod::class,
            'fee_bearer' => FeeBearer::class,
            'clearing_state' => ClearingState::class,
            'amount' => 'integer',
            'fee_amount' => 'integer',
            'unallocated_amount' => 'integer',
            'is_migration' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Payment $payment): void {
            foreach (array_keys($payment->getDirty()) as $column) {
                if (in_array($column, self::MUTABLE_AFTER_INSERT, true)) {
                    continue;
                }

                // The once-only ledger stamp: null -> id is the tail of
                // RecordPayment's own transaction; changing a stamp that
                // exists would orphan the posted entry.
                if ($column === 'journal_entry_id' && $payment->getOriginal('journal_entry_id') === null) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Payment %s is immutable; column [%s] cannot be changed after recording. '
                    .'Corrections are a void plus a re-record (04-fees §11.5), never an edit.',
                    (string) $payment->getOriginal('receipt_no'),
                    $column,
                ));
            }
        });

        static::deleting(function (Payment $payment): void {
            throw new RuntimeException(sprintf(
                'Payment %s is append-only and cannot be deleted (04-fees A3).',
                $payment->receipt_no,
            ));
        });
    }

    protected static function newFactory(): PaymentFactory
    {
        return PaymentFactory::new();
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**
     * @return HasMany<PaymentVoid, $this>
     */
    public function voids(): HasMany
    {
        return $this->hasMany(PaymentVoid::class);
    }

    /**
     * @return HasMany<Receipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    /**
     * §11.5: derived, never stored - there is no is_voided column to drift.
     */
    public function isVoided(): bool
    {
        return $this->voids()
            ->where('status', PaymentVoidStatus::Confirmed->value)
            ->exists();
    }

    /**
     * §5's exclusion pair in one predicate: a voided or bounced payment
     * contributes nothing to allocated().
     */
    public function countsTowardsBalance(): bool
    {
        return $this->clearing_state !== ClearingState::Bounced && ! $this->isVoided();
    }

    public static function lockForAllocation(int $id): self
    {
        /** @var self $payment */
        $payment = self::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        return $payment;
    }
}
