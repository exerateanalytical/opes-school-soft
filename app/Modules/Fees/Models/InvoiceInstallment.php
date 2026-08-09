<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\InvoiceInstallmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/04-fees.md §3.3. First-class rows because AGING IS BY
 * INSTALMENT DUE DATE, not invoice date. A payment schedule over the invoice
 * as a whole, never a partition of lines.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $sequence_no
 * @property string $label
 * @property string|null $label_fr
 * @property int $amount
 * @property Carbon $due_date
 * @property bool $is_cancelled
 * @property string|null $cancelled_reason
 */
final class InvoiceInstallment extends Model
{
    /** @use HasFactory<InvoiceInstallmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id',
        'sequence_no',
        'label',
        'label_fr',
        'amount',
        'due_date',
        'is_cancelled',
        'cancelled_reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'is_cancelled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
