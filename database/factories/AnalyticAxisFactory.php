<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\AnalyticAxis;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A throwaway NON-mandatory axis by default, so ordinary tests do not
 * accidentally trip AN-3; use state overrides for mandatory axes.
 *
 * @extends Factory<AnalyticAxis>
 */
class AnalyticAxisFactory extends Factory
{
    /** @var class-string<AnalyticAxis> */
    protected $model = AnalyticAxis::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'AX'.strtoupper(fake()->unique()->lexify('??????'));

        return [
            'code' => $code,
            'name' => 'Test axis '.$code,
            'name_fr' => 'Axe de test '.$code,
            'is_mandatory' => false,
            'applies_to_classes' => [6, 7],
            'requires_full_allocation' => true,
            'display_order' => 99,
            'is_active' => true,
            'is_archived' => false,
        ];
    }
}
