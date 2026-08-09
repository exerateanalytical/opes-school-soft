<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.3 - the bon de reception.
 *
 * A receipt POSTS NOTHING: recognition happens on the invoice or at cut-off
 * via 4818 (§3.3), never here. Once confirmed the document is frozen - the
 * PO line counters it advanced under lock cannot be silently rewritten by
 * editing the receipt afterwards.
 *
 * @property int $id
 * @property string $receipt_no
 * @property int|null $purchase_order_id
 * @property int $supplier_id
 * @property string $received_on
 * @property int $received_by
 * @property string|null $delivery_note_ref
 * @property int|null $store_location_id
 * @property GoodsReceiptStatus $status
 * @property bool $has_discrepancy
 * @property int $academic_year_id
 * @property int $fiscal_year_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class GoodsReceipt extends Model
{
    private const MUTABLE_AFTER_CONFIRM = ['status', 'updated_at'];

    /** @var list<string> */
    protected $fillable = [
        'receipt_no',
        'purchase_order_id',
        'supplier_id',
        'received_on',
        'received_by',
        'delivery_note_ref',
        'store_location_id',
        'status',
        'has_discrepancy',
        'academic_year_id',
        'fiscal_year_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => GoodsReceiptStatus::class,
            'has_discrepancy' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (GoodsReceipt $receipt): void {
            /** @var string|GoodsReceiptStatus|null $original */
            $original = $receipt->getOriginal('status');
            $originalStatus = $original instanceof GoodsReceiptStatus
                ? $original
                : GoodsReceiptStatus::from((string) $original);

            if ($originalStatus === GoodsReceiptStatus::Draft) {
                return;
            }

            foreach (array_keys($receipt->getDirty()) as $column) {
                if (! in_array($column, self::MUTABLE_AFTER_CONFIRM, true)) {
                    throw new RuntimeException(sprintf(
                        'Goods receipt %s is confirmed and immutable; column [%s] cannot change. '
                        .'A wrong receipt is resolved by credit note or PO amendment (03-tax-procurement 4.3).',
                        (string) $receipt->getOriginal('receipt_no'),
                        $column,
                    ));
                }
            }
        });

        static::deleting(function (GoodsReceipt $receipt): void {
            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw new RuntimeException(sprintf(
                    'Goods receipt %s has left draft and can only be cancelled, never deleted (03-tax-procurement 9).',
                    $receipt->receipt_no,
                ));
            }
        });
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('line_no');
    }
}
