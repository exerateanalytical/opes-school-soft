<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    /** @var class-string<Room> */
    protected $model = Room::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'R-'.Str::upper(Str::random(6)),
            'name' => 'Room '.fake()->buildingNumber(),
            'capacity' => fake()->numberBetween(20, 80),
            'building' => 'Block '.fake()->randomLetter(),
            'type' => 'classroom',
        ];
    }
}
