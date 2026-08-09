<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.7 - the payment correction and
 * disbursement plumbing:
 *
 *  - `supplier_payment_voids`: a paid payment is IMMUTABLE; reversal is
 *    this separate record (`supplier_payment_id` UNIQUE - voided at most
 *    once; reason mandatory; `reversal_journal_entry_id` the C9 reversal).
 *    `recorded_by <> voided_by` is enforced in the Action (§11.14) - the
 *    two ids live on different tables, out of CHECK's reach.
 *
 *  - `supplier_payment_batches`: groups approved payments into a bank
 *    transfer / MoMo bulk file. No specific bank layout is specified
 *    (§4.7: layouts NEEDS VERIFICATION per bank) - `export_format` names
 *    the generic format used and `file_hash` fingerprints what was handed
 *    to the bank.
 *
 *  - `supplier_retentions`: the §3.3 retenue de garantie working record.
 *    The reclass Cr 4817 happens at FIRST PAYMENT against the invoice
 *    (F3's invoice posting credits the payable with the FULL TTC and
 *    snapshots `retention_amount`; this table + the payment Action
 *    complete the §3.3 scheme), and `ReleaseRetention` later posts
 *    Dr 4817 / Cr 401 when the works are accepted. One row per invoice;
 *    `cancelled` marks a reclass undone by a payment void so a later
 *    payment may withhold again.
 *
 * This migration also adds the FKs DEFERRED by earlier pre-assigned
 * filenames: `withholding_attestations.supplier_payment_id` (250018 -
 * created before `supplier_payments` existed) and
 * `supplier_payments.batch_id` (250020, before batches existed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payment_voids', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('supplier_payment_id')
                ->unique('uq_spv_payment')
                ->constrained('supplier_payments')->restrictOnDelete();

            $table->string('reason', 255);
            $table->foreignId('voided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('voided_at');
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('supplier_payment_batches', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('batch_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_spb_no');

            $table->foreignId('bank_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->string('export_format', 30);

            $table->unsignedInteger('payment_count')->default(0);
            $table->bigInteger('total_amount')->default(0);

            $table->char('file_hash', 64)->nullable();
            $table->dateTime('exported_at')->nullable();
            $table->foreignId('exported_by')->nullable()->constrained('users')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('supplier_retentions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('supplier_invoice_id')
                ->unique('uq_sr_invoice')
                ->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // The payment whose recording withheld the retention.
            $table->foreignId('supplier_payment_id')->constrained('supplier_payments')->restrictOnDelete();

            // 4817-family account carrying the retention.
            $table->foreignId('retention_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->string('status', 10)->default('withheld');
            $table->date('release_due_on')->nullable();

            $table->foreignId('withheld_journal_entry_id')->constrained('journal_entries')->restrictOnDelete();

            $table->dateTime('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('release_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'ix_sr_supplier');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_retentions
              ADD CONSTRAINT ck_sr_status CHECK (
                status IN ('withheld', 'released', 'cancelled')
              ),
              ADD CONSTRAINT ck_sr_amount CHECK (amount > 0)
        SQL);

        // Deferred FK 1 (250018's docblock): attestations may now point at
        // the payment that triggered them.
        DB::statement(<<<'SQL'
            ALTER TABLE withholding_attestations
              ADD CONSTRAINT fk_wa_supplier_payment FOREIGN KEY (supplier_payment_id)
                REFERENCES supplier_payments (id) ON DELETE RESTRICT
        SQL);

        // Deferred FK 2 (250020): payments may now join a batch.
        DB::statement(<<<'SQL'
            ALTER TABLE supplier_payments
              ADD CONSTRAINT fk_supplier_payments_batch FOREIGN KEY (batch_id)
                REFERENCES supplier_payment_batches (id) ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE supplier_payments DROP FOREIGN KEY fk_supplier_payments_batch');
        DB::statement('ALTER TABLE withholding_attestations DROP FOREIGN KEY fk_wa_supplier_payment');
        Schema::dropIfExists('supplier_retentions');
        Schema::dropIfExists('supplier_payment_batches');
        Schema::dropIfExists('supplier_payment_voids');
    }
};
