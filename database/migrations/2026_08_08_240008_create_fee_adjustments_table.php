<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §8 - C2. v1's FeeAdjustment had no foreign key to
 * anything; here `invoice_line_id` is NOT NULL because allocation is
 * line-level (A2) or reconciliation is impossible.
 *
 * `amount` is SIGNED - the whole reason Money is BIGINT SIGNED: positive
 * reduces outstanding, negative is a surcharge and INCREASES it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_adjustments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Own ADJ/{YYYY}/{######} series (§14).
            $table->string('reference_no', 40)->collation('utf8mb4_0900_as_cs');

            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->restrictOnDelete();
            // Denormalised from the line for direct querying (dual calendar).
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->enum('reason_type', [
                'correction', 'scholarship_internal', 'scholarship_donor_funded',
                'sibling_discount', 'staff_child', 'hardship',
                'early_payment_discount', 'surcharge_late_payment', 'goodwill',
            ]);
            $table->string('reason_note', 400);

            // Resolved from reason_type (§8.1); the donor-funded and surcharge
            // accounts are NEEDS-VERIFICATION and ship unseeded - the Action
            // refuses those reason types until the accountant configures them.
            $table->foreignId('adjustment_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('counterpart_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            // Suppliers/organisations land in another phase - plain reference.
            $table->unsignedBigInteger('donor_id')->nullable();

            $table->enum('application_method', [
                'pro_rata', 'earliest_first', 'latest_first', 'specific_instalment',
            ])->default('earliest_first');
            $table->foreignId('target_installment_id')->nullable()->constrained('invoice_installments')->restrictOnDelete();

            $table->date('effective_date');
            $table->enum('status', ['pending', 'approved', 'rejected', 'reversed'])->default('pending');

            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            // Corrections are new signed rows, never edits (A3).
            $table->foreignId('reversed_by_adjustment_id')->nullable()->constrained('fee_adjustments')->restrictOnDelete();
            $table->uuid('bulk_batch_id')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique('reference_no', 'uq_fee_adjustments_ref');
            $table->unique('reversed_by_adjustment_id', 'uq_fee_adjustments_reversal');
            $table->index(['student_id', 'status'], 'ix_fee_adjustments_student');
            $table->index(['invoice_line_id', 'status', 'effective_date'], 'ix_fee_adjustments_line');
        });

        DB::statement('ALTER TABLE fee_adjustments ADD CONSTRAINT ck_fa_amount CHECK (amount <> 0)');
        DB::statement("ALTER TABLE fee_adjustments ADD CONSTRAINT ck_fa_approved CHECK ((status = 'approved') = (approved_at IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_adjustments');
    }
};
