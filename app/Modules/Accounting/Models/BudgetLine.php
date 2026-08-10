<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * docs/specs/02-accounting.md §16. One budgeted account (optionally narrowed
 * to one analytic value) inside one budget.
 *
 * `analytic_key` is the STORED generated sentinel that makes
 * `UNIQUE(budget_id, account_id, analytic_value_id)` actually unique for the
 * NULL-analytic case - see the migration. It is never fillable.
 *
 * @property int $id
 * @property int $budget_id
 * @property int $account_id
 * @property int|null $analytic_value_id
 * @property int $analytic_key
 * @property int $annual_amount
 * @property string|null $notes
 */
final class BudgetLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'budget_id',
        'account_id',
        'analytic_value_id',
        'annual_amount',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_amount' => 'integer',
            'analytic_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }

    /**
     * @return BelongsTo<AnalyticValue, $this>
     */
    public function analyticValue(): BelongsTo
    {
        return $this->belongsTo(AnalyticValue::class);
    }

    /**
     * @return HasMany<BudgetPhasing, $this>
     */
    public function phasings(): HasMany
    {
        return $this->hasMany(BudgetPhasing::class)->orderBy('period_month');
    }
}
