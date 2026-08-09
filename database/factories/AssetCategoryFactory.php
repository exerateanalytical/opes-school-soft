<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Assets\Models\AssetCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * A straight-line IT-equipment category wired to the VERIFIED seeded
 * accounts (02-accounting 2.3): 2442 gross, 28 accumulated, 812/822
 * disposal legs, 249 in-progress. The NEEDS-VERIFICATION columns (681x,
 * class-29, 106, 151) stay NULL exactly as the seeder would leave them -
 * a test wanting them set must say so explicitly.
 *
 * prorata_convention defaults to `monthly` because most F1 flows need a
 * capitalisable category; the V1-gate tests override it back to NULL.
 *
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    /** @var class-string<AssetCategory> */
    protected $model = AssetCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $code = 'AC'.fake()->unique()->numberBetween(1, 999_999);

        return [
            'code' => $code,
            'name' => 'Category '.$code,
            'name_fr' => 'Categorie '.$code,
            'parent_id' => null,
            'asset_account_id' => fn (): int => self::accountId('2442'),
            'accumulated_depreciation_account_id' => fn (): int => self::accountId('28'),
            'depreciation_expense_account_id' => null,
            'disposal_nbv_account_id' => fn (): int => self::accountId('812'),
            'disposal_proceeds_account_id' => fn (): int => self::accountId('822'),
            'impairment_provision_account_id' => null,
            'impairment_expense_account_id' => null,
            'revaluation_equity_account_id' => null,
            'in_progress_account_id' => fn (): int => self::accountId('249'),
            'depreciation_method' => 'straight_line',
            'useful_life_months' => 60,
            'declining_rate_bp' => null,
            'default_residual_rate_bp' => 0,
            'prorata_convention' => 'monthly',
            'tax_method' => null,
            'tax_rate_bp' => null,
            'tax_useful_life_months' => null,
            'derogatory_depreciation_account_id' => null,
            'capitalisation_threshold' => 0,
            'below_threshold_behaviour' => 'expense_only',
            'below_threshold_expense_account_id' => null,
            'requires_serial_number' => false,
            'is_archived' => false,
        ];
    }

    /** A seeded chart account id by exact code. */
    public static function accountId(string $code): int
    {
        return (int) DB::table('chart_of_accounts')->where('code', $code)->value('id');
    }
}
