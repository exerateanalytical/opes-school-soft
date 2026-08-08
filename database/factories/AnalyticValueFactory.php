<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Accounting\Models\AnalyticAxis;
use App\Modules\Accounting\Models\AnalyticValue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalyticValue>
 */
class AnalyticValueFactory extends Factory
{
    /** @var class-string<AnalyticValue> */
    protected $model = AnalyticValue::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'VAL'.strtoupper(fake()->unique()->lexify('??????'));

        return [
            'analytic_axis_id' => AnalyticAxis::factory(),
            'code' => $code,
            'name' => 'Test value '.$code,
            'name_fr' => 'Valeur de test '.$code,
            'parent_id' => null,
            'linked_type' => null,
            'linked_id' => null,
            'is_active' => true,
            'is_archived' => false,
        ];
    }
}
