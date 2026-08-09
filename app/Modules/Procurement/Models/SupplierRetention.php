<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\SupplierRetentionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §3.3 - the retenue de garantie working
 * record: withheld to 4817 at first payment against the invoice, released
 * (Dr 4817 / Cr 401) by `ReleaseRetention` on final acceptance, or
 * cancelled when the withholding payment is voided. Retention is NOT a
 * discount and never touches expense.
 *
 * @property int $id
 * @property int $supplier_invoice_id
 * @property int $supplier_id
 * @property int $supplier_payment_id
 * @property int $retention_account_id
 * @property int $amount
 * @property SupplierRetentionStatus $status
 * @property string|null $release_due_on
 * @property int $withheld_journal_entry_id
 * @property Carbon|null $released_at
 * @property int|null $released_by
 * @property int|null $release_journal_entry_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierRetention extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SupplierRetentionStatus::class,
            'amount' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (SupplierRetention $retention): void {
            throw new RuntimeException(
                'A retention record is never deleted; it is released or cancelled (03-tax-procurement 3.3).'
            );
        });
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'supplier_invoice_id');
    }
}
