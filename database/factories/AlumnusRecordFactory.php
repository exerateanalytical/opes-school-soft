<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Alumni\Models\AlumnusRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare-schema fixture only. Tests that exercise the CONVERSION contract go
 * through the real ConvertGraduateToAlumnus door; this factory exists for
 * screens and engagement flows that need a record to already exist.
 *
 * @extends Factory<AlumnusRecord>
 */
final class AlumnusRecordFactory extends Factory
{
    protected $model = AlumnusRecord::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'graduation_year' => 2030,
            'final_class_group_name' => 'Upper Sixth A',
            'academic_year_name' => 'Academic Year 2029/2030',
            'current_occupation' => null,
            'current_organisation' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'is_deceased' => false,
            'notes' => null,
        ];
    }

    public function reachable(): self
    {
        return $this->state(fn (array $attributes): array => [
            'contact_email' => fake()->unique()->safeEmail(),
        ]);
    }

    public function deceased(): self
    {
        return $this->state(fn (array $attributes): array => ['is_deceased' => true]);
    }
}
