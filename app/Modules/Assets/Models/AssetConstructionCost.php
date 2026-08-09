<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §3 - append-only construction-cost accumulation for
 * in-progress assets. Schema triggers reject UPDATE and DELETE outright.
 *
 * @property int $id
 * @property int $asset_id
 * @property int|null $supplier_invoice_id
 * @property int|null $journal_entry_id
 * @property int $amount
 * @property string $incurred_on
 * @property string $description
 * @property string|null $document_ref
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int $recorded_by
 * @property string|null $idempotency_key
 */
final class AssetConstructionCost extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'supplier_invoice_id', 'journal_entry_id', 'amount',
        'incurred_on', 'description', 'document_ref', 'fiscal_year_id',
        'academic_year_id', 'recorded_by', 'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'supplier_invoice_id' => 'integer',
            'journal_entry_id' => 'integer',
            'amount' => 'integer',
            'fiscal_year_id' => 'integer',
            'academic_year_id' => 'integer',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
