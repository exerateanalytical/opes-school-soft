<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payroll run (docs/specs/05-hr-payroll.md 8.1, fixing H3): statutory
 * amounts are computed EMPLOYER-WIDE, once per month - never per section.
 * The ceiling, the IRPP annualisation and the RAV/TDL bands are per
 * employee per employer per month; section cost allocation happens
 * DOWNSTREAM of approval, on the ledger's analytic axis, via the Money
 * Allocator (8.1, 3.7).
 *
 * `active_key` is the 00-core 10.1 generated-column pattern: one
 * non-cancelled run per (payroll_month, run_type, employer_profile_id).
 * A cancelled run's key collapses to NULL and escapes the UNIQUE, which is
 * what lets a reversed month be recalculated.
 *
 * `reverses_run_id` is UNIQUE - a run is reversed at most once, and
 * reversing a reversal is refused in the Action (8.7).
 *
 * `inputs_hash` is set at calculate over the canonical input serialisation
 * (8.3) and RE-VERIFIED at approve: a run someone approved is a run
 * someone reviewed, so a changed input fails approval rather than being
 * silently recalculated.
 *
 * Posting on approval goes through Accounting's PostFromEvent
 * ('payroll.approved') and lands `journal_entry_id` here - there is no
 * second posting path anywhere in this module. No PostingRule is seeded
 * with account codes: the payroll accounts (66x staff cost, 42x staff
 * payable, 43x social security, 44x State) are NOT in the shipped chart
 * shell, so the rule - like every statutory rate in this module - is
 * configuration the school's accountant supplies (05 §0 asymmetry: an
 * empty rule stops a run for an afternoon; a guessed account survives an
 * audit cycle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // First day of month - 00-core 5 vocabulary.
            $table->date('payroll_month');

            $table->enum('run_type', [
                'regular', 'thirteenth_month', 'final_settlement', 'regularisation', 'reversal',
            ]);

            $table->enum('status', [
                'draft', 'calculating', 'calculated', 'approved', 'paid', 'closed', 'cancelled',
            ])->default('draft');

            // 02-accounting C3: BOTH calendars, plus the period the entry
            // will land in.
            $table->unsignedBigInteger('fiscal_year_id');
            $table->foreign('fiscal_year_id')->references('id')->on('fiscal_years')->restrictOnDelete();

            $table->unsignedBigInteger('academic_year_id');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->restrictOnDelete();

            $table->unsignedBigInteger('accounting_period_id');
            $table->foreign('accounting_period_id')->references('id')->on('accounting_periods')->restrictOnDelete();

            // The profile VERSION in force at period end (3.1).
            $table->unsignedBigInteger('employer_profile_id');
            $table->foreign('employer_profile_id')->references('id')->on('employer_profiles')->restrictOnDelete();

            // SHA-256 over the canonical input serialisation, set at
            // calculate (8.3). NULL only while draft.
            $table->char('inputs_hash', 64)->nullable();

            $table->unsignedBigInteger('calculated_by')->nullable();
            $table->foreign('calculated_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('calculated_at')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable();
            $table->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->foreign('cancelled_by')->references('id')->on('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();

            // UNIQUE: a run is reversed at most once; reversing a reversal
            // is refused in ReversePayrollRun (8.7).
            $table->unsignedBigInteger('reverses_run_id')->nullable()->unique();
            $table->foreign('reverses_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();

            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->foreign('journal_entry_id')->references('id')->on('journal_entries')->restrictOnDelete();

            // 00-core 6.2 rule 7.
            $table->string('idempotency_key', 64)->nullable()->unique();

            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            // 00-core 10.1: the UNIQUE bites only while the run is alive.
            $table->boolean('active_key')->nullable()
                ->storedAs("CASE WHEN `status` <> 'cancelled' THEN 1 END");

            $table->unique(
                ['payroll_month', 'run_type', 'employer_profile_id', 'active_key'],
                'uq_payroll_runs_active',
            );
        });

        // A reversal run must name what it reverses; nothing else may.
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs ADD CONSTRAINT ck_pr_reversal_target CHECK (
                (run_type = 'reversal') = (reverses_run_id IS NOT NULL)
            )
        SQL);

        // A cancelled run records why, by whom and when.
        DB::statement(<<<'SQL'
            ALTER TABLE payroll_runs ADD CONSTRAINT ck_pr_cancellation CHECK (
                status <> 'cancelled'
                OR (cancellation_reason IS NOT NULL AND cancelled_by IS NOT NULL)
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
