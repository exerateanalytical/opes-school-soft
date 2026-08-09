<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One component's line on one payslip (docs/specs/05-hr-payroll.md 10.2).
 * Base AND rate ride on the line because the payslip must legally print
 * each deduction with both (14.1); `statutory_rate_id` is the provenance
 * link to the exact rate row that produced the amount (H1).
 *
 * @property int $id
 * @property int $payroll_item_id
 * @property int $payroll_component_id
 * @property int|null $statutory_rate_id
 * @property int $base_amount
 * @property int|null $applied_rate_bp
 * @property int|null $applied_flat_amount
 * @property list<array<string, mixed>>|null $bracket_detail
 * @property int $amount
 * @property Carbon|null $arrears_for_month
 * @property Carbon $arrears_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_item_id',
        'payroll_component_id',
        'statutory_rate_id',
        'base_amount',
        'applied_rate_bp',
        'applied_flat_amount',
        'bracket_detail',
        'amount',
        'arrears_for_month',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_amount' => 'integer',
            'applied_rate_bp' => 'integer',
            'applied_flat_amount' => 'integer',
            'bracket_detail' => 'array',
            'amount' => 'integer',
            'arrears_for_month' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PayrollItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    /**
     * @return BelongsTo<PayrollComponent, $this>
     */
    public function component(): BelongsTo
    {
        return $this->belongsTo(PayrollComponent::class, 'payroll_component_id');
    }

    /**
     * @return BelongsTo<StatutoryRate, $this>
     */
    public function statutoryRate(): BelongsTo
    {
        return $this->belongsTo(StatutoryRate::class);
    }
}
