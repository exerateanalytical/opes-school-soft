<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Domain\SanctionType;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineSanction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an unacknowledged single-day warning — the mildest rung, the
 * only shape with no lifecycle side effects.
 *
 * @extends Factory<DisciplineSanction>
 */
class DisciplineSanctionFactory extends Factory
{
    /** @var class-string<DisciplineSanction> */
    protected $model = DisciplineSanction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'discipline_case_id' => DisciplineCase::factory(),
            'type' => SanctionType::Warning,
            'starts_on' => '2026-09-11',
            'ends_on' => null,
            'applied_by' => User::factory(),
            'acknowledged_at' => null,
            'notes' => null,
        ];
    }
}
