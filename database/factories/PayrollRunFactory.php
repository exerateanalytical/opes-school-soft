<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayrollRun>
 *
 * Fixture-only: real runs are born through CalculatePayrollRun, which
 * derives the calendar links from the payroll month. This factory exists
 * for tests that need a run row to simply exist (e.g. the preflight
 * results table's FK).
 */
class PayrollRunFactory extends Factory
{
    /** @var class-string<PayrollRun> */
    protected $model = PayrollRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payroll_month' => '2031-01-01',
            'run_type' => 'regular',
            'status' => 'draft',
            'fiscal_year_id' => FiscalYear::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'accounting_period_id' => AccountingPeriod::factory(),
            'employer_profile_id' => EmployerProfile::factory(),
            'inputs_hash' => null,
            'idempotency_key' => null,
            'version' => 0,
        ];
    }
}
