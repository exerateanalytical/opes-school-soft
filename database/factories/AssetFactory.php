<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * A DRAFT purchased asset on a self-consistent calendar. The calendar is
 * built through JournalEntryFactory::buildCalendar - the same helper every
 * financial factory uses - so a capitalisation posted against the asset's
 * dates lands in an open period without further fixture work.
 *
 * Depreciation policy columns are NULL here on purpose: they are
 * CapitaliseAsset's snapshot to take (§5.3), not a factory default.
 *
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    /** @var class-string<Asset> */
    protected $model = Asset::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2400, 2999);
        $date = Carbon::parse($year.'-03-10');
        $calendar = (new JournalEntryFactory)->buildCalendar($date);

        return [
            'tag_number' => 'AST-'.fake()->unique()->numberBetween(1, 9_999_999),
            'serial_number' => null,
            'asset_category_id' => AssetCategory::factory(),
            'parent_asset_id' => null,
            'name' => 'Asset '.fake()->unique()->numberBetween(1, 9_999_999),
            'description' => null,
            'status' => 'draft',
            'acquisition_date' => $date->toDateString(),
            'acquisition_cost' => fake()->numberBetween(100_000, 50_000_000),
            'cost_basis' => 'ht',
            'non_recoverable_vat_amount' => 0,
            'residual_value' => 0,
            'in_service_date' => null,
            'depreciation_start_date' => null,
            'useful_life_months' => null,
            'depreciation_method' => null,
            'prorata_convention' => null,
            'acquisition_type' => 'purchase',
            'fair_value_at_donation' => null,
            'donor_id' => null,
            'investment_subsidy_id' => null,
            'supplier_id' => null,
            'supplier_invoice_id' => null,
            'location_id' => null,
            'custodian_staff_id' => null,
            'school_section_id' => null,
            'fiscal_year_id' => $calendar['fiscal_year_id'],
            'academic_year_id' => $calendar['academic_year_id'],
            'insurance_policy_ref' => null,
            'warranty_expires_on' => null,
            'notes' => null,
            'idempotency_key' => null,
        ];
    }
}
