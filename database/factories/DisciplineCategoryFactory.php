<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Models\DisciplineCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an active minor offence with a warning as the suggested
 * sanction — the commonest catalogue row.
 *
 * @extends Factory<DisciplineCategory>
 */
class DisciplineCategoryFactory extends Factory
{
    /** @var class-string<DisciplineCategory> */
    protected $model = DisciplineCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Offence '.fake()->unique()->lexify('??????'),
            'name_fr' => 'Faute '.fake()->lexify('??????'),
            'severity' => 1,
            'default_sanction_type' => SanctionType::Warning,
            'is_active' => true,
        ];
    }

    public function grave(): static
    {
        return $this->state(fn (): array => [
            'severity' => 5,
            'default_sanction_type' => SanctionType::Suspension,
        ]);
    }
}
