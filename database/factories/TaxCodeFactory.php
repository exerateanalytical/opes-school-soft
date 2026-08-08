<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Tax\Domain\TaxType;
use App\Modules\Tax\Models\TaxCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A plain 19.25% output TVA code by default. rate_bp is in
 * App\Support\Rate scale (100 000 bp = 100%).
 *
 * @extends Factory<TaxCode>
 */
class TaxCodeFactory extends Factory
{
    /** @var class-string<TaxCode> */
    protected $model = TaxCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'TX'.strtoupper(fake()->unique()->lexify('??????'));

        return [
            'code' => $code,
            'name' => 'Test tax '.$code,
            'name_fr' => 'Taxe de test '.$code,
            'tax_type' => TaxType::Tva,
            'rate_bp' => 19_250,
            'direction' => 'output',
            'effective_from' => '2020-01-01',
            'effective_to' => null,
            'is_exempt' => false,
            'is_zero_rated' => false,
            'exemption_legal_ref' => null,
            'exemption_condition' => null,
            'collected_account_id' => null,
            'deductible_account_id' => null,
            'non_deductible_expense_account_id' => null,
            'affects_prorata_numerator' => true,
            'affects_prorata_denominator' => true,
            'is_active' => true,
        ];
    }

    public function exempt(): self
    {
        return $this->state([
            'rate_bp' => 0,
            'is_exempt' => true,
            'exemption_legal_ref' => 'CGI art. TEST',
            'exemption_condition' => 'ministry_accreditation',
            'affects_prorata_numerator' => false,
        ]);
    }
}
