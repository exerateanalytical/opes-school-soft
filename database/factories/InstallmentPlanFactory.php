<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Fees\Domain\InstallmentBasis;
use App\Modules\Fees\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InstallmentPlan>
 */
class InstallmentPlanFactory extends Factory
{
    /** @var class-string<InstallmentPlan> */
    protected $model = InstallmentPlan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name' => 'Plan '.fake()->unique()->numberBetween(1, 99999),
            'fee_structure_id' => InstallmentPlan::GLOBAL,
            'basis' => InstallmentBasis::Percentage,
            'is_default' => false,
        ];
    }
}
