<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Models\FiscalYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Each row is a self-consistent calendar year. Distinct rows are NOT
 * automatically contiguous - tests exercising that invariant build years
 * through CreateFiscalYear, mirroring AcademicYearFactory's convention.
 *
 * @extends Factory<FiscalYear>
 */
class FiscalYearFactory extends Factory
{
    /** @var class-string<FiscalYear> */
    protected $model = FiscalYear::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2000, 2400);

        return [
            'code' => (string) $year,
            'starts_on' => sprintf('%d-01-01', $year),
            'ends_on' => sprintf('%d-12-31', $year),
            'status' => FiscalYearStatus::Planned,
            'is_first_exercice' => false,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes): array => ['status' => FiscalYearStatus::Open]);
    }
}
