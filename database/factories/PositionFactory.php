<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\HR\Models\Position;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    /** @var class-string<Position> */
    protected $model = Position::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'POS-'.Str::upper(Str::random(6)),
            'name' => 'Teacher',
            'name_fr' => 'Enseignant',
            'is_teaching' => true,
            'is_active' => true,
        ];
    }

    public function nonTeaching(): self
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'Bursar',
            'name_fr' => 'Econome',
            'is_teaching' => false,
        ]);
    }
}
