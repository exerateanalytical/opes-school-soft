<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use App\Modules\Assets\Domain\DepreciationBasis;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Support\Retention\Immutable10Year;

/**
 * 06-assets-stores.md §4.2 - one row per asset per period per basis.
 * `charge` is SIGNED (§5.5). Rows are written once by RunDepreciation (or
 * DisposeAsset's §4.5 depreciate-to-date, run_id NULL) and only ever
 * touched again to stamp journal_entry_id at posting.
 *
 * @property int $id
 * @property int $asset_id
 * @property int|null $depreciation_run_id
 * @property int $fiscal_year_id
 * @property int $period_month
 * @property DepreciationBasis $basis
 * @property int $opening_accumulated
 * @property int $charge
 * @property int $closing_accumulated
 * @property int $net_book_value
 * @property int $depreciable_base
 * @property int $months_elapsed
 * @property bool $is_catch_up
 * @property int|null $journal_entry_id
 */
final class DepreciationSchedule extends Model
{
    use Immutable10Year;
    /** @var list<string> */
    protected $fillable = [
        'asset_id', 'depreciation_run_id', 'fiscal_year_id', 'period_month',
        'basis', 'opening_accumulated', 'charge', 'closing_accumulated',
        'net_book_value', 'depreciable_base', 'months_elapsed', 'is_catch_up',
        'journal_entry_id',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'asset_id' => 'integer',
            'depreciation_run_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'period_month' => 'integer',
            'basis' => DepreciationBasis::class,
            'opening_accumulated' => 'integer',
            'charge' => 'integer',
            'closing_accumulated' => 'integer',
            'net_book_value' => 'integer',
            'depreciable_base' => 'integer',
            'months_elapsed' => 'integer',
            'is_catch_up' => 'boolean',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<DepreciationRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }
}
