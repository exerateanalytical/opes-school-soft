<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §3.3 - one 4818 working-paper row per
 * goods-receipt line accrued by the year-end cut-off run, valued at PO
 * price, carrying both the accrual entry and its first-day reversal.
 *
 * @property int $id
 * @property int $fiscal_year_id
 * @property int $goods_receipt_line_id
 * @property int $supplier_id
 * @property string $quantity
 * @property int $amount_ht
 * @property int $expense_account_id
 * @property int $accrual_account_id
 * @property int $journal_entry_id
 * @property int|null $reversal_journal_entry_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseAccrual extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_ht' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (PurchaseAccrual $accrual): void {
            throw new RuntimeException(
                'A purchase accrual is a working paper behind posted entries and is never deleted (02-accounting C5).'
            );
        });
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class, 'goods_receipt_line_id');
    }
}
