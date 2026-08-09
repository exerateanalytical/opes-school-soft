<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The persisted preflight checklist (docs/specs/05-hr-payroll.md 9.1): the
 * bursar sees a checklist, not a stack trace, and each failing row links
 * to the settings screen that fixes it.
 *
 * PayrollPreflightCheck replaces a run's result set atomically on every
 * execution and COMMITS it even when the run itself is refused - the
 * refusal writes no payroll item, no line and no ledger entry, but the
 * reasons for it must survive the rollback or the screen has nothing to
 * show. Every failure is fatal except check 15 (unfiled prior
 * declarations), which is a warning: a school in arrears still needs to
 * pay its staff.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_preflight_results', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('payroll_run_id');
            $table->foreign('payroll_run_id')->references('id')->on('payroll_runs')->restrictOnDelete();

            // 9.1's stable check identifiers, e.g. EMPLOYER_PROFILE_MISSING,
            // STATUTORY_RATE_UNRESOLVED, DUPLICATE_PAYROLL_MONTH.
            $table->string('check_code', 64);

            $table->enum('status', ['pass', 'fail', 'warning']);

            // Machine-readable specifics: offending codes, staff_no lists,
            // uncovered ranges - what the screen renders and links from.
            $table->json('detail');

            $table->timestamp('checked_at');

            $table->timestamps();

            $table->unique(['payroll_run_id', 'check_code'], 'uq_preflight_run_check');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_preflight_results');
    }
};
