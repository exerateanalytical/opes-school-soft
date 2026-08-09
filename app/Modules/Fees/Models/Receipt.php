<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\ReceiptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * One ISSUANCE of the printed receipt document (docs/specs/04-fees.md §14).
 * copy_no 1 is the original; ReissueReceipt appends the next copy_no
 * (printed as DUPLICATA). Append-only except the void flag, which
 * VoidPayment sets on every issuance so a reprint of a voided receipt is
 * blocked and every printed copy remains discoverable (§11.5 step 7).
 *
 * @property int $id
 * @property int $payment_id
 * @property string $receipt_no
 * @property int $copy_no
 * @property string|null $reissue_reason
 * @property bool $is_voided
 * @property int $issued_by
 * @property Carbon $issued_at
 */
final class Receipt extends Model
{
    /** @use HasFactory<ReceiptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'payment_id',
        'receipt_no',
        'copy_no',
        'reissue_reason',
        'is_voided',
        'issued_by',
        'issued_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'copy_no' => 'integer',
            'is_voided' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Receipt $receipt): void {
            foreach (array_keys($receipt->getDirty()) as $column) {
                if (in_array($column, ['is_voided', 'updated_at'], true)) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Receipt %s copy %d is append-only; column [%s] cannot change. '
                    .'A replacement is a reissue (new copy row), never an edit.',
                    (string) $receipt->getOriginal('receipt_no'),
                    (int) $receipt->getOriginal('copy_no'),
                    $column,
                ));
            }

            // The flag is one-way: un-voiding a receipt would resurrect a
            // printable document for a payment whose void is permanent.
            if ($receipt->isDirty('is_voided') && (bool) $receipt->getOriginal('is_voided')) {
                throw new RuntimeException('A voided receipt cannot be un-voided.');
            }
        });

        static::deleting(function (Receipt $receipt): void {
            throw new RuntimeException('A receipt issuance record cannot be deleted (04-fees A3).');
        });
    }

    protected static function newFactory(): ReceiptFactory
    {
        return ReceiptFactory::new();
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
