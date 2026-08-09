<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One staff member's month (docs/specs/05-hr-payroll.md 10.2) - the live
 * row, mutable only while its run is draft/calculating. The two CNPS bases
 * are the N1 fix; `irpp_amount` is CAC's basis; the cross-run
 * UNIQUE(active_month, staff_member_id) makes double payment structurally
 * impossible while letting a reversed month re-run.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $staff_member_id
 * @property int $staff_contract_id
 * @property Carbon $payroll_month
 * @property bool $is_cancelled
 * @property string $days_worked
 * @property string $days_in_period
 * @property string|null $hours_validated
 * @property int $gross
 * @property int $sbt
 * @property int $cnps_capped_base
 * @property int $cnps_uncapped_base
 * @property int $taxable_base
 * @property int $irpp_amount
 * @property int $total_employee_deductions
 * @property int $total_employer_charges
 * @property int $net
 * @property int $ytd_sbt
 * @property int $ytd_irpp_withheld
 * @property array<string, mixed> $exception_flags
 * @property Carbon|null $active_month
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollItem extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'payroll_run_id',
        'staff_member_id',
        'staff_contract_id',
        'payroll_month',
        'is_cancelled',
        'days_worked',
        'days_in_period',
        'hours_validated',
        'gross',
        'sbt',
        'cnps_capped_base',
        'cnps_uncapped_base',
        'taxable_base',
        'irpp_amount',
        'total_employee_deductions',
        'total_employer_charges',
        'net',
        'ytd_sbt',
        'ytd_irpp_withheld',
        'exception_flags',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payroll_month' => 'date',
            'is_cancelled' => 'boolean',
            'gross' => 'integer',
            'sbt' => 'integer',
            'cnps_capped_base' => 'integer',
            'cnps_uncapped_base' => 'integer',
            'taxable_base' => 'integer',
            'irpp_amount' => 'integer',
            'total_employee_deductions' => 'integer',
            'total_employer_charges' => 'integer',
            'net' => 'integer',
            'ytd_sbt' => 'integer',
            'ytd_irpp_withheld' => 'integer',
            'exception_flags' => 'array',
            'active_month' => 'date',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * @return HasOne<PayrollItemSnapshot, $this>
     */
    public function snapshot(): HasOne
    {
        return $this->hasOne(PayrollItemSnapshot::class);
    }
}
