<?php

declare(strict_types=1);

namespace App\Modules\Assets\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §6.3 - one quote-part released per (subsidy, asset,
 * run), UNIQUE - the same idempotency shape as the schedule. run NULL only
 * for the disposal-time / clawback write-off of the unreleased balance.
 *
 * @property int $id
 * @property int $investment_subsidy_id
 * @property int $asset_id
 * @property int|null $depreciation_run_id
 * @property int $fiscal_year_id
 * @property int $period_month
 * @property int $amount
 * @property int $journal_entry_id
 */
final class InvestmentSubsidyRelease extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'investment_subsidy_id', 'asset_id', 'depreciation_run_id',
        'fiscal_year_id', 'period_month', 'amount', 'journal_entry_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'investment_subsidy_id' => 'integer',
            'asset_id' => 'integer',
            'depreciation_run_id' => 'integer',
            'fiscal_year_id' => 'integer',
            'period_month' => 'integer',
            'amount' => 'integer',
            'journal_entry_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InvestmentSubsidy, $this>
     */
    public function subsidy(): BelongsTo
    {
        return $this->belongsTo(InvestmentSubsidy::class, 'investment_subsidy_id');
    }
}
