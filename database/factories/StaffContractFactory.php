<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\Department;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffContract>
 *
 * Prefer App\Modules\HR\Actions\OpenStaffContract in feature tests that
 * exercise the invariants - this factory bypasses the CDD chain and overlap
 * checks by design, for fixtures that need a contract to simply exist.
 */
class StaffContractFactory extends Factory
{
    /** @var class-string<StaffContract> */
    protected $model = StaffContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'staff_member_id' => StaffMember::factory(),
            'contract_role' => 'teaching',
            'contract_type' => 'cdi',
            'working_time' => 'full_time',
            'department_id' => Department::factory(),
            'position_id' => Position::factory(),
            'salary_grade_id' => null,
            'collective_agreement_id' => null,
            'category' => '9',
            'echelon' => 'A',
            'starts_on' => '2026-01-01',
            'ends_on' => null,
            'probation_end' => null,
            'renewal_count' => 0,
            'renewed_from_contract_id' => null,
            'converted_to_cdi_on' => null,
            'mintss_visa_ref' => null,
            'social_security_status' => 'affilie_cnps',
            'is_payroll_eligible' => true,
            'rp_risk_class_override' => null,
            'override_reason' => null,
            'seniority_reference_date' => '2026-01-01',
            'termination_reason' => null,
        ];
    }

    public function cdd(string $startsOn = '2026-01-01', string $endsOn = '2027-01-01'): self
    {
        return $this->state(fn (array $attributes): array => [
            'contract_type' => 'cdd',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'seniority_reference_date' => $startsOn,
        ]);
    }

    public function hourly(): self
    {
        return $this->state(fn (array $attributes): array => [
            'working_time' => 'hourly',
        ]);
    }
}
