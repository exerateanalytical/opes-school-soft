<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Domain\BeneficiaryType;
use App\Modules\Fees\Domain\RemittanceFrequency;
use App\Modules\Fees\Models\ThirdPartyFund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * The liability account leans on the seeded class-47 root: in production the
 * accountant chooses the exact 47x subdivision (04-fees §23 item 1 - nothing
 * is seeded FOR them), but a test fund only needs an existing account whose
 * code starts with 47, which the statutory seed already provides.
 *
 * @extends Factory<ThirdPartyFund>
 */
class ThirdPartyFundFactory extends Factory
{
    /** @var class-string<ThirdPartyFund> */
    protected $model = ThirdPartyFund::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'TPF'.fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => $code,
            'name' => 'Fund '.$code,
            'name_fr' => 'Fonds '.$code,
            'beneficiary_type' => BeneficiaryType::Apee,
            'beneficiary_name' => 'APEE '.$code,
            'beneficiary_niu' => null,
            'liability_account_id' => (int) DB::table('chart_of_accounts')->where('code', '47')->value('id'),
            'remittance_frequency' => RemittanceFrequency::Termly,
            'remittance_due_day' => null,
            'is_active' => true,
        ];
    }
}
