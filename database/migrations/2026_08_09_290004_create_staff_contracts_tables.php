<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of employment truth (docs/specs/05-hr-payroll.md 3.4-3.6,
 * fixing defect H6: v1 had employment attributes on both the person and a
 * "versioned" contract, i.e. two sources of truth).
 *
 * Contracts are effective-dated and POSSIBLY CONCURRENT: one person may hold
 * a teaching contract and a boarding-master contract at once. The 00-core
 * 10.1 "one active X" generated-column pattern is therefore applied per
 * `contract_role`, not per staff member.
 *
 * `active_role_key` uses `ends_on IS NULL` rather than the spec's
 * CURDATE() comparison because MySQL forbids non-deterministic functions in
 * generated columns. The DB constraint therefore guards the dangerous case -
 * two concurrent OPEN-ENDED contracts on one role - and the date-overlap
 * check for dated contracts runs in OpenStaffContract under FOR UPDATE on
 * the staff member row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_contracts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            // e.g. `teaching`, `boarding`, `administration`. The concurrency key.
            $table->string('contract_role', 32);

            // Split from working time (3.4): a CDD may be full-time and a CDI
            // may be hourly; v1 conflated the two axes.
            $table->enum('contract_type', ['cdi', 'cdd', 'temporaire', 'occasionnel', 'saisonnier', 'apprentissage', 'stage']);
            $table->enum('working_time', ['full_time', 'part_time', 'hourly']);

            $table->unsignedBigInteger('department_id');
            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();

            $table->unsignedBigInteger('position_id');
            $table->foreign('position_id')->references('id')->on('positions')->restrictOnDelete();

            // NULL for pure hourly staff (vacataires).
            $table->unsignedBigInteger('salary_grade_id')->nullable();
            $table->foreign('salary_grade_id')->references('id')->on('salary_grades')->restrictOnDelete();

            // NULL until 2.4 resolves which convention covers the school.
            $table->unsignedBigInteger('collective_agreement_id')->nullable();
            $table->foreign('collective_agreement_id')->references('id')->on('collective_agreements')->restrictOnDelete();

            // Printed on the payslip - legally mandatory (14.1).
            $table->string('category', 16)->nullable();
            $table->string('echelon', 16)->nullable();

            $table->date('starts_on');
            // Exclusive. NOT NULL required when contract_type = 'cdd' (CHECK).
            $table->date('ends_on')->nullable();
            // Validation blocked pending 2.4 (probation limits unverified).
            $table->date('probation_end')->nullable();

            $table->unsignedInteger('renewal_count')->default(0);

            $table->unsignedBigInteger('renewed_from_contract_id')->nullable();
            $table->foreign('renewed_from_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            // Set when a CDD chain hits the 2-year/one-renewal limit and
            // converts by operation of law (3.4 CDD invariant).
            $table->date('converted_to_cdi_on')->nullable();

            // Required when the staff member's nationality is non-Cameroonian.
            $table->string('mintss_visa_ref', 64)->nullable();

            // 3.5 - the cases v1 could not express, incl. detache_etat
            // (seconded State teacher, extremely common in private schools).
            $table->enum('social_security_status', [
                'affilie_cnps', 'assurance_volontaire', 'convention_bilaterale', 'detache_etat', 'exempt_other',
            ])->default('affilie_cnps');

            // Distinct from StaffMember.status: a suspended person's pay
            // treatment follows the suspension decision, not the status enum.
            $table->boolean('is_payroll_eligible')->default(true);

            // 3.2: gated on payroll.override_risk_class, reason mandatory
            // when set (CHECK below), surfaced on the run exception report.
            $table->string('rp_risk_class_override', 8)->nullable();
            $table->string('override_reason', 255)->nullable();

            // Drives prime d'anciennete; may predate starts_on on conversion.
            $table->date('seniority_reference_date');

            $table->enum('termination_reason', [
                'resignation', 'licenciement', 'licenciement_faute_lourde', 'fin_cdd', 'retirement', 'death', 'mutual',
            ])->nullable();

            $table->string('active_role_key', 32)->nullable()
                ->storedAs('CASE WHEN `ends_on` IS NULL THEN `contract_role` END');

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['staff_member_id', 'active_role_key'], 'uq_active_contract_role');
        });

        DB::statement(
            'ALTER TABLE staff_contracts ADD CONSTRAINT ck_staff_contracts_dates CHECK (ends_on IS NULL OR ends_on > starts_on)'
        );
        DB::statement(
            "ALTER TABLE staff_contracts ADD CONSTRAINT ck_staff_contracts_cdd_end CHECK (contract_type <> 'cdd' OR ends_on IS NOT NULL)"
        );
        DB::statement(
            'ALTER TABLE staff_contracts ADD CONSTRAINT ck_staff_contracts_override_reason CHECK (rp_risk_class_override IS NULL OR override_reason IS NOT NULL)'
        );

        // 3.5: per-branch statutory exemptions. Every exemption is a claim
        // the labour inspector will test - document ref NOT NULL, approver
        // recorded, and each one appears on the run's exception report.
        Schema::create('staff_contract_exemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->enum('branch', ['PVID', 'PF', 'RP', 'IRPP', 'CFC', 'FNE', 'RAV', 'TDL']);

            $table->date('effective_from');
            $table->date('effective_to')->nullable();

            $table->string('exemption_document_ref', 64);

            $table->unsignedBigInteger('approved_by');
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['staff_contract_id', 'branch', 'effective_from'], 'uq_contract_exemption');
        });

        DB::statement(
            'ALTER TABLE staff_contract_exemptions ADD CONSTRAINT ck_exemptions_dates CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );

        // 3.6: replaces the deleted dependants_count. Feeds CNPS
        // family-allowance entitlement (a benefit CNPS PAYS). It is NOT an
        // input to any contribution rate and NOT an input to IRPP (defect N3).
        Schema::create('staff_dependants', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->string('full_name', 150);
            $table->enum('relationship', ['child', 'spouse', 'parent', 'ward', 'other']);
            $table->date('date_of_birth');
            $table->boolean('is_schooled')->default(false);
            $table->boolean('cnps_allowance_eligible')->default(false);

            // No FK: `documents` belongs to Phase 13.
            $table->unsignedBigInteger('birth_certificate_document_id')->nullable();

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->unique(['staff_member_id', 'full_name', 'date_of_birth'], 'uq_staff_dependant');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_dependants');
        Schema::dropIfExists('staff_contract_exemptions');
        Schema::dropIfExists('staff_contracts');
    }
};
