<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.2 - the bon de commande.
 *
 * Invariant 5: an APPROVED PO is immutable. The observer freezes every
 * commercial column once the ORIGINAL status has left the pre-approval
 * states; only the lifecycle columns (status, sent_at, closed_reason,
 * version, approval stamps) may move. The single legitimate way to change
 * the line set or totals afterwards is AmendPurchaseOrder, which snapshots
 * the prior lines into `purchase_order_amendments` and then writes inside
 * the amendment window opened here - the same booted()-observer pattern
 * Payment uses, so no console command or queued job can slip past it.
 *
 * Invariant 6: a PO POSTS NOTHING to the ledger. No journal_entry_id
 * exists on this table, and no Action in this module may hand a PO to
 * PostFromEvent - PurchaseOrderTest asserts the whole chain leaves
 * journal_entries untouched.
 *
 * @property int $id
 * @property string $po_no
 * @property int $supplier_id
 * @property int|null $requisition_id
 * @property string $order_date
 * @property string|null $expected_delivery_date
 * @property string|null $delivery_address
 * @property string $currency
 * @property int|null $exchange_rate_bp
 * @property int $subtotal_ht
 * @property int $tax_total
 * @property int $total_ttc
 * @property int $retention_rate_bp
 * @property string|null $retention_release_due_on
 * @property PurchaseOrderStatus $status
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $sent_at
 * @property string|null $closed_reason
 * @property int $payable_account_id
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property int $version
 * @property string|null $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseOrder extends Model
{
    /**
     * Lifecycle columns the observer lets move after approval; everything
     * else is the commercial substance of the order and frozen.
     */
    private const MUTABLE_AFTER_APPROVAL = [
        'status',
        'approved_by',
        'approved_at',
        'sent_at',
        'closed_reason',
        'version',
        'updated_at',
    ];

    private static bool $amendmentWindowOpen = false;

    /** @var list<string> */
    protected $fillable = [
        'po_no',
        'supplier_id',
        'requisition_id',
        'order_date',
        'expected_delivery_date',
        'delivery_address',
        'currency',
        'exchange_rate_bp',
        'subtotal_ht',
        'tax_total',
        'total_ttc',
        'retention_rate_bp',
        'retention_release_due_on',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
        'sent_at',
        'closed_reason',
        'payable_account_id',
        'academic_year_id',
        'fiscal_year_id',
        'version',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'subtotal_ht' => 'integer',
            'tax_total' => 'integer',
            'total_ttc' => 'integer',
            'retention_rate_bp' => 'integer',
            'version' => 'integer',
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseOrder $po): void {
            /** @var string|PurchaseOrderStatus|null $original */
            $original = $po->getOriginal('status');
            $originalStatus = $original instanceof PurchaseOrderStatus
                ? $original
                : PurchaseOrderStatus::from((string) $original);

            if ($originalStatus->isPreApproval() || self::$amendmentWindowOpen) {
                return;
            }

            foreach (array_keys($po->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE_AFTER_APPROVAL, true)) {
                    throw new RuntimeException(sprintf(
                        'Purchase order %s is approved and immutable; column [%s] changes only through '
                        .'a PurchaseOrderAmendment (03-tax-procurement 4.2 invariant 5).',
                        (string) $po->getOriginal('po_no'),
                        $column,
                    ));
                }
            }
        });

        static::deleting(function (PurchaseOrder $po): void {
            if ($po->status !== PurchaseOrderStatus::Draft) {
                throw new RuntimeException(sprintf(
                    'Purchase order %s has left draft and can only be cancelled, never deleted (03-tax-procurement 9).',
                    $po->po_no,
                ));
            }
        });
    }

    /**
     * The ONLY door through the invariant-5 freeze. AmendPurchaseOrder
     * snapshots the prior line set first, then runs its rewrite inside this
     * window; the finally-block guarantees the freeze is restored even when
     * the amendment itself throws.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withinAmendmentWindow(callable $callback): mixed
    {
        self::$amendmentWindowOpen = true;

        try {
            return $callback();
        } finally {
            self::$amendmentWindowOpen = false;
        }
    }

    public static function amendmentWindowIsOpen(): bool
    {
        return self::$amendmentWindowOpen;
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_no');
    }

    /**
     * @return HasMany<PurchaseOrderAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(PurchaseOrderAmendment::class)->orderBy('amendment_no');
    }
}
