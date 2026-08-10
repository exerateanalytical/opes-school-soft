<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One charge on an expense voucher (docs/specs/02-accounting.md §21.3):
 * an account (class 6 operating, or class 2 when the purchase is capex), an
 * optional analytic value, an optional tax code, and a strictly positive
 * amount - the sign is fixed by the CHECK constraint so a negative "credit"
 * line can never hide inside an expense.
 *
 * @property int $id
 * @property int $expense_id
 * @property int $line_no
 * @property string $label
 * @property int $account_id
 * @property int|null $analytic_value_id
 * @property int|null $tax_code_id
 * @property int $amount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ExpenseLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'expense_id', 'line_no', 'label',
        'account_id', 'analytic_value_id', 'tax_code_id', 'amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expense_id' => 'integer',
            'line_no' => 'integer',
            'account_id' => 'integer',
            'analytic_value_id' => 'integer',
            'tax_code_id' => 'integer',
            'amount' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * @return BelongsTo<ChartOfAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(ChartOfAccount::class, 'account_id');
    }
}
