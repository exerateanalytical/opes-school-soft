<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/03-tax-procurement.md §4.2 invariant 5 - the audited record of
 * a change to an approved PO: reason, actor, and a JSON snapshot of the
 * line set AS IT WAS before the amendment applied, so the history replays.
 *
 * Append-only: an amendment is itself part of the audit trail, so it is
 * never edited or deleted once written.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $amendment_no
 * @property string $reason
 * @property array<int, array<string, mixed>> $previous_lines
 * @property int $previous_subtotal_ht
 * @property int $previous_total_ttc
 * @property int $amended_by
 * @property Carbon $amended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PurchaseOrderAmendment extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id',
        'amendment_no',
        'reason',
        'previous_lines',
        'previous_subtotal_ht',
        'previous_total_ttc',
        'amended_by',
        'amended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_lines' => 'array',
            'previous_subtotal_ht' => 'integer',
            'previous_total_ttc' => 'integer',
            'amended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('A PO amendment is part of the audit trail and is never edited.');
        });

        static::deleting(function (): void {
            throw new RuntimeException('A PO amendment is part of the audit trail and is never deleted.');
        });
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
