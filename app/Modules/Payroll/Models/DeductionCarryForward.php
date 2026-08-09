<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The un-deducted excess of a capped deduction (docs/specs/05-hr-payroll.md
 * 5.7): carried forward and re-presented next month, never silently
 * dropped - dropping it would make a loan un-repayable.
 *
 * @property int $id
 * @property int $staff_contract_id
 * @property string $source_component_code
 * @property int $amount
 * @property Carbon $created_from_payroll_month
 * @property Carbon|null $settled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class DeductionCarryForward extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'staff_contract_id',
        'source_component_code',
        'amount',
        'created_from_payroll_month',
        'settled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'created_from_payroll_month' => 'date',
            'settled_at' => 'datetime',
        ];
    }
}
