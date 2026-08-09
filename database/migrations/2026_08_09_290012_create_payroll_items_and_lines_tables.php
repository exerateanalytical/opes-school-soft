<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One PayrollItem per staff member per run (docs/specs/05-hr-payroll.md
 * 10.2) - the live row, mutable only while its run is draft/calculating.
 *
 * The TWO CNPS bases at row level are the N1 fix: `cnps_capped_base`
 * = min(SBC, ceiling) feeds PVID and PF; `cnps_uncapped_base` = SBC feeds
 * Risques Professionnels, which has NO ceiling.
 *
 * Cross-run double-payment protection (00-core 10.4, 8.1): `payroll_month`
 * is denormalised onto the item and `active_month` is a stored generated
 * column that collapses to NULL when the item's run is cancelled
 * (`is_cancelled`, stamped by ReversePayrollRun inside the same
 * transaction that cancels the run). UNIQUE(active_month, staff_member_id)
 * therefore spans ALL runs: paying one person twice for one month is a
 * constraint violation, not a race - while a reversed month can be re-run.
 *
 * `payroll_lines` carries base + rate PER LINE because the payslip legally
 * must print each deduction with its base AND its rate (14.1), and
 * `statutory_rate_id` is the provenance link H1 was missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_items', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_run_id');
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();

            $table->unsignedBigInteger('staff_member_id');
            $table->foreign('staff_member_id')->references('id')->on('staff_members')->restrictOnDelete();

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            // Denormalised from the run for the cross-run UNIQUE below.
            $table->date('payroll_month');
            $table->boolean('is_cancelled')->default(false);

            // DIPE requires days worked per employee per month (11.4);
            // mis-recording them corrupts CNPS pension quarters decades out.
            $table->decimal('days_worked', 5, 2);
            $table->decimal('days_in_period', 5, 2);
            $table->decimal('hours_validated', 7, 2)->nullable();

            $table->bigInteger('gross');
            $table->bigInteger('sbt');
            $table->bigInteger('cnps_capped_base');
            $table->bigInteger('cnps_uncapped_base');

            // NC_annual / 12 - display figure for the payslip (10.2).
            $table->bigInteger('taxable_base');

            // CAC's basis (2.2): the ROUNDED monthly IRPP actually withheld.
            $table->bigInteger('irpp_amount');

            $table->bigInteger('total_employee_deductions');
            $table->bigInteger('total_employer_charges');
            $table->bigInteger('net');

            // 6.5 YTD-cumulative inputs, INCLUDING this month.
            $table->bigInteger('ytd_sbt');
            $table->bigInteger('ytd_irpp_withheld');

            // Exemptions, overrides, missing IRPP floor - the run's
            // exception report reads from here.
            $table->json('exception_flags');

            $table->timestamps();

            $table->unique(['payroll_run_id', 'staff_member_id'], 'uq_payroll_items_run_staff');

            $table->date('active_month')->nullable()
                ->storedAs('CASE WHEN `is_cancelled` = 0 THEN `payroll_month` END');

            // The constraint that makes double payment structurally
            // impossible across ALL runs (00-core 10.4).
            $table->unique(['active_month', 'staff_member_id'], 'uq_payroll_items_month_staff');
        });

        // 8.6: gross - employee deductions = net, EXACTLY, no tolerance -
        // asserted in the Action before persist and re-asserted here so no
        // write path can smuggle in an off-by-one-franc row.
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_items ADD CONSTRAINT ck_pi_net_identity CHECK (
                gross - total_employee_deductions = net
            )
        SQL);

        Schema::create('payroll_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_item_id');
            $table->foreign('payroll_item_id')->references('id')->on('payroll_items')->restrictOnDelete();

            $table->unsignedBigInteger('payroll_component_id');
            $table->foreign('payroll_component_id')->references('id')->on('payroll_components')->restrictOnDelete();

            // H1's missing provenance link: WHICH rate row produced this
            // amount. RESTRICT plus the 4.4 lock makes history immutable.
            $table->unsignedBigInteger('statutory_rate_id')->nullable();
            $table->foreign('statutory_rate_id')->references('id')->on('statutory_rates')->restrictOnDelete();

            // Printed on the payslip - legally required (14.1).
            $table->bigInteger('base_amount');
            $table->bigInteger('applied_rate_bp')->nullable();
            $table->bigInteger('applied_flat_amount')->nullable();

            // Per-bracket breakdown for IRPP.
            $table->json('bracket_detail')->nullable();

            $table->bigInteger('amount');

            // 5.1 rappel: which month this arrears line compensates.
            $table->date('arrears_for_month')->nullable();

            $table->timestamps();

            // MySQL exempts NULLs from UNIQUE, so the non-arrears case
            // needs a NULL-collapsing key: one line per component per item,
            // plus one ARREARS line per compensated month.
            $table->date('arrears_key')->nullable(false)
                ->storedAs("COALESCE(`arrears_for_month`, '1000-01-01')");

            $table->unique(
                ['payroll_item_id', 'payroll_component_id', 'arrears_key'],
                'uq_payroll_lines_component',
            );
        });

        // 5.7: the deduction-cap excess is never discarded - it carries
        // forward and is re-presented next month; dropping it silently
        // would make a loan un-repayable.
        Schema::create('deduction_carry_forwards', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('staff_contract_id');
            $table->foreign('staff_contract_id')->references('id')->on('staff_contracts')->restrictOnDelete();

            $table->string('source_component_code', 24)->collation('utf8mb4_0900_as_cs');
            $table->foreign('source_component_code')->references('code')->on('payroll_components')->restrictOnDelete();

            $table->bigInteger('amount');

            $table->date('created_from_payroll_month');
            $table->timestamp('settled_at')->nullable();

            $table->timestamps();

            $table->index(
                ['staff_contract_id', 'settled_at'],
                'ix_dcf_contract_open',
            );
        });

        DB::statement(<<<'SQL'
            ALTER TABLE deduction_carry_forwards ADD CONSTRAINT ck_dcf_amount CHECK (amount > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deduction_carry_forwards');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payroll_items');
    }
};
