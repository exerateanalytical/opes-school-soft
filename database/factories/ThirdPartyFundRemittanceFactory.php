<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fees\Models\ThirdPartyFundRemittance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft remittance by default; use remitted() for the confirmed leg.
 * `third_party_fund_id` must be supplied by the caller - funds carry a
 * class-47 liability account chosen by the accountant, so tests build them
 * explicitly.
 *
 * @extends Factory<ThirdPartyFundRemittance>
 */
class ThirdPartyFundRemittanceFactory extends Factory
{
    /** @var class-string<ThirdPartyFundRemittance> */
    protected $model = ThirdPartyFundRemittance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'period_start' => '2026-01-01',
            'period_end' => '2026-03-31',
            'amount_collected' => 0,
            'amount_remitted' => 0,
            'remitted_on' => null,
            'method' => null,
            'reference' => null,
            'status' => 'draft',
            'journal_entry_id' => null,
            'approved_by' => null,
            'approved_at' => null,
        ];
    }

    public function remitted(int $amount, string $on): self
    {
        return $this->state([
            'amount_remitted' => $amount,
            'remitted_on' => $on,
            'status' => 'remitted',
            'method' => 'bank_transfer',
            'reference' => 'TRF-'.fake()->unique()->numerify('######'),
        ]);
    }
}
