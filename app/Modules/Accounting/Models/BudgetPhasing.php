<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §16. One month of one budget line.
 *
 * `period_month` is the first day of the month, matching
 * `accounting_periods.period_month` exactly - Budget-vs-Actual joins the two
 * on that equality, so a mid-month date would silently read as a zero budget.
 * The migration's `ck_budget_phasings_month_start` refuses it.
 *
 * B-1 (Σ amount = the line's annual_amount) is maintained by
 * `Actions\ApplyBudgetPhasing`, which rewrites the whole set for a line in
 * one transaction.
 *
 * @property int $id
 * @property int $budget_line_id
 * @property Carbon $period_month
 * @property int $amount
 */
final class BudgetPhasing extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'budget_line_id',
        'period_month',
        'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_month' => 'date',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BudgetLine, $this>
     */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }
}
