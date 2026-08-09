<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Payroll\Models\StatutoryRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds statutory rate SHELLS by default - amount columns NULL,
 * `is_verified = false` - exactly the state 05-hr-payroll.md §0 ships.
 * Tests that need REAL reference values (CLEISS 2024) set them explicitly
 * in fixtures tagged `@statutory-reference`; this factory deliberately
 * knows none of them.
 *
 * @extends Factory<StatutoryRate>
 */
final class StatutoryRateFactory extends Factory
{
    protected $model = StatutoryRate::class;

    public function definition(): array
    {
        return [
            'code' => 'PVID',
            'label' => 'Test statutory rate shell',
            'label_fr' => null,
            'shape' => 'percentage',
            'basis' => 'cnps_capped',
            'bracket_basis' => null,
            'employee_rate_bp' => null,
            'employer_rate_bp' => null,
            'flat_amount' => null,
            'ceiling_amount' => null,
            'floor_amount' => null,
            'band_from' => null,
            'band_to' => null,
            'risk_class' => null,
            'cnps_regime' => null,
            'effective_from' => '2024-01-01',
            'effective_to' => null,
            'source_citation' => 'Test fixture',
            'source_document_id' => null,
            'is_verified' => false,
            'verified_by' => null,
            'verified_at' => null,
            'locked' => false,
        ];
    }
}
