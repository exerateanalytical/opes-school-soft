<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §10.5-§10.7 - library_fines is the
 * ASSESSMENT record (why, how many days, which copy); the student DEBT
 * lives in Fees, joined through `invoice_id` when the fine is levied
 * through the Fees invoicing door (§10.7: exactly ONE student debt
 * stream, never a parallel receivable).
 *
 * - `settlement_route` is derived from member_type at levy time and
 *   SNAPSHOTTED, so a member who converts (former pupil hired as staff)
 *   does not reroute historic fines.
 * - Staff fines stay OUT of Fees: `payroll_deduction_id` ships as an
 *   unconstrained nullable BIGINT (Phase 11 owns the payroll-deduction
 *   table and adds the FK); nothing posts until payroll runs.
 * - `uq_overdue_fine_issue`: ONE overdue fine per issue, ever - the
 *   nightly accrual recomputes the entitlement on that single row
 *   (idempotent, §10.5), it never appends a fine per night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_fines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('fine_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_library_fines_no');

            // NULL: a damage fine can exist without an overdue issue.
            $table->foreignId('library_issue_id')->nullable()
                ->constrained('library_issues')->restrictOnDelete();

            $table->foreignId('library_member_id')
                ->constrained('library_members')->restrictOnDelete();

            // §10.3 keying: the debt survives the year, so it keys on the
            // student even though the membership keys on the enrollment.
            $table->foreignId('student_id')->nullable()
                ->constrained('students')->restrictOnDelete();

            $table->enum('fine_type', ['overdue', 'damage', 'loss', 'other']);

            $table->date('assessed_on');
            $table->smallInteger('days_overdue')->nullable();

            $table->bigInteger('amount');
            $table->bigInteger('waived_amount')->default(0);

            $table->foreignId('waived_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->string('waived_reason', 255)->nullable();

            // Waiver segregation (§10.6): approver may not be the levier.
            $table->foreignId('levied_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->enum('status', ['assessed', 'invoiced', 'paid', 'waived', 'written_off'])
                ->default('assessed');

            // Students, §10.7: the fine's debt is this invoice.
            $table->foreignId('invoice_id')->nullable()
                ->constrained('invoices')->restrictOnDelete();

            // Waiver of an invoiced fine is a Fees credit note (contra-
            // revenue against the same income account, §10.6).
            $table->foreignId('credit_note_id')->nullable()
                ->constrained('credit_notes')->restrictOnDelete();

            // Staff, §10.7: FK added by Phase 11 (payroll deductions).
            $table->unsignedBigInteger('payroll_deduction_id')->nullable();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->enum('settlement_route', [
                'student_receivable', 'staff_payroll_deduction', 'cash_immediate',
            ]);

            $table->string('idempotency_key', 100)->nullable()
                ->unique('uq_library_fines_idem');

            $table->timestamps();

            $table->index(['library_member_id', 'status'], 'ix_library_fines_member_status');
            $table->index(['student_id', 'status'], 'ix_library_fines_student_status');
        });

        DB::statement(
            'ALTER TABLE library_fines ADD CONSTRAINT chk_library_fines_amounts CHECK '
            .'(amount >= 0 AND waived_amount >= 0 AND waived_amount <= amount)'
        );

        // One overdue-entitlement row per issue (see class docblock). Other
        // fine types stay out of the key (NULL), so damage + loss fines can
        // coexist with an overdue fine on the same issue.
        DB::statement(
            "ALTER TABLE library_fines ADD COLUMN overdue_issue_key BIGINT UNSIGNED "
            ."GENERATED ALWAYS AS (CASE WHEN fine_type = 'overdue' THEN library_issue_id END) STORED"
        );
        DB::statement(
            'ALTER TABLE library_fines ADD CONSTRAINT uq_overdue_fine_issue UNIQUE (overdue_issue_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('library_fines');
    }
};
