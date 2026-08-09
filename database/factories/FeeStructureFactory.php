<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Fees\Domain\BoardingScope;
use App\Modules\Fees\Domain\EnrollmentStatusScope;
use App\Modules\Fees\Domain\FeeStructureStatus;
use App\Modules\Fees\Models\FeeStructure;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Scope sentinels default to 0 = "any level / any stream" (04-fees §2.5).
 *
 * @extends Factory<FeeStructure>
 */
class FeeStructureFactory extends Factory
{
    /** @var class-string<FeeStructure> */
    protected $model = FeeStructure::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'school_section_id' => SchoolSection::factory(),
            'class_level_id' => FeeStructure::ANY,
            'stream_id' => FeeStructure::ANY,
            'enrollment_status_scope' => EnrollmentStatusScope::Any,
            'boarding_scope' => BoardingScope::Any,
            'name' => 'Structure '.fake()->unique()->numberBetween(1, 99999),
            'status' => FeeStructureStatus::Draft,
            'version' => 1,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['status' => FeeStructureStatus::Active]);
    }
}
