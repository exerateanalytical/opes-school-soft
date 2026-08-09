<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\PaymentVoidReason;
use App\Modules\Fees\Domain\PaymentVoidStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/04-fees.md §11.5 - the correction record for a payment. The
 * generated-column UNIQUE (uq_payment_voids_confirmed) guarantees at most
 * one CONFIRMED void per payment; this model only adds append-only
 * discipline: a void row, once written, is history.
 *
 * @property int $id
 * @property int $payment_id
 * @property PaymentVoidReason $reason_type
 * @property string $reason_note
 * @property int $voided_by
 * @property int|null $approved_by
 * @property Carbon $voided_at
 * @property int|null $reversal_journal_entry_id
 * @property PaymentVoidStatus $status
 */
final class PaymentVoid extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'reason_type',
        'reason_note',
        'voided_by',
        'approved_by',
        'voided_at',
        'reversal_journal_entry_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason_type' => PaymentVoidReason::class,
            'status' => PaymentVoidStatus::class,
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PaymentVoid $void): void {
            throw new RuntimeException(
                'A payment void is append-only and cannot be edited (04-fees §11.5/A3).'
            );
        });

        static::deleting(function (PaymentVoid $void): void {
            throw new RuntimeException(
                'A payment void is append-only and cannot be deleted (04-fees §11.5/A3).'
            );
        });
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
