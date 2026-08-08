<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §5.3 - TaxCode, the minimal scaffolding
 * Phase 6 needs so fee items can carry `tax_code_id` and the chart of
 * accounts' `default_tax_code_id` has a target. The FULL tax module
 * (WithholdingRule, TaxDeclaration, prorata...) is Phase 5 and is NOT built
 * here.
 *
 * NOTHING is seeded (00-core §16 blocking-gate discipline): the CGI article
 * for the education exemption is NEEDS VERIFICATION, so the accountant
 * configures every row via ConfigureTaxCode, and the TVA machinery refuses
 * to compute until at least one confirmed row exists.
 *
 * `rate_bp` is stored in App\Support\Rate's scale - 100 000 bp = 100%, so
 * 19.25% = 19 250 - NOT the per-10 000 illustration in 03's table. One
 * scale project-wide, or a snapshot re-rendered through Rate would silently
 * multiply by ten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core §4: identifier collation - accent/case sensitive.
            // NOT unique alone: effective-dated versions share the code.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs');

            $table->string('name', 160);
            $table->string('name_fr', 160);

            // tva | withholding_air | withholding_precompte | other.
            // Withholding logic lives in Phase 5's WithholdingRule; a
            // withholding-type TaxCode exists only so a line can name it.
            $table->string('tax_type', 20);

            // Integer basis points, App\Support\Rate scale (100 000 = 100%).
            $table->unsignedBigInteger('rate_bp');

            // output (collected on sales) | input (deductible) | both.
            $table->string('direction', 10);

            $table->date('effective_from');
            // Exclusive. NULL = open-ended.
            $table->date('effective_to')->nullable();

            $table->boolean('is_exempt')->default(false);
            // Distinct from exempt: zero-rated supplies grant deduction,
            // exempt supplies do not (prorata numerator integrity).
            $table->boolean('is_zero_rated')->default(false);

            // Ships empty; ConfigureTaxCode makes it mandatory when
            // is_exempt (NEEDS VERIFICATION: CGI art. 120 vs 128).
            $table->string('exemption_legal_ref', 120)->nullable();
            $table->string('exemption_condition', 40)->nullable();

            // Accounts wiring. Nullable: the accountant selects them in the
            // setup wizard (00-core §16); RESTRICT once chosen.
            $table->foreignId('collected_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('deductible_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('non_deductible_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('affects_prorata_numerator')->default(false);
            $table->boolean('affects_prorata_denominator')->default(true);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['code', 'effective_from'], 'uq_tax_codes_code_effective_from');
        });

        // MySQL 8.0.16+ enforces CHECK constraints.
        DB::statement(
            'ALTER TABLE tax_codes ADD CONSTRAINT chk_tax_codes_type '
            ."CHECK (tax_type IN ('tva','withholding_air','withholding_precompte','other'))"
        );
        DB::statement(
            'ALTER TABLE tax_codes ADD CONSTRAINT chk_tax_codes_direction '
            ."CHECK (direction IN ('output','input','both'))"
        );
        DB::statement(
            'ALTER TABLE tax_codes ADD CONSTRAINT chk_tax_codes_effective_range '
            .'CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        // Exempt and zero-rated are mutually exclusive states.
        DB::statement(
            'ALTER TABLE tax_codes ADD CONSTRAINT chk_tax_codes_exempt_xor_zero '
            .'CHECK (NOT (is_exempt = 1 AND is_zero_rated = 1))'
        );

        // NOTHING SEEDED - deliberately (00-core §16).
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_codes');
    }
};
