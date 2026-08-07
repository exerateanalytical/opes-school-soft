<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\House;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<House>
 */
class HouseFactory extends Factory
{
    /** @var class-string<House> */
    protected $model = House::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'H-'.Str::upper(Str::random(6)),
            'name' => fake()->randomElement(['Red House', 'Blue House', 'Green House', 'Yellow House']),
            'colour' => fake()->safeColorName(),
            'patron_staff_id' => null,
        ];
    }
}
