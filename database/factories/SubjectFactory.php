<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subject>
 */
final class SubjectFactory extends Factory
{
    protected $model = Subject::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('SUB-####')),
            'name' => $this->faker->unique()->words(2, true),
            'name_fr' => $this->faker->words(2, true),
            'department_id' => null,
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
