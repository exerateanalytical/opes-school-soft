<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\SupplierCreditNoteReasonType;
use App\Modules\Procurement\Domain\SupplierCreditNoteStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.8 - the avoir fournisseur.
 *
 * Issued in one act (create + post) by IssueSupplierCreditNote; once
 * issued it is immutable and never deleted (§9) - the observer and the
 * BEFORE DELETE trigger both hold the line. The TVA reduction it carries
 * belongs to the declaration period of ITS OWN date, never a restatement
 * of the original invoice's period.
 *
 * @property int $id
 * @property string $credit_note_no
 * @property string|null $supplier_reference
 * @property int $supplier_id
 * @property int|null $original_invoice_id
 * @property SupplierCreditNoteReasonType $reason_type
 * @property string $reason_note
 * @property string $credit_note_date
 * @property string|null $received_date
 * @property string $currency
 * @property int|null $exchange_rate_bp
 * @property int $subtotal_ht
 * @property int $tax_total
 * @property int $total_ttc
 * @property int $payable_account_id
 * @property SupplierCreditNoteStatus $status
 * @property Carbon|null $posted_at
 * @property int|null $journal_entry_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int $accounting_period_id
 * @property int $created_by
 * @property int $version
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierCreditNote extends Model
{
    private const MUTABLE_AFTER_ISSUE = [
        'status',
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
            'status' => SupplierCreditNoteStatus::class,
            'reason_type' => SupplierCreditNoteReasonType::class,
            'subtotal_ht' => 'integer',
            'tax_total' => 'integer',
            'total_ttc' => 'integer',
            'version' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierCreditNote $creditNote): void {
            /** @var string|SupplierCreditNoteStatus|null $original */
            $original = $creditNote->getOriginal('status');
            $originalStatus = $original instanceof SupplierCreditNoteStatus
                ? $original
                : SupplierCreditNoteStatus::from((string) $original);

            if ($originalStatus !== SupplierCreditNoteStatus::Draft) {
                foreach (array_keys($creditNote->getDirty()) as $column) {
                    if (! in_array($column, self::MUTABLE_AFTER_ISSUE, true)) {
                        throw new RuntimeException(sprintf(
                            'Supplier credit note %s is issued and immutable; [%s] cannot change (03-tax-procurement 4.8).',
                            (string) $creditNote->getOriginal('credit_note_no'),
                            $column,
                        ));
                    }
                }
            }
        });

        static::deleting(function (SupplierCreditNote $creditNote): void {
            if ($creditNote->status !== SupplierCreditNoteStatus::Draft) {
                throw new RuntimeException(sprintf(
                    'Supplier credit note %s is issued and is never deleted (03-tax-procurement 9).',
                    $creditNote->credit_note_no,
                ));
            }
        });
    }

    /**
     * @return HasMany<SupplierCreditNoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<SupplierInvoice, $this>
     */
    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(SupplierInvoice::class, 'original_invoice_id');
    }
}
