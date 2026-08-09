<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use App\Modules\Library\Domain\AcquisitionSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 06-assets-stores.md §10.8 - the acquisition batch. Under the default
 * `expensed` policy this is a memorandum record (the purchase expense is
 * the supplier invoice's posting); under `capitalised` (HARD-GATED on
 * V17) it would carry the batch Asset and its capitalisation entry.
 *
 * @property int $id
 * @property string $reference
 * @property int|null $supplier_id
 * @property int|null $supplier_invoice_id
 * @property string $acquired_on
 * @property AcquisitionSource $source
 * @property int $total_cost
 * @property int $copy_count
 * @property int|null $journal_entry_id
 * @property int|null $asset_id
 * @property string|null $notes
 * @property int $recorded_by
 * @property string|null $idempotency_key
 */
final class BookAcquisition extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'supplier_id', 'supplier_invoice_id', 'acquired_on',
        'source', 'total_cost', 'copy_count', 'journal_entry_id', 'asset_id',
        'notes', 'recorded_by', 'idempotency_key',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'supplier_invoice_id' => 'integer',
            'source' => AcquisitionSource::class,
            'total_cost' => 'integer',
            'copy_count' => 'integer',
            'journal_entry_id' => 'integer',
            'asset_id' => 'integer',
            'recorded_by' => 'integer',
        ];
    }

    /**
     * @return HasMany<BookCopy, $this>
     */
    public function copies(): HasMany
    {
        return $this->hasMany(BookCopy::class, 'acquisition_id');
    }
}
