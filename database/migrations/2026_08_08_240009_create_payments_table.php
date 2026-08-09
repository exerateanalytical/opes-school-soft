<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §11.1 `payments` and §11.5 `payment_voids`.
 *
 * Payment is append-only (A3): after insert the only legitimate writes are
 * the clearing-state machine, the `unallocated_amount` derived cache and a
 * once-only `journal_entry_id` stamp - the Payment model's observer throws
 * on anything else. The void is a separate record; there is no
 * `payments.is_voided` column to drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            // §14: global uniqueness scope, gaps permitted (00-core §12).
            $table->string('receipt_no', 40)->collation('utf8mb4_0900_as_cs')->unique();
            // C10: a payment is against the STUDENT, not necessarily an invoice.
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('enrollment_id')->nullable()->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            // v1 hard decision: manual recording only - cash / mobile_money /
            // bank - so the method is an enum column, not a gateway config row.
            $table->string('payment_method', 30);
            // Gross amount received from the payer (Money, BIGINT SIGNED).
            $table->bigInteger('amount');
            // Operator/bank commission (§15.6). Zero for cash.
            $table->bigInteger('fee_amount')->default(0);
            $table->string('fee_bearer', 10)->default('none');
            $table->string('reference', 120)->nullable();
            // (H) The payer is frequently NOT the registered guardian.
            $table->string('payer_name', 200);
            $table->string('payer_phone', 30)->nullable();
            $table->date('value_date');
            $table->date('posting_date');
            $table->string('clearing_state', 10)->default('cleared');
            $table->date('bounced_on')->nullable();
            $table->string('bounce_reason', 200)->nullable();
            // Derived cache (§12.3), maintained only inside the §11.2 lock.
            $table->bigInteger('unallocated_amount')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_migration')->default(false);
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            // Protects the double-clicked Collect button.
            $table->string('idempotency_key', 80)->nullable()->unique();
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'academic_year_id']);
        });

        DB::statement(
            'ALTER TABLE payments'
            .' ADD CONSTRAINT chk_payments_amount CHECK (amount > 0),'
            .' ADD CONSTRAINT chk_payments_fee CHECK (fee_amount >= 0),'
            .' ADD CONSTRAINT chk_payments_unallocated CHECK (unallocated_amount >= 0)'
        );

        Schema::create('payment_voids', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->string('reason_type', 30);
            $table->string('reason_note', 400);
            $table->foreignId('voided_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at');
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->string('status', 20)->default('pending_approval');
            $table->timestamps();
        });

        // §11.5: "UNIQUE where status = 'confirmed'" - a payment can be
        // voided exactly once - via the 00-core §10.1 generated-column
        // pattern, since MySQL has no partial indexes.
        DB::statement(
            "ALTER TABLE payment_voids ADD COLUMN confirmed_payment_key BIGINT UNSIGNED"
            ." GENERATED ALWAYS AS (CASE WHEN status = 'confirmed' THEN payment_id ELSE NULL END) STORED"
        );
        DB::statement(
            'ALTER TABLE payment_voids ADD UNIQUE INDEX uq_payment_voids_confirmed (confirmed_payment_key)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_voids');
        Schema::dropIfExists('payments');
    }
};
