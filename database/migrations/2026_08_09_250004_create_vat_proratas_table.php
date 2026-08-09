<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §5.4 - the prorata de déduction, per
 * fiscal year and basis (provisional at year opening, definitive at close),
 * plus the regularisation working-paper table.
 *
 * `rate_bp` is in App\Support\Rate scale (100 000 bp = 100%). The spec's
 * worked example (11.72% = "1172 bp" in its per-10 000 illustration) is
 * 11 720 here; ComputeVatProrata rounds to the precision the configured
 * TaxSettings.prorata_rounding dictates so the §5.4 example reproduces to
 * the franc.
 *
 * A prorata cannot be used for deduction until CONFIRMED (`confirmed_at`) -
 * ComputeLineTax refuses on an unconfirmed or absent prorata rather than
 * silently deducting 100%.
 *
 * vat_prorata_regularisations is SCHEMA ONLY in this phase (§5.4.4): whether
 * the CGI requires multi-year capital-goods regularisation is NEEDS
 * VERIFICATION, so `asset_id` stays a plain column (Assets is Phase 9) and
 * no rule is implemented. The annual provisional→definitive regularisation
 * Action (RegulariseVatProrata) is Agent F5's scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_proratas', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();

            // provisional | definitive.
            $table->string('basis', 12);

            // App\Support\Rate scale (100 000 = 100%).
            $table->unsignedBigInteger('rate_bp');

            // Taxable (and zero-rated) turnover HT / total turnover HT.
            $table->bigInteger('numerator_amount');
            $table->bigInteger('denominator_amount');

            $table->dateTime('computed_at')->nullable();
            $table->foreignId('computed_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            // computed | manual. Manual requires a reason (§5.4).
            $table->string('source', 10)->default('computed');
            $table->string('manual_reason', 255)->nullable();

            $table->foreignId('confirmed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            // The provisional→definitive adjusting entry (posted by F5's
            // RegulariseVatProrata through PostFromEvent).
            $table->foreignId('regularisation_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'basis'], 'uq_vat_proratas_year_basis');
        });

        DB::statement(
            "ALTER TABLE vat_proratas ADD CONSTRAINT chk_vp_basis CHECK (basis IN ('provisional','definitive'))"
        );
        DB::statement(
            "ALTER TABLE vat_proratas ADD CONSTRAINT chk_vp_source CHECK (source IN ('computed','manual'))"
        );
        DB::statement(
            'ALTER TABLE vat_proratas ADD CONSTRAINT chk_vp_amounts '
            .'CHECK (numerator_amount >= 0 AND denominator_amount > 0 AND numerator_amount <= denominator_amount)'
        );
        DB::statement(
            'ALTER TABLE vat_proratas ADD CONSTRAINT chk_vp_rate CHECK (rate_bp <= 100000)'
        );

        Schema::create('vat_prorata_regularisations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('vat_prorata_id')
                ->constrained('vat_proratas')->restrictOnDelete();

            // Assets is Phase 9; plain column until its table exists.
            $table->unsignedBigInteger('asset_id')->nullable();

            // annual_adjustment | capital_goods (§5.4.4 - anticipated only).
            $table->string('regularisation_type', 30);

            // Signed: the adjustment can go either way.
            $table->bigInteger('amount');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();
        });

        // NOTHING SEEDED - the prorata formula and rounding rule are NEEDS
        // VERIFICATION; the accountant computes and confirms every row.
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_prorata_regularisations');
        Schema::dropIfExists('vat_proratas');
    }
};
