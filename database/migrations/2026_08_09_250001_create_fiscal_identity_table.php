<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §2 - the school's fiscal identity.
 *
 * The spec assumed a `SchoolProfile` singleton ROW to hang these columns on,
 * but this codebase's SchoolProfile is a key-value Setting store with no row
 * table (docs/plans/phase-05.md §0) - so the fiscal identity gets its own
 * singleton table, `CHECK (id = 1)` making a second row a database error,
 * the same way a trigger-guarded singleton would but greppable in the schema.
 * Flagged for spec-owner sign-off (phase-05 plan, risk 4).
 *
 * Every §2.1 column that the spec marks NOT NULL is nullable HERE because the
 * row is born empty and the first-run wizard (§2.4) fills it in: completeness
 * is enforced by ConfirmFiscalIdentity (which refuses to confirm an
 * incomplete identity) and by AssertDocumentIdentityComplete (§2.2 inv. 5,
 * the print-path hard gate). An empty-and-blocking state is the 00-core §16
 * discipline; a NOT NULL column would just force fake placeholder values.
 *
 * NIU immutability once confirmed (§2.2 inv. 1) is enforced in the
 * FiscalIdentity model observer plus the CorrectFiscalIdentity Action - not
 * in a trigger, because the correction path is an application-level bypass a
 * trigger cannot see (phase-05 plan §1 Block A).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_identities', function (Blueprint $table): void {
            // NOT auto-increment: MySQL refuses a CHECK on an auto-increment
            // column, and the singleton CHECK below is the point. The row is
            // always inserted with an explicit id = 1.
            $table->unsignedBigInteger('id')->primary();

            // Registered legal name; may differ from the trading name held
            // in SchoolProfile settings.
            $table->string('legal_name', 200)->nullable();

            // etablissement_prive_laic | etablissement_confessionnel | sarl |
            // sa | association | fondation | gie | etablissement_individuel |
            // other. Exact enumeration NEEDS VERIFICATION (spec §2.1), hence
            // VARCHAR + CHECK, not a MySQL ENUM.
            $table->string('legal_form', 40)->nullable();

            // Numéro Identifiant Unique. VARCHAR not CHAR: the format spec is
            // NEEDS VERIFICATION and validation is warn-never-block until
            // verified, so a shorter-than-14 value must round-trip unpadded.
            // 00-core §4: identifier collation, accent/case sensitive.
            $table->string('niu', 14)->collation('utf8mb4_0900_as_cs')
                ->nullable()->unique();
            $table->date('niu_issued_on')->nullable();

            // Registre du Commerce et du Crédit Mobilier - mandatory when
            // legal_form is a commercial form (enforced in the Action).
            $table->string('rccm_number', 40)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->string('rccm_registry', 120)->nullable();
            $table->date('rccm_registered_on')->nullable();

            // Centre des impôts de rattachement.
            $table->string('tax_centre_code', 20)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->string('tax_centre_name', 160)->nullable();
            // DGE | CIME | CDI | CSI - load-bearing: selects the DSF due
            // date (§7.6).
            $table->string('tax_centre_type', 10)->nullable();

            // reel | simplifie | liberatoire | non_assujetti (§5).
            $table->string('tax_regime', 20)->nullable();
            $table->date('tax_regime_effective_from')->nullable();

            // Assujetti à la TVA. §2.2 inv. 2: true requires tax_regime =
            // 'reel' (whether simplifié may register is NEEDS VERIFICATION).
            $table->boolean('is_tva_registered')->default(false);
            $table->date('tva_registered_from')->nullable();

            // NOTE deliberately absent: cnps_employer_number lives ONLY in
            // EmployerProfile (05-hr-payroll); §2.1 forbids a second column.

            // Arrêté / autorisation d'ouverture - conditions the TVA
            // exemption on tuition and boarding (§5.2).
            $table->string('ministry_accreditation_number', 60)
                ->collation('utf8mb4_0900_as_cs')->nullable();
            // MINESEC | MINEDUB | MINEFOP | MINESUP | other.
            $table->string('ministry_accreditation_authority', 20)->nullable();
            $table->date('ministry_accreditation_date')->nullable();
            // Null = indefinite; when set and passed, §5.2 blocks exemption.
            $table->date('ministry_accreditation_expires_on')->nullable();
            // FK → documents deferred: the Documents module is Phase 13 and
            // its table does not exist yet. Plain column now, RESTRICT FK
            // added by the phase that creates `documents`.
            $table->unsignedBigInteger('ministry_accreditation_document_id')->nullable();

            // §2.3: OHADA pins the exercice to the calendar year. The columns
            // exist only to render the value on documents; CHECK-pinned.
            $table->unsignedTinyInteger('fiscal_year_end_month')->default(12);
            $table->unsignedTinyInteger('fiscal_year_end_day')->default(31);

            $table->foreignId('fiscal_identity_confirmed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('fiscal_identity_confirmed_at')->nullable();

            $table->timestamps();
        });

        // Singleton: the only legal primary key value is 1, so a second row
        // is a constraint violation, not an application bug to hunt.
        DB::statement(
            'ALTER TABLE fiscal_identities ADD CONSTRAINT chk_fiscal_identity_singleton CHECK (id = 1)'
        );
        DB::statement(
            'ALTER TABLE fiscal_identities ADD CONSTRAINT chk_fiscal_identity_fye '
            .'CHECK (fiscal_year_end_month = 12 AND fiscal_year_end_day = 31)'
        );
        DB::statement(
            'ALTER TABLE fiscal_identities ADD CONSTRAINT chk_fiscal_identity_regime '
            ."CHECK (tax_regime IS NULL OR tax_regime IN ('reel','simplifie','liberatoire','non_assujetti'))"
        );
        DB::statement(
            'ALTER TABLE fiscal_identities ADD CONSTRAINT chk_fiscal_identity_centre_type '
            ."CHECK (tax_centre_type IS NULL OR tax_centre_type IN ('DGE','CIME','CDI','CSI'))"
        );
        DB::statement(
            'ALTER TABLE fiscal_identities ADD CONSTRAINT chk_fiscal_identity_legal_form '
            ."CHECK (legal_form IS NULL OR legal_form IN ("
            ."'etablissement_prive_laic','etablissement_confessionnel','sarl','sa',"
            ."'association','fondation','gie','etablissement_individuel','other'))"
        );

        // NOTHING SEEDED - the wizard row is created by the first
        // ConfirmFiscalIdentity call, audited, never by a migration.
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_identities');
    }
};
