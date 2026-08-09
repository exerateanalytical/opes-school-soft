<?php

declare(strict_types=1);

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The analytic split of one contract's employment cost
 * (docs/specs/05-hr-payroll.md 3.7).
 *
 * Invariant: the rows in force over any effective date sum to exactly 100% -
 * `App\Support\Rate\Rate::SCALE` basis points - per contract, asserted in
 * SaveCostAllocation. Statutory amounts are computed employer-wide FIRST
 * (H3); cost allocation happens downstream using Money's largest-remainder
 * Allocator so the parts sum exactly to the computed cost.
 *
 * `analytic_value_id` is a plain FK attribute: AnalyticValue belongs to
 * Accounting and is read cross-module via DB::table only.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property int $analytic_value_id
 * @property int $percentage_bp
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StaffCostAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'analytic_value_id',
        'percentage_bp',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'analytic_value_id' => 'integer',
            'percentage_bp' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<StaffContract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(StaffContract::class, 'staff_contract_id');
    }

    /**
     * @param  Builder<StaffCostAllocation>  $query
     * @return Builder<StaffCostAllocation>
     */
    public function scopeInForceOn(Builder $query, string $date): Builder
    {
        return $query
            ->where('effective_from', '<=', $date)
            ->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $date);
            });
    }
}
