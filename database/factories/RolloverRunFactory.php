<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A run mid-flight: outgoing year exists, new year already created (step 1
 * done), operator present. Tests exercising the step-0 state use atPreflight(),
 * which leaves academic_year_to_id NULL exactly as StartRolloverRun would.
 *
 * @extends Factory<RolloverRun>
 */
class RolloverRunFactory extends Factory
{
    /** @var class-string<RolloverRun> */
    protected $model = RolloverRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_from_id' => AcademicYearFactory::new(),
            'academic_year_to_id' => AcademicYearFactory::new(),
            'current_step' => RolloverStep::CopyClassGroups->value,
            'step_states' => [
                (string) RolloverStep::Preflight->value => ['completed_at' => '2027-08-20T08:00:00Z'],
                (string) RolloverStep::CreateNewYear->value => ['completed_at' => '2027-08-20T08:05:00Z'],
            ],
            'inputs_hash' => hash('sha256', fake()->unique()->uuid()),
            'status' => RolloverRunStatus::Running->value,
            'operator_id' => UserFactory::new(),
            'backup_id' => null,
        ];
    }

    /**
     * The state StartRolloverRun leaves behind: pre-flight only, no new year
     * yet, nothing copied.
     */
    public function atPreflight(): static
    {
        return $this->state(fn (): array => [
            'academic_year_to_id' => null,
            'current_step' => RolloverStep::Preflight->value,
            'step_states' => null,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'current_step' => RolloverStep::FlipActiveYear->value,
            'status' => RolloverRunStatus::Completed->value,
        ]);
    }
}
