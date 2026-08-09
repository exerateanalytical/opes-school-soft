<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * `entity_id` is deliberately NOT a real row: the artifact ledger records
 * (table, id) pairs without an FK, because the referenced rows live in five
 * different modules and are deleted BY the undo that reads this ledger.
 *
 * @extends Factory<RolloverArtifact>
 */
class RolloverArtifactFactory extends Factory
{
    /** @var class-string<RolloverArtifact> */
    protected $model = RolloverArtifact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'rollover_run_id' => RolloverRunFactory::new(),
            'entity_type' => 'class_groups',
            'entity_id' => fake()->unique()->numberBetween(1, 2_000_000),
            'step' => RolloverStep::CopyClassGroups->value,
        ];
    }
}
