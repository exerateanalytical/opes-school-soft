<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Domain\Comparator;
use App\Modules\Students\Domain\CriterionType;
use App\Modules\Students\Models\PromotionCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionCriterion>
 */
class PromotionCriterionFactory extends Factory
{
    /** @var class-string<PromotionCriterion> */
    protected $model = PromotionCriterion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'criteria_set_id' => PromotionCriteriaSetFactory::new(),
            'type' => CriterionType::AnnualAverage,
            'comparator' => Comparator::Gte,
            'threshold' => '10.000',
            'subject_id' => null,
            'weight' => '0.00',
            'is_blocking' => true,
            'sequence' => fake()->unique()->numberBetween(0, 60000),
        ];
    }
}
