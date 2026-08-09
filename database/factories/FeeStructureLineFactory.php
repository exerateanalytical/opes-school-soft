<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\FeeStructureLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeeStructureLine>
 */
class FeeStructureLineFactory extends Factory
{
    /** @var class-string<FeeStructureLine> */
    protected $model = FeeStructureLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fee_structure_id' => FeeStructure::factory(),
            'fee_item_id' => FeeItem::factory(),
            'amount' => fake()->numberBetween(5_000, 500_000),
            'term_id' => FeeStructureLine::ANNUAL,
            'service_period_start' => null,
            'service_period_end' => null,
            'is_optional' => false,
            'display_order' => 0,
        ];
    }
}
