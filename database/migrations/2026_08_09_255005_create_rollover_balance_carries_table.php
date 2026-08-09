<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/08-operations.md §6.2 step 7 - the per-student outcome record
 * for "carry balances forward". Credit balances carry into the new year via
 * Fees\Actions\CarryForwardStudentCredit -> Accounting\Actions\PostFromEvent
 * (the ONLY posting path); debit balances stay on the old year's invoices
 * with an explicit per-student choice recorded here. Never nets across
 * students (04-fees C9) - hence one row per student per kind, by constraint.
 *
 * `amount` is minor-unit XAF (BIGINT like all Money columns) and always the
 * absolute value of the balance the kind acts on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollover_balance_carries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('rollover_run_id')->constrained('rollover_runs')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();

            $table->enum('kind', ['credit_carry', 'debt_carry', 'write_off', 'block']);
            $table->bigInteger('amount');

            // Set only for kinds that post (credit_carry, write_off);
            // NULL for debt_carry (the debt stays on the old invoices) and
            // block (an enrolment gate, not a ledger event).
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['rollover_run_id', 'student_id', 'kind'], 'uq_rollover_carries_outcome');
            $table->index(['student_id'], 'ix_rollover_carries_student');
        });

        DB::statement('ALTER TABLE rollover_balance_carries ADD CONSTRAINT ck_rbc_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('rollover_balance_carries');
    }
};
