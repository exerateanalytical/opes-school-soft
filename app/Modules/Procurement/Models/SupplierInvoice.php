<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\MatchStatus;
use App\Modules\Procurement\Domain\SupplierInvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.5 - the facture fournisseur: the one
 * P2P document that is never optional, because it creates the payable and
 * the expense.
 *
 * Immutability: once POSTED, the commercial substance is frozen by the
 * observer - only the payment-lifecycle columns may move (status,
 * cancellation stamps, version). The single door for later money movement
 * is a SupplierCreditNote or F4's payment allocations, never an edit here.
 *
 * Deletion (§9): RESTRICT once out of draft - observer + the BEFORE DELETE
 * trigger both enforce it, so no console command or direct SQL can slip a
 * posted invoice out of the 10-year AUDCIF retention.
 *
 * @property int $id
 * @property string $internal_no
 * @property string $supplier_invoice_no
 * @property int $supplier_id
 * @property int|null $purchase_order_id
 * @property string $invoice_date
 * @property string $received_date
 * @property string $value_date
 * @property string $due_date
 * @property string $currency
 * @property int|null $exchange_rate_bp
 * @property int $subtotal_ht
 * @property int $discount_total
 * @property int $tax_total
 * @property int $total_ttc
 * @property int $withholding_total
 * @property int $net_payable
 * @property int $retention_amount
 * @property int $payable_account_id
 * @property SupplierInvoiceStatus $status
 * @property MatchStatus $match_status
 * @property string|null $match_override_reason
 * @property int|null $match_override_by
 * @property Carbon|null $match_override_at
 * @property string|null $unmatched_reason
 * @property bool $withholding_unresolved
 * @property string|null $withholding_waived_reason
 * @property int|null $withholding_waived_by
 * @property Carbon|null $withholding_waived_at
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $posted_at
 * @property int|null $journal_entry_id
 * @property int|null $secondary_journal_entry_id
 * @property int|null $withholding_journal_entry_id
 * @property int|null $cancelled_by
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property bool $is_migration
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int $accounting_period_id
 * @property int|null $document_id
 * @property int $version
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SupplierInvoice extends Model
{
    /**
     * Lifecycle columns the observer lets move after posting; everything
     * else is the commercial substance of the invoice and frozen.
     */
    private const MUTABLE_AFTER_POSTING = [
        'status',
        'cancelled_by',
        'cancelled_at',
        'cancellation_reason',
        'document_id',
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
            'status' => SupplierInvoiceStatus::class,
            'match_status' => MatchStatus::class,
            'subtotal_ht' => 'integer',
            'discount_total' => 'integer',
            'tax_total' => 'integer',
            'total_ttc' => 'integer',
            'withholding_total' => 'integer',
            'net_payable' => 'integer',
            'retention_amount' => 'integer',
            'withholding_unresolved' => 'boolean',
            'is_migration' => 'boolean',
            'version' => 'integer',
            'match_override_at' => 'datetime',
            'withholding_waived_at' => 'datetime',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (SupplierInvoice $invoice): void {
            /** @var string|SupplierInvoiceStatus|null $original */
            $original = $invoice->getOriginal('status');
            $originalStatus = $original instanceof SupplierInvoiceStatus
                ? $original
                : SupplierInvoiceStatus::from((string) $original);

            // The freeze arms at the moment of posting: journal_entry_id and
            // posted_at are stamped in the SAME save that flips the status,
            // so the ORIGINAL (pre-save) status is the arming condition.
            if (in_array($originalStatus, [
                SupplierInvoiceStatus::Posted,
                SupplierInvoiceStatus::PartiallyPaid,
                SupplierInvoiceStatus::Paid,
                SupplierInvoiceStatus::Cancelled,
            ], true)) {
                foreach (array_keys($invoice->getDirty()) as $column) {
                    if (! in_array($column, self::MUTABLE_AFTER_POSTING, true)) {
                        throw new RuntimeException(sprintf(
                            'Supplier invoice %s is posted and immutable; [%s] can only change through a '
                            .'credit note or a payment (03-tax-procurement 4.5).',
                            (string) $invoice->getOriginal('internal_no'),
                            $column,
                        ));
                    }
                }
            }
        });

        static::deleting(function (SupplierInvoice $invoice): void {
            if ($invoice->status !== SupplierInvoiceStatus::Draft) {
                throw new RuntimeException(sprintf(
                    'Supplier invoice %s has left draft and can only be cancelled, never deleted (03-tax-procurement 9).',
                    $invoice->internal_no,
                ));
            }
        });
    }

    /**
     * @return HasMany<SupplierInvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class)->orderBy('line_no');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
