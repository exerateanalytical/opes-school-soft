<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §6.2 - withholding-at-source rules, the
 * profile grouping, and the profile↔rule pivot.
 *
 * The rule table ships EMPTY (§6.1: the full rate table NEEDS VERIFICATION,
 * no rate is seeded) and ResolveWithholding refuses to compute until at
 * least one confirmed rule exists - "configure withholding rules with your
 * accountant", never a silent zero withheld.
 *
 * `rate_bp` is in App\Support\Rate scale (100 000 bp = 100%): 5.5% = 5 500 -
 * the same scale tax_codes.rate_bp uses, NOT the per-10 000 illustration in
 * the spec's table. One scale project-wide.
 *
 * `base` (amount_ht | amount_ttc) is load-bearing and NEEDS VERIFICATION per
 * type - it ships NULLABLE and a rule with an unset base cannot be
 * confirmed/activated (ConfigureWithholdingRule::confirm refuses).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Effective-dated versions share the code; see the UNIQUE below.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs');
            $table->string('name', 160);
            $table->string('name_fr', 160);

            // air | precompte_achats | precompte_station_service |
            // no_contributor_card | niu_inactive | other.
            $table->string('withholding_type', 30);

            // App\Support\Rate scale: 100 000 bp = 100%.
            $table->unsignedBigInteger('rate_bp');

            // amount_ht | amount_ttc. NULL = base unverified: cannot confirm.
            $table->string('base', 12)->nullable();

            // services | goods | both | rent | commission.
            $table->string('applies_to', 12);

            // Below this HT base, no withholding. Threshold NEEDS
            // VERIFICATION; default 0 = no threshold.
            $table->bigInteger('minimum_base')->default(0);

            // Criterion set evaluated against the supplier: regime_fiscal,
            // has_contributor_card, niu_status, supplier_type, country.
            $table->json('supplier_condition')->nullable();

            // Highest matching rule wins; equal-top-priority is a
            // configuration error rejected at save AND at resolution (§6.4).
            $table->integer('priority')->default(0);

            // 447 État, impôts retenus à la source - sub-account NEEDS
            // VERIFICATION, so the accountant wires it; RESTRICT once set.
            $table->foreignId('liability_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();

            // Which TaxDeclaration type this rule's withholdings feed.
            $table->string('declaration_type', 40)->nullable();

            // Ships empty; mandatory before confirmation/activation.
            $table->string('legal_ref', 120)->nullable();

            $table->date('effective_from');
            // Exclusive. NULL = open-ended.
            $table->date('effective_to')->nullable();

            $table->boolean('is_active')->default(false);

            // An unconfirmed rule cannot be applied (§6.2).
            $table->foreignId('confirmed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();

            $table->unique(['code', 'effective_from'], 'uq_withholding_rules_code_from');
            $table->index(['withholding_type', 'is_active'], 'ix_withholding_rules_type_active');
        });

        DB::statement(
            'ALTER TABLE withholding_rules ADD CONSTRAINT chk_wr_type CHECK (withholding_type IN '
            ."('air','precompte_achats','precompte_station_service','no_contributor_card','niu_inactive','other'))"
        );
        DB::statement(
            "ALTER TABLE withholding_rules ADD CONSTRAINT chk_wr_base CHECK (base IS NULL OR base IN ('amount_ht','amount_ttc'))"
        );
        DB::statement(
            'ALTER TABLE withholding_rules ADD CONSTRAINT chk_wr_applies_to CHECK (applies_to IN '
            ."('services','goods','both','rent','commission'))"
        );
        DB::statement(
            'ALTER TABLE withholding_rules ADD CONSTRAINT chk_wr_effective_range '
            .'CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
        DB::statement(
            'ALTER TABLE withholding_rules ADD CONSTRAINT chk_wr_minimum_base CHECK (minimum_base >= 0)'
        );

        // Grouping for assignment to a supplier (§6.2). A supplier with no
        // profile resolves dynamically through §6.4.
        Schema::create('withholding_profiles', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 160);
            $table->string('name_fr', 160);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('withholding_profile_rules', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('withholding_profile_id')
                ->constrained('withholding_profiles')->restrictOnDelete();
            $table->foreignId('withholding_rule_id')
                ->constrained('withholding_rules')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamps();

            $table->unique(['withholding_profile_id', 'sequence'], 'uq_wpr_profile_sequence');
            $table->unique(['withholding_profile_id', 'withholding_rule_id'], 'uq_wpr_profile_rule');
        });

        // NOTHING SEEDED - §6.1: no rate is seeded, ever.
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_profile_rules');
        Schema::dropIfExists('withholding_profiles');
        Schema::dropIfExists('withholding_rules');
    }
};
