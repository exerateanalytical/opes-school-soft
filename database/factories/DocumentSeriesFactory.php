<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Reporting\Models\DocumentSeries;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSeries>
 */
class DocumentSeriesFactory extends Factory
{
    /** @var class-string<DocumentSeries> */
    protected $model = DocumentSeries::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // lexify keeps codes unique and case-sensitively distinct without
            // colliding with the real catalogue codes (RCPT, BUL, ...).
            'code' => 'S'.fake()->unique()->lexify('???'),
            'format' => '{school}/{year}/{code}/{serial:6}',
            'scope' => 'academic_year',
            'reset_policy' => 'per_academic_year',
            'padding' => 6,
            'is_active' => true,
        ];
    }

    public function global(): self
    {
        return $this->state(fn (): array => [
            'format' => '{school}/{code}/{serial:6}',
            'scope' => 'global',
            'reset_policy' => 'never',
        ]);
    }

    public function fiscalYear(): self
    {
        return $this->state(fn (): array => [
            'scope' => 'fiscal_year',
            'reset_policy' => 'per_fiscal_year',
        ]);
    }
}
