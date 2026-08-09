<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Library\Models\ShelfLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShelfLocation>
 */
class ShelfLocationFactory extends Factory
{
    /** @var class-string<ShelfLocation> */
    protected $model = ShelfLocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'SH'.fake()->unique()->numberBetween(1, 999_999);

        return [
            'code' => $code,
            'name' => 'Shelf '.$code,
            'section' => fake()->randomElement(['Sciences', 'Languages', 'Arts', 'Reference']),
            'capacity' => 200,
        ];
    }
}
