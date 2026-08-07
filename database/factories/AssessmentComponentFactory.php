<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assessment\Models\AssessmentComponent;
use App\Modules\Assessment\Models\AssessmentFramework;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssessmentComponent>
 */
final class AssessmentComponentFactory extends Factory
{
    protected $model = AssessmentComponent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'framework_id' => AssessmentFramework::factory(),
            'code' => 'CA',
            'name' => 'Continuous Assessment',
            'name_fr' => 'Contrôle continu',
            // Deliberately NOT the framework's 20: a component carries its own
            // maximum, and a factory that quietly matched the framework would
            // let the 2.1 defect back in unnoticed by every test using it.
            'max_score' => '30.000',
            'order_index' => 1,
            'is_active' => true,
        ];
    }

    /** The end-of-period examination, marked out of 100. */
    public function exam(): self
    {
        return $this->state(fn (): array => [
            'code' => 'EXAM',
            'name' => 'Examination',
            'name_fr' => 'Composition',
            'max_score' => '100.000',
            'order_index' => 2,
        ]);
    }
}
