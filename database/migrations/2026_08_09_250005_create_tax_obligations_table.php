<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §7.1 / §7.4 - reference data for the
 * compliance calendar:
 *
 * - `tax_declaration_types`: extensible reference table (NOT an enum - the
 *   list of declaration types is not verified).
 * - `tax_obligations`: declarative obligations with a small `due_rule`
 *   expression (e.g. `day_of_next_month(15)` or `tax_centre_dependent`),
 *   from which the system generates upcoming obligations and T−15/7/1
 *   alerts.
 *
 * Seeding policy (00-core §16): NO rate and NO unverified deadline is
 * seeded. The ONE verified item (§7.5) IS seeded: the DSF annual
 * declaration, due 15 March (DGE) / 15 April (CIME) / 15 May (others),
 * penalties 25% + 1.5%/month - encoded as `due_rule` DATA, not a hardcoded
 * match. The TVA-return deadline ("commonly stated as the 15th") is NEEDS
 * VERIFICATION and is NOT seeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_declaration_types', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // tva_monthly | withholding_monthly | acompte_is | dsf_annual |
            // ... extensible.
            $table->string('code', 40)->collation('utf8mb4_0900_as_cs')->unique();
            $table->string('name', 160);
            $table->string('name_fr', 160);

            // month | quarter | year.
            $table->string('period_type', 10);

            // Archive-flag, never delete: a retired type stays referencable
            // by historical declarations.
            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE tax_declaration_types ADD CONSTRAINT chk_tdt_period CHECK (period_type IN ('month','quarter','year'))"
        );

        Schema::create('tax_obligations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('tax_declaration_type_id')
                ->constrained('tax_declaration_types')->restrictOnDelete();

            // monthly | quarterly | annual.
            $table->string('frequency', 10);

            // Small declarative expression, e.g. 'day_of_next_month(15)' or
            // 'tax_centre_dependent(DGE=03-15,CIME=04-15,other=05-15)'.
            // Weekend/holiday roll-forward NEEDS VERIFICATION: the statutory
            // date is shown with no adjustment and a note (§7.6).
            $table->string('due_rule', 160);

            // Regime / TVA-registration predicate, evaluated against the
            // fiscal identity, e.g. {"tax_regime":"reel"}.
            $table->json('applies_when')->nullable();

            $table->string('penalty_note', 255)->nullable();
            $table->string('legal_ref', 120)->nullable();

            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE tax_obligations ADD CONSTRAINT chk_to_frequency CHECK (frequency IN ('monthly','quarterly','annual'))"
        );

        // The ONLY seeded rows: the DSF, whose dates and penalties §7.5
        // marks VERIFIED. Everything else waits for the accountant.
        $now = now();

        DB::table('tax_declaration_types')->insert([
            'code' => 'dsf_annual',
            'name' => 'Statistical and Fiscal Declaration (DSF)',
            'name_fr' => 'Déclaration Statistique et Fiscale (DSF)',
            'period_type' => 'year',
            'is_archived' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $dsfTypeId = DB::table('tax_declaration_types')->where('code', 'dsf_annual')->value('id');

        DB::table('tax_obligations')->insert([
            'tax_declaration_type_id' => $dsfTypeId,
            'frequency' => 'annual',
            'due_rule' => 'tax_centre_dependent(DGE=03-15,CIME=04-15,other=05-15)',
            'applies_when' => json_encode(['tax_regime' => 'reel']),
            'penalty_note' => '25% majoration + 1.5% interest per month of delay (verified, spec §7.5). Filed exclusively electronically via impots.cm; this system never files - it generates figures and the export.',
            'legal_ref' => null,
            'is_archived' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_obligations');
        Schema::dropIfExists('tax_declaration_types');
    }
};
