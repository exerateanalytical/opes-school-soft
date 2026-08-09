<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Student;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an OPEN internal negative case with no enrollment link —
 * the shape OpenDisciplineCase creates for an incident outside an enrolled
 * period. Tests attach `enrollment_id` explicitly because the enrollment
 * fixture (year/level/section) is theirs to build.
 *
 * @extends Factory<DisciplineCase>
 */
class DisciplineCaseFactory extends Factory
{
    /** @var class-string<DisciplineCase> */
    protected $model = DisciplineCase::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'enrollment_id' => null,
            'discipline_category_id' => DisciplineCategory::factory(),
            'occurred_on' => '2026-09-10',
            'reported_by' => User::factory(),
            'description' => fake()->sentence(8),
            'status' => DisciplineCaseStatus::Open,
            'visibility' => DisciplineVisibility::Internal,
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_note' => null,
            'is_positive' => false,
        ];
    }

    public function positive(): static
    {
        return $this->state(fn (): array => ['is_positive' => true]);
    }

    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'status' => DisciplineCaseStatus::Resolved,
            'resolved_at' => now(),
            'resolved_by' => User::factory(),
            'resolution_note' => 'Resolved in test fixture.',
        ]);
    }
}
