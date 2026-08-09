<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §2.1 - asset_categories. Reference data
 * owning the accounting and fiscal depreciation policy for every asset
 * capitalised under it. CHECKs A1/A2/A4 live here; A3 (class-prefix
 * resolution) and A5 (account freeze once posted) are Action-enforced
 * because the chart is data, not schema.
 *
 * NEEDS-VERIFICATION discipline (§11): columns whose SYSCOHADA code is not
 * yet confirmed (681x subdivision, class-29, 106, 151, in-progress account)
 * are NULLABLE and never seeded; the dependent Action refuses to run while
 * they are NULL, naming the missing configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier columns are accent- and case-sensitive.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique('uq_asset_categories_code');
            $table->string('name', 120);
            $table->string('name_fr', 120);

            // Max depth 3 and the cycle check are Action-enforced (A10-style
            // ancestor walk); RESTRICT because children may carry history.
            $table->foreignId('parent_id')->nullable()
                ->constrained('asset_categories')->restrictOnDelete();

            // The class-2 gross account (e.g. 2442) and its class-28 mirror.
            // Both resolvable to verified codes today, so NOT NULL.
            $table->foreignId('asset_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('accumulated_depreciation_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // 681x - subdivision NEEDS VERIFICATION (V3): nullable, unseeded;
            // RunDepreciation (F2) refuses while NULL.
            $table->foreignId('depreciation_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // Verified 81x / 82x families (02-accounting C7).
            $table->foreignId('disposal_nbv_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('disposal_proceeds_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // Feature-gated accounts, all NEEDS VERIFICATION (V7-V9):
            // nullable, unseeded, dependent features refuse while NULL.
            $table->foreignId('impairment_provision_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('impairment_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('revaluation_equity_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // Assets under construction (§3): 249 is seeded and postable;
            // still per-school configuration, so nullable and CommissionAsset
            // refuses while NULL.
            $table->foreignId('in_progress_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->enum('depreciation_method', ['none', 'straight_line', 'declining_balance']);
            $table->unsignedSmallInteger('useful_life_months')->nullable();

            // Basis points on the house scale (Rate::SCALE, 100 000 = 100%).
            $table->bigInteger('declining_rate_bp')->nullable();
            $table->bigInteger('default_residual_rate_bp')->default(0);

            // NULL until the school's accountant declares a policy (V1);
            // RunDepreciation refuses while any depreciating category has
            // this NULL. Never defaulted - two schools must not diverge by
            // accident (§5.2).
            $table->enum('prorata_convention', ['daily', 'monthly', 'full_month', 'half_year'])->nullable();

            // Fiscal (CGI) policy - V10, all nullable until verified rates
            // are entered; the fiscal basis is not generated until then.
            $table->enum('tax_method', ['none', 'straight_line', 'declining_balance'])->nullable();
            $table->bigInteger('tax_rate_bp')->nullable();
            $table->smallInteger('tax_useful_life_months')->nullable();
            $table->foreignId('derogatory_depreciation_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // FCFA; 0 = capitalise everything (§8.6).
            $table->bigInteger('capitalisation_threshold')->default(0);
            $table->enum('below_threshold_behaviour', ['expense_only', 'expense_and_track'])
                ->default('expense_only');
            $table->foreignId('below_threshold_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('requires_serial_number')->default(false);
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        // A1: no useful life without a depreciating method, and vice versa.
        DB::statement(
            'ALTER TABLE asset_categories ADD CONSTRAINT chk_asset_categories_a1 CHECK ( '
            ."(depreciation_method = 'none') = (useful_life_months IS NULL) )"
        );

        // A2: declining balance requires its rate.
        DB::statement(
            'ALTER TABLE asset_categories ADD CONSTRAINT chk_asset_categories_a2 CHECK ( '
            ."depreciation_method <> 'declining_balance' OR declining_rate_bp IS NOT NULL )"
        );

        // A4: a positive threshold requires the expense account for the
        // below-threshold path.
        DB::statement(
            'ALTER TABLE asset_categories ADD CONSTRAINT chk_asset_categories_a4 CHECK ( '
            .'capitalisation_threshold = 0 OR below_threshold_expense_account_id IS NOT NULL )'
        );

        // Rates and thresholds are never negative.
        DB::statement(
            'ALTER TABLE asset_categories ADD CONSTRAINT chk_asset_categories_rates CHECK ( '
            .'default_residual_rate_bp >= 0 AND capitalisation_threshold >= 0 '
            .'AND (declining_rate_bp IS NULL OR declining_rate_bp > 0) )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
