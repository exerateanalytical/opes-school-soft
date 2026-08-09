<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.8 - SupplierCreditNote (avoir
 * fournisseur), series `AVF/2026/000123`.
 *
 * `original_invoice_id` is FK RESTRICT and NULLABLE - a credit note may be
 * standalone (an annual rebate). Lines mirror invoice lines with the same
 * `tax_code_id` and `expense_account_id`; the posting reverses the original
 * scheme (Dr 401 / Cr 60x, Cr 4451). A credit note REDUCES the TVA
 * déductible already claimed - the declaration adjustment falls in the
 * period of the credit note (its own `credit_note_date`), never a
 * restatement of the original period.
 *
 * Deletion (§9): never hard-deleted once issued - the draft-only DELETE
 * trigger is the DB backstop; the module's Actions issue in one step, so a
 * lingering draft is already an anomaly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_credit_notes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('credit_note_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_supplier_credit_notes_no');
            // The supplier's own avoir number, when printed on the document.
            $table->string('supplier_reference', 60)->collation('utf8mb4_0900_as_cs')->nullable();

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('original_invoice_id')->nullable()->constrained('supplier_invoices')->restrictOnDelete();

            $table->string('reason_type', 30);
            $table->string('reason_note', 255);

            $table->date('credit_note_date');
            $table->date('received_date')->nullable();

            $table->char('currency', 3)->default('XAF');
            $table->bigInteger('exchange_rate_bp')->nullable();

            $table->bigInteger('subtotal_ht')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total_ttc')->default(0);

            $table->foreignId('payable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->string('status', 20)->default('draft');

            $table->dateTime('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('version')->default(0);
            $table->char('idempotency_key', 36)->nullable()->unique('uq_supplier_credit_notes_idem');

            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'ix_supplier_credit_notes_supplier');
            $table->index('original_invoice_id', 'ix_supplier_credit_notes_invoice');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_credit_notes
              ADD CONSTRAINT ck_supplier_credit_notes_status CHECK (
                status IN ('draft', 'issued', 'cancelled')
              ),
              ADD CONSTRAINT ck_supplier_credit_notes_reason CHECK (
                reason_type IN ('return', 'price_correction', 'quantity_correction', 'rebate', 'cancellation')
              )
        SQL);

        Schema::create('supplier_credit_note_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('supplier_credit_note_id')->constrained('supplier_credit_notes')->cascadeOnDelete();
            $table->unsignedInteger('line_no');

            $table->foreignId('supplier_invoice_line_id')->nullable()->constrained('supplier_invoice_lines')->restrictOnDelete();

            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit_of_measure', 20)->nullable();
            $table->bigInteger('unit_price_ht')->default(0);
            $table->bigInteger('amount_ht');

            // Same snapshot discipline as the invoice line (§4.8).
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->bigInteger('tax_rate_bp_applied')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('deductible_tax_amount')->default(0);
            $table->bigInteger('non_deductible_tax_amount')->default(0);

            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['supplier_credit_note_id', 'line_no'], 'uq_scnl_no');
            $table->index('supplier_invoice_line_id', 'ix_scnl_invoice_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_credit_note_lines
              ADD CONSTRAINT ck_scnl_tax_split CHECK (
                deductible_tax_amount + non_deductible_tax_amount = tax_amount
              )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_supplier_credit_notes_draft_only_delete
            BEFORE DELETE ON supplier_credit_notes
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A supplier credit note is never deleted once issued (03-tax-procurement 9)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_credit_notes_draft_only_delete');
        Schema::dropIfExists('supplier_credit_note_lines');
        Schema::dropIfExists('supplier_credit_notes');
    }
};
