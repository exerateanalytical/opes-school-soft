<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Models\PromotionDecision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionDecision>
 */
class PromotionDecisionFactory extends Factory
{
    /** @var class-string<PromotionDecision> */
    protected $model = PromotionDecision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => EnrollmentFactory::new(),
            'decision' => PromotionDecision::DECISION_PROMOTED,
            'target_class_group_key' => 'level:'.fake()->numberBetween(1, 7),
            'decided_by' => UserFactory::new(),
            'decided_at' => '2027-07-10 10:00:00',
        ];
    }

    public function repeat(): static
    {
        return $this->state(fn (): array => [
            'decision' => PromotionDecision::DECISION_REPEAT,
        ]);
    }

    public function graduated(): static
    {
        return $this->state(fn (): array => [
            'decision' => PromotionDecision::DECISION_GRADUATED,
            'target_class_group_key' => null,
        ]);
    }
}
