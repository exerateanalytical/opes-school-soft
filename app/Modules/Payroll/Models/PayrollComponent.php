<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Models;

use App\Modules\Payroll\Domain\ComponentBasis;
use App\Modules\Payroll\Domain\ComponentCalculation;
use App\Modules\Payroll\Domain\ComponentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A payroll component (docs/specs/05-hr-payroll.md 5.2). Statutory
 * components carry a `statutory_rate_code` and NO amount - the rate
 * resolves period-END dated through StatutoryRateResolver. Formula
 * components carry a 5.4-grammar expression parsed at save; each must have
 * at least one passing stored unit test before it can be enabled.
 *
 * `is_system` rows (the 5.3 set) cannot be deleted or reordered.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property ComponentType $type
 * @property ComponentCalculation $calculation
 * @property ComponentBasis|null $basis
 * @property string|null $statutory_rate_code
 * @property string|null $formula_expression
 * @property int $calculation_order
 * @property list<string> $depends_on
 * @property bool $is_taxable
 * @property bool $is_cnps_liable
 * @property bool $is_prorated
 * @property bool $subject_to_deduction_cap
 * @property int|null $expense_account_id
 * @property int|null $liability_account_id
 * @property string $analytic_axis_behaviour
 * @property string|null $print_group
 * @property int $print_order
 * @property bool $is_enabled
 * @property bool $is_system
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PayrollComponent extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'name_fr',
        'type',
        'calculation',
        'basis',
        'statutory_rate_code',
        'formula_expression',
        'calculation_order',
        'depends_on',
        'is_taxable',
        'is_cnps_liable',
        'is_prorated',
        'subject_to_deduction_cap',
        'expense_account_id',
        'liability_account_id',
        'analytic_axis_behaviour',
        'print_group',
        'print_order',
        'is_enabled',
        'is_system',
        'effective_from',
        'effective_to',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'type' => ComponentType::class,
            'calculation' => ComponentCalculation::class,
            'basis' => ComponentBasis::class,
            'calculation_order' => 'integer',
            'depends_on' => 'array',
            'is_taxable' => 'boolean',
            'is_cnps_liable' => 'boolean',
            'is_prorated' => 'boolean',
            'subject_to_deduction_cap' => 'boolean',
            'print_order' => 'integer',
            'is_enabled' => 'boolean',
            'is_system' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'version' => 'integer',
        ];
    }

    /**
     * @return HasMany<PayrollComponentTest, $this>
     */
    public function storedTests(): HasMany
    {
        return $this->hasMany(PayrollComponentTest::class);
    }
}
