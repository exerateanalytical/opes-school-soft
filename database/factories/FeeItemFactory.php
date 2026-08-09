<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Domain\CollectionBasis;
use App\Modules\Fees\Domain\FeeRecurrence;
use App\Modules\Fees\Domain\RecognitionMethod;
use App\Modules\Fees\Models\FeeCategory;
use App\Modules\Fees\Models\FeeItem;
use App\Modules\Fees\Models\ThirdPartyFund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Defaults to an own-revenue item on the seeded 706 (Services vendus)
 * account; use agentCollected() for the C5 liability side.
 *
 * @extends Factory<FeeItem>
 */
class FeeItemFactory extends Factory
{
    /** @var class-string<FeeItem> */
    protected $model = FeeItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'FEE'.fake()->unique()->numberBetween(1, 99999);

        return [
            'code' => $code,
            'name' => 'Fee '.$code,
            'name_fr' => 'Frais '.$code,
            'fee_category_id' => FeeCategory::factory(),
            'collection_basis' => CollectionBasis::OwnRevenue,
            'third_party_fund_id' => null,
            'revenue_account_id' => (int) DB::table('chart_of_accounts')->where('code', '706')->value('id'),
            'recognition_method' => RecognitionMethod::OnIssue,
            'tax_code_id' => null,
            'is_refundable' => false,
            'is_mandatory' => true,
            'default_recurrence' => FeeRecurrence::PerYear,
            'asset_or_service_note' => null,
            'is_archived' => false,
        ];
    }

    public function agentCollected(): static
    {
        return $this->state(fn (): array => [
            'collection_basis' => CollectionBasis::AgentForThirdParty,
            'revenue_account_id' => null,
            'third_party_fund_id' => ThirdPartyFund::factory(),
        ]);
    }
}
