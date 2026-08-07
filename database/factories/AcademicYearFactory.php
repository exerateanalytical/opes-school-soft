<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Domain\AcademicYearStatus;
use App\Modules\Academics\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Each factory row is a self-consistent September-August year. Distinct rows
 * are NOT automatically contiguous - tests exercising the gapless invariant
 * build years explicitly through CreateAcademicYear.
 *
 * @extends Factory<AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    /** @var class-string<AcademicYear> */
    protected $model = AcademicYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = fake()->unique()->numberBetween(2000, 2400);

        return [
            'code' => sprintf('%d-%d', $start, $start + 1),
            'name' => sprintf('Academic Year %d/%d', $start, $start + 1),
            'starts_on' => sprintf('%d-09-01', $start),
            'ends_on' => sprintf('%d-08-31', $start + 1),
            'is_current' => false,
            'status' => AcademicYearStatus::Planned,
        ];
    }

    public function current(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_current' => true,
            'status' => AcademicYearStatus::Active,
        ]);
    }
}
