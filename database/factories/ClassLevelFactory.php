<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassLevel>
 */
final class ClassLevelFactory extends Factory
{
    /** @var class-string<ClassLevel> */
    protected $model = ClassLevel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 9999);

        return [
            'school_section_id' => SchoolSection::factory(),
            'code' => 'F'.$number,
            'name' => 'Form '.$number,
            'name_fr' => 'Classe '.$number,
            'order_index' => $number,
            'is_exam_class' => false,
        ];
    }

    public function examClass(): static
    {
        return $this->state(fn (array $attributes): array => ['is_exam_class' => true]);
    }
}
