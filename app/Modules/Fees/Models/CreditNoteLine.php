<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * docs/specs/04-fees.md §9. Line-level (A2): every credited franc names the
 * invoice line it relieves, or reconciliation is impossible.
 *
 * @property int $id
 * @property int $credit_note_id
 * @property int $invoice_line_id
 * @property string $description
 * @property int $amount
 * @property int $tax_amount
 * @property int|null $revenue_account_id
 * @property string $collection_basis own_revenue|agent_for_third_party
 */
final class CreditNoteLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'credit_note_id',
        'invoice_line_id',
        'description',
        'amount',
        'tax_amount',
        'revenue_account_id',
        'collection_basis',
    ];

    /** @return BelongsTo<CreditNote, $this> */
    public function creditNote(): BelongsTo
    {
        return $this->belongsTo(CreditNote::class);
    }

    /** @return BelongsTo<InvoiceLine, $this> */
    public function invoiceLine(): BelongsTo
    {
        return $this->belongsTo(InvoiceLine::class);
    }
}
