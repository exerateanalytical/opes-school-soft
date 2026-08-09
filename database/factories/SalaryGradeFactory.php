<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\SalaryGrade;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SalaryGrade>
 *
 * No salary amount anywhere: grades classify, `staff_compensations` pays
 * (docs/specs/05-hr-payroll.md 5.1).
 */
class SalaryGradeFactory extends Factory
{
    /** @var class-string<SalaryGrade> */
    protected $model = SalaryGrade::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'GR-'.Str::upper(Str::random(6)),
            'name' => 'Grade '.fake()->numberBetween(1, 12),
            'name_fr' => null,
            'category' => (string) fake()->numberBetween(1, 12),
            'echelon' => Str::upper(Str::random(1)),
            'collective_agreement_id' => null,
            'is_active' => true,
        ];
    }
}
