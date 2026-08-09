<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\FeeCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeCategory>
 */
class FeeCategoryFactory extends Factory
{
    /** @var class-string<FeeCategory> */
    protected $model = FeeCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'CAT'.fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => $code,
            'name' => 'Category '.$code,
            'name_fr' => 'Categorie '.$code,
            'display_order' => 0,
            'is_archived' => false,
        ];
    }
}
