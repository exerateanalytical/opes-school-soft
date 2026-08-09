<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payroll\Models\Commune;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commune>
 */
class CommuneFactory extends Factory
{
    /** @var class-string<Commune> */
    protected $model = Commune::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Commune de '.fake()->unique()->city(),
            'region' => 'Centre',
            'is_active' => true,
        ];
    }
}
