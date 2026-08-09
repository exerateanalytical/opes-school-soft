<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The statutory return set and the CNPS worker-lifecycle records
 * (docs/specs/05-hr-payroll.md 11, fixing C5: v1 specified only DIPE and
 * would have left the DGI monthly salary return - the one with the most
 * aggressive penalty regime - unfiled).
 *
 * `dipe_layouts` ships with ONE UNPOPULATED row: the e-DIPE magnetic export
 * is implemented behind a layout definition object whose byte-level field
 * positions are NEEDS VERIFICATION (11.4 / 2.4). The export refuses until an
 * operator populates and activates it - a fabricated layout mis-records CNPS
 * pension quarters, and that harm surfaces decades later, irreversibly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statutory_declarations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 11.1's set + `staff_departure` (11.5: a required CNPS filing on
            // termination, materialised as a declaration row).
            $table->enum('type', [
                'dipe', 'cnps_contribution_schedule', 'dgi_monthly_salary_return',
                'tdl_remittance', 'annual_salary_return', 'cnps_annual',
                'staff_register', 'staff_departure',
            ]);

            $table->enum('payee', ['CNPS', 'DGI', 'Commune']);

            // Monthly types carry period_month (first day of month, 00-core 5);
            // annual types carry period_year. Exactly one is set (CHECK).
            $table->date('period_month')->nullable();
            $table->smallInteger('period_year')->nullable();

            // Only `staff_departure` names a person (CHECKs below).
            $table->unsignedBigInteger('staff_member_id')->nullable();
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            // NULL where the statutory deadline is NEEDS VERIFICATION (11.2:
            // TDL per-commune schedule, the annual returns, departures). The
            // screen shows "Deadline not configured" - a fabricated deadline
            // is worse than none.
            $table->date('due_date')->nullable();

            $table->enum('status', ['not_due', 'due', 'generated', 'filed', 'paid', 'late', 'rejected'])
                ->default('not_due');

            $table->timestamp('generated_at')->nullable();
            $table->timestamp('filed_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            // The receipt / acknowledgement number.
            $table->string('external_reference', 64)->nullable();

            $table->bigInteger('amount_declared')->nullable();
            $table->bigInteger('amount_paid')->nullable();

            // 11.3: late-payment surcharges are a DISTINCT recorded cost,
            // never netted into the contribution.
            $table->bigInteger('penalty_amount')->default(0);

            $table->json('generated_from_run_ids')->nullable();

            // No FK: the export artefact belongs to the Documents module.
            $table->unsignedBigInteger('export_document_id')->nullable();

            $table->unsignedBigInteger('filed_by')->nullable();
            $table->foreign('filed_by')->references('id')->on('users')->restrictOnDelete();

            // 11.1's UNIQUE (type, period_month), widened for annual types and
            // per-person departures; a single generated key handles NULLs.
            $table->string('dedupe_key', 96)->nullable()->storedAs(
                "CONCAT_WS('|', `type`,"
                ." IFNULL(DATE_FORMAT(`period_month`, '%Y-%m-%d'), ''),"
                ." IFNULL(`period_year`, ''),"
                ." IFNULL(`staff_member_id`, 0))"
            );

            $table->timestamps();

            $table->unique('dedupe_key', 'uq_declaration_period');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE statutory_declarations ADD CONSTRAINT ck_decl_period CHECK (
                (period_month IS NULL) <> (period_year IS NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_declarations ADD CONSTRAINT ck_decl_departure_staff CHECK (
                (type = 'staff_departure') = (staff_member_id IS NOT NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE statutory_declarations ADD CONSTRAINT ck_decl_penalty CHECK (penalty_amount >= 0)
        SQL);

        // 11.5: the work-accident register. Declaration deadline NEEDS
        // VERIFICATION - no fabricated due date column here either.
        Schema::create('work_accidents', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->dateTime('occurred_at');
            $table->string('location', 150);
            $table->text('description');
            $table->string('witness_names', 255)->nullable();

            $table->dateTime('declared_to_cnps_at')->nullable();
            $table->string('cnps_reference', 64)->nullable();

            $table->unsignedBigInteger('medical_certificate_document_id')->nullable();

            $table->decimal('days_lost', 5, 1)->default(0);

            $table->enum('status', ['open', 'declared', 'closed'])->default('open');

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE work_accidents ADD CONSTRAINT ck_wa_days_lost CHECK (days_lost >= 0)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE work_accidents ADD CONSTRAINT ck_wa_declared CHECK (
                status <> 'declared' OR declared_to_cnps_at IS NOT NULL
            )
        SQL);

        // 11.6: the employer ADVANCES maternity/accident allowances and then
        // claims reimbursement - a receivable v1 had no entity for.
        Schema::create('cnps_benefit_claims', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->enum('claim_type', ['maternity', 'work_accident', 'sickness', 'family_allowance']);

            $table->unsignedBigInteger('work_accident_id')->nullable();
            $table->foreign('work_accident_id')->references('id')->on('work_accidents')->restrictOnDelete();

            $table->date('period_from');
            $table->date('period_to');

            $table->bigInteger('amount_advanced');
            $table->bigInteger('amount_claimed');
            $table->bigInteger('amount_reimbursed')->default(0);

            $table->timestamp('submitted_at')->nullable();
            $table->string('cnps_reference', 64)->nullable();

            $table->enum('status', ['draft', 'submitted', 'part_reimbursed', 'reimbursed', 'rejected'])
                ->default('draft');

            // Stays NULL until 02-accounting confirms the CNPS-receivable
            // sub-account (NEEDS VERIFICATION); posting is withheld, the
            // entity and its ageing are not.
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE cnps_benefit_claims ADD CONSTRAINT ck_cbc_period CHECK (period_to >= period_from)
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE cnps_benefit_claims ADD CONSTRAINT ck_cbc_amounts CHECK (
                amount_advanced >= 0 AND amount_claimed >= 0 AND amount_reimbursed >= 0
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE cnps_benefit_claims ADD CONSTRAINT ck_cbc_accident_link CHECK (
                work_accident_id IS NULL OR claim_type = 'work_accident'
            )
        SQL);

        // 11.4: the DIPE layout definition object - field name, offset,
        // length, alignment, padding, format - ships UNPOPULATED.
        Schema::create('dipe_layouts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 32)->unique();
            $table->string('name', 100);

            $table->unsignedInteger('record_length')->nullable();
            $table->json('fields')->nullable();

            $table->boolean('is_active')->default(false);

            $table->string('source_citation', 255);
            $table->date('verified_on')->nullable();

            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE dipe_layouts ADD CONSTRAINT ck_dipe_active_needs_fields CHECK (
                is_active = FALSE OR fields IS NOT NULL
            )
        SQL);

        DB::table('dipe_layouts')->insert([
            'code' => 'edipe_magnetic',
            'name' => 'e-DIPE magnetic fixed-layout export',
            'record_length' => null,
            'fields' => null,
            'is_active' => false,
            'source_citation' => 'cnps.cm/images/pdf/dipe.pdf - exact field positions NEEDS VERIFICATION (05-hr-payroll 2.4/11.4)',
            'verified_on' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('dipe_layouts');
        Schema::dropIfExists('cnps_benefit_claims');
        Schema::dropIfExists('work_accidents');
        Schema::dropIfExists('statutory_declarations');
    }
};
