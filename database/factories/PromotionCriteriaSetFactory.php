<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Students\Models\PromotionCriteriaSet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PromotionCriteriaSet>
 */
class PromotionCriteriaSetFactory extends Factory
{
    /** @var class-string<PromotionCriteriaSet> */
    protected $model = PromotionCriteriaSet::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYearFactory::new(),
            'school_section_id' => SchoolSectionFactory::new(),
            'class_level_id' => null,
            'name' => 'Promotion criteria '.fake()->unique()->numberBetween(1, 9999),
            'is_active' => true,
            'version' => 1,
            'created_by' => null,
        ];
    }
}
