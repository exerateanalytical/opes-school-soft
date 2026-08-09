<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §6.3 - investment_subsidies (class 14) and
 * investment_subsidy_releases (the quote-part virée au résultat).
 *
 * `release_income_account_id` is NULLABLE and never seeded: 845 is V5
 * NEEDS VERIFICATION (865 is confirmed wrong). While NULL the release step
 * is skipped with an exception and the asset still depreciates - the
 * subsidy sits in 14 until an accountant configures the account.
 *
 * UNIQUE(investment_subsidy_id, asset_id, depreciation_run_id) is the same
 * idempotency shape as the schedule. depreciation_run_id is NULLABLE for
 * the §6.4 disposal-time write-off of the unreleased balance.
 *
 * Also adds the FK for assets.investment_subsidy_id that F1's 270002 left
 * unconstrained (this migration owns the target table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_subsidies', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifiers accent- and case-sensitive.
            $table->string('reference', 60)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_inv_subsidies_ref');

            // The partner registry for donors is the supplier table.
            $table->foreignId('donor_partner_id')
                ->constrained('suppliers')->restrictOnDelete();

            // Class 14 (subdivision NEEDS VERIFICATION - the school's
            // accountant picks the concrete account at registration).
            $table->foreignId('subsidy_account_id')
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // 845 - V5 NEEDS VERIFICATION: NULL until confirmed, releases
            // are skipped-with-exception while NULL, never guessed.
            $table->foreignId('release_income_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->bigInteger('granted_amount');
            $table->date('granted_on');

            $table->string('agreement_ref', 120)->nullable();
            $table->text('conditions')->nullable();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')->restrictOnDelete();

            $table->enum('status', ['active', 'fully_released', 'clawed_back'])
                ->default('active');

            $table->string('idempotency_key', 100)->nullable()->unique('uq_inv_subsidies_idem');

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE investment_subsidies ADD CONSTRAINT chk_inv_subsidies_amount CHECK ( granted_amount > 0 )'
        );

        Schema::create('investment_subsidy_releases', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('investment_subsidy_id')
                ->constrained('investment_subsidies')->restrictOnDelete();
            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            // NULL only for the disposal-time / clawback write-off row.
            $table->foreignId('depreciation_run_id')->nullable()
                ->constrained('depreciation_runs')->restrictOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();
            $table->unsignedTinyInteger('period_month');

            $table->bigInteger('amount');

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            // The same idempotency shape as the schedule (§6.3).
            $table->unique(
                ['investment_subsidy_id', 'asset_id', 'depreciation_run_id'],
                'uq_inv_sub_releases_run'
            );
        });

        DB::statement(
            'ALTER TABLE investment_subsidy_releases ADD CONSTRAINT chk_inv_sub_rel_month CHECK ( '
            .'period_month BETWEEN 1 AND 12 )'
        );

        // F1's 270002 noted: FK added by the owning phase's migration.
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreign('investment_subsidy_id', 'fk_assets_inv_subsidy')
                ->references('id')->on('investment_subsidies')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('fk_assets_inv_subsidy');
        });

        Schema::dropIfExists('investment_subsidy_releases');
        Schema::dropIfExists('investment_subsidies');
    }
};
