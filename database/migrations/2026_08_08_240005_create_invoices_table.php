<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §3 - the invoice header.
 *
 * Issue idempotency (00-core §10.3/§10.4): UNIQUE(enrollment_id,
 * fee_structure_id, term_id) - but fee_structure_id and term_id are nullable
 * and MySQL treats every NULL in a unique index as distinct, so the index
 * rides on generated sentinel-0 companions. The enrollment key itself is
 * NULL for non-`standard` rows AND for non-`issued` rows: only an ISSUED
 * standard invoice binds the constraint, which is what exempts supplementary
 * and opening-balance invoices (§4.6) from it, and what lets a CANCELLED
 * invoice coexist with (and be re-generated alongside) its replacement -
 * drafts are guarded by GenerateInvoices' locked pre-check, not the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // NULL while draft; allocated ONLY at issue, by SequenceAllocator
            // inside the issuing transaction (00-core §12, gaps permitted).
            $table->string('invoice_no', 40)->nullable()->collation('utf8mb4_0900_as_cs');

            // 00-core §10.5: financial records are never cascaded into.
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            // Denormalised for query paths that must not join through enrollment.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            // Dual calendar (02-accounting C3).
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->foreignId('fee_structure_id')->nullable()->constrained('fee_structures')->restrictOnDelete();
            $table->unsignedInteger('fee_structure_version')->nullable();
            $table->foreignId('term_id')->nullable()->constrained('assessment_periods')->restrictOnDelete();
            $table->foreignId('installment_plan_id')->nullable()->constrained('installment_plans')->restrictOnDelete();

            $table->enum('type', ['standard', 'supplementary', 'opening_balance'])->default('standard');

            $table->date('issue_date');
            $table->date('due_date');
            $table->char('currency', 3)->default('XAF');

            $table->enum('status', ['draft', 'issued', 'cancelled'])->default('draft');
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cancellation_reason', 400)->nullable();

            // Migrated invoices do NOT re-trigger posting rules (02-accounting).
            $table->boolean('is_migration')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->text('notes')->nullable();

            // Optimistic lock (00-core §10.6).
            $table->unsignedInteger('version')->default(0);
            $table->string('idempotency_key', 80)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('invoice_no', 'uq_invoices_no');
            $table->unique('idempotency_key', 'uq_invoices_idem');
            $table->index(['student_id', 'status'], 'ix_invoices_student');
            $table->index(['academic_year_id', 'status'], 'ix_invoices_year');
        });

        // Sentinel companions for the issue-idempotency UNIQUE (§3, 00-core
        // §10.3). The enrollment key is NULL (index-exempt) off the
        // standard+issued path, and MySQL sentinels stand in for the
        // nullable dimensions.
        DB::statement(<<<'SQL'
            ALTER TABLE invoices
              ADD COLUMN uq_enrollment_key BIGINT UNSIGNED
                  GENERATED ALWAYS AS (CASE WHEN type = 'standard' AND status = 'issued' THEN enrollment_id ELSE NULL END) STORED,
              ADD COLUMN uq_fee_structure_key BIGINT UNSIGNED
                  GENERATED ALWAYS AS (COALESCE(fee_structure_id, 0)) STORED,
              ADD COLUMN uq_term_key BIGINT UNSIGNED
                  GENERATED ALWAYS AS (COALESCE(term_id, 0)) STORED,
              ADD UNIQUE INDEX uq_invoices_issue_idem (uq_enrollment_key, uq_fee_structure_key, uq_term_key)
        SQL);

        DB::statement('ALTER TABLE invoices ADD CONSTRAINT ck_invoices_dates CHECK (due_date >= issue_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
