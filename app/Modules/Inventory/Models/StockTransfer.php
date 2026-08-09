<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\StockTransferStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.9 - a transfer between two locations
 * mapping to the same stock account is NOT a ledger event; the Action
 * posts only on a stock-account difference.
 *
 * @property int $id
 * @property string $transfer_no
 * @property int $from_location_id
 * @property int $to_location_id
 * @property StockTransferStatus $status
 * @property Carbon $transferred_on
 * @property int|null $journal_entry_id
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int $created_by
 * @property string|null $idempotency_key
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockTransfer extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'transfer_no', 'from_location_id', 'to_location_id', 'status',
        'transferred_on', 'journal_entry_id', 'fiscal_year_id',
        'academic_year_id', 'created_by', 'idempotency_key', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StockTransferStatus::class,
            'transferred_on' => 'date',
        ];
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }
}
