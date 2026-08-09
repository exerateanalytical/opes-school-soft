<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\StockTakeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.10 - physical inventory with freeze
 * semantics and segregated approval (approved_by <> counted_by).
 *
 * @property int $id
 * @property string $reference
 * @property int $store_location_id
 * @property bool $is_full_count
 * @property Carbon $count_date
 * @property StockTakeStatus $status
 * @property int|null $counted_by
 * @property int|null $verified_by
 * @property int|null $approved_by
 * @property int $fiscal_year_id
 * @property int $academic_year_id
 * @property int|null $journal_entry_id
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StockTake extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'reference', 'store_location_id', 'is_full_count', 'count_date',
        'status', 'counted_by', 'verified_by', 'approved_by',
        'fiscal_year_id', 'academic_year_id', 'journal_entry_id', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_full_count' => 'boolean',
            'count_date' => 'date',
            'status' => StockTakeStatus::class,
        ];
    }

    /**
     * @return HasMany<StockTakeLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTakeLine::class);
    }
}
