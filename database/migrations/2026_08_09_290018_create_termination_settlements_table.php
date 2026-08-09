<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The termination settlement (docs/specs/05-hr-payroll.md 13, fixing H9).
 *
 * `indemnite_licenciement` CANNOT be computed: the severance schedule
 * (Arrêté 016/MTPS/SG/CJ of 26/05/1993) is NEEDS VERIFICATION and sources
 * conflict materially (2.4). It is enterable manually with a MANDATORY
 * `indemnite_basis_note` (CHECK below) - computing severance from a guessed
 * schedule produces a number an employee will litigate.
 *
 * The exempt/taxable IRPP split of severance is likewise manual until the
 * exemption rule is verified; the two portions are set together (CHECK).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termination_settlements', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // ONE settlement per contract, forever.
            $table->unsignedBigInteger('staff_contract_id')->unique();
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            // Mirrors staff_contracts.termination_reason.
            $table->enum('termination_type', [
                'resignation', 'licenciement', 'licenciement_faute_lourde',
                'fin_cdd', 'retirement', 'death', 'mutual',
            ]);

            $table->date('notice_start')->nullable();
            $table->date('notice_end')->nullable();
            $table->boolean('notice_served')->default(false);

            $table->date('last_working_day');
            $table->date('settlement_date')->nullable();

            // From StaffContract.seniority_reference_date.
            $table->decimal('seniority_years', 5, 2);

            $table->bigInteger('indemnite_licenciement')->nullable();
            $table->string('indemnite_basis_note', 255)->nullable();

            $table->bigInteger('indemnite_compensatrice_preavis')->nullable();

            // Monetised from the LeaveAccrual ledger balance; the RATE is
            // manual while the allocation formula's inputs are unverified.
            $table->bigInteger('leave_compensation')->nullable();

            $table->json('other_amounts')->nullable();

            // Severance IRPP treatment: only taxable_portion enters SBT.
            $table->bigInteger('exempt_portion')->nullable();
            $table->bigInteger('taxable_portion')->nullable();

            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();

            // The final_settlement run (F3's payroll_runs; FK attaches below
            // when the table exists - same coordination note as
            // 2026_08_09_290015).
            $table->unsignedBigInteger('payroll_run_id')->nullable();

            // No FKs: departure documents live in the Documents module.
            $table->unsignedBigInteger('certificat_travail_document_id')->nullable();
            $table->unsignedBigInteger('solde_de_tout_compte_document_id')->nullable();

            $table->unsignedBigInteger('created_by');
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE termination_settlements ADD CONSTRAINT ck_ts_indemnite_basis CHECK (
                indemnite_licenciement IS NULL OR indemnite_basis_note IS NOT NULL
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE termination_settlements ADD CONSTRAINT ck_ts_notice CHECK (
                notice_end IS NULL OR notice_start IS NULL OR notice_end >= notice_start
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE termination_settlements ADD CONSTRAINT ck_ts_split_together CHECK (
                (exempt_portion IS NULL) = (taxable_portion IS NULL)
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE termination_settlements ADD CONSTRAINT ck_ts_seniority CHECK (seniority_years >= 0)
        SQL);

        if (Schema::hasTable('payroll_runs')) {
            Schema::table('termination_settlements', function (Blueprint $table): void {
                $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('termination_settlements');
    }
};
