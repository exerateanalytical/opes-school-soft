<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\PaymentAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/04-fees.md §11.6 - line-level allocation of a payment to an
 * invoice line (A2). Append-only with two sanctioned exceptions:
 *
 *  - a TOP-UP: §11.6 mandates one live row per (payment, line), so a
 *    further allocation to the same line INCREASES `amount` - and only
 *    increases; shrinking an allocation is a reversal, never an edit;
 *  - the REVERSAL stamp: reversed_at / reversed_by / reversal_reason are
 *    set exactly once (§11.4/§11.5 - rows are never deleted).
 *
 * @property int $id
 * @property int $payment_id
 * @property int|null $invoice_id
 * @property int|null $invoice_line_id
 * @property int $amount
 * @property Carbon $allocated_at
 * @property int $allocated_by
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 */
final class PaymentAllocation extends Model
{
    /** @use HasFactory<PaymentAllocationFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'invoice_id',
        'invoice_line_id',
        'amount',
        'allocated_at',
        'allocated_by',
        'reversed_at',
        'reversed_by',
        'reversal_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'allocated_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentAllocation $allocation): void {
            $wasReversed = $allocation->getOriginal('reversed_at') !== null;

            foreach (array_keys($allocation->getDirty()) as $column) {
                if ($column === 'updated_at') {
                    continue;
                }

                // The reversal stamp, exactly once.
                if (in_array($column, ['reversed_at', 'reversed_by', 'reversal_reason'], true)) {
                    if ($wasReversed) {
                        throw new RuntimeException(
                            'An allocation reversal is permanent; the reversal stamp cannot change (04-fees §11.5).'
                        );
                    }

                    continue;
                }

                // The §11.6 top-up: amount may only grow, and only while live.
                if ($column === 'amount') {
                    if ($wasReversed || $allocation->isDirty('reversed_at')) {
                        throw new RuntimeException(
                            'A reversed allocation is frozen; its amount cannot change (04-fees §11.5).'
                        );
                    }

                    if ($allocation->amount <= (int) $allocation->getOriginal('amount')) {
                        throw new RuntimeException(
                            'An allocation amount may only be topped up (04-fees §11.6); shrinking it is a reversal, never an edit.'
                        );
                    }

                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Payment allocation column [%s] is immutable (04-fees A3).',
                    $column,
                ));
            }
        });

        static::deleting(function (PaymentAllocation $allocation): void {
            throw new RuntimeException(
                'Payment allocations are never deleted - they are reversed (04-fees §11.4/§11.5).'
            );
        });
    }

    protected static function newFactory(): PaymentAllocationFactory
    {
        return PaymentAllocationFactory::new();
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
