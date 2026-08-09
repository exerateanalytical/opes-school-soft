<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §9 - C7. Over-invoicing requires a facture d'avoir
 * under OHADA: a document with its own legal identity and its own sequence,
 * mirroring Invoice, never an "adjustment".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // Own series, AV/{YYYY}/{######} by default (§14). NULL in draft.
            $table->string('credit_note_no', 40)->nullable()->collation('utf8mb4_0900_as_cs');

            // A credit note always references the invoice it corrects.
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->date('issue_date');
            $table->enum('reason_type', [
                'over_invoiced', 'service_not_delivered', 'withdrawal',
                'duplicate_invoice', 'price_correction', 'goodwill',
            ]);
            $table->string('reason_note', 400);
            $table->enum('status', ['draft', 'issued', 'cancelled'])->default('draft');
            $table->enum('settlement_mode', ['apply_to_account', 'refund'])->default('apply_to_account');

            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            // SHA-256 of the issued PDF (00-core §13/§14 discipline).
            $table->char('printed_pdf_hash', 64)->nullable();
            $table->string('idempotency_key', 80)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique('credit_note_no', 'uq_credit_notes_no');
            $table->unique('idempotency_key', 'uq_credit_notes_idem');
            $table->index(['student_id', 'status'], 'ix_credit_notes_student');
        });

        Schema::create('credit_note_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('credit_note_id')->constrained('credit_notes')->restrictOnDelete();
            $table->foreignId('invoice_line_id')->constrained('invoice_lines')->restrictOnDelete();
            // Snapshots, same discipline as invoice_lines.
            $table->string('description', 200);
            $table->bigInteger('amount');
            $table->bigInteger('tax_amount')->default(0);
            $table->foreignId('revenue_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->enum('collection_basis', ['own_revenue', 'agent_for_third_party']);
            $table->timestamps();

            $table->unique(['credit_note_id', 'invoice_line_id'], 'uq_cn_lines_target');
        });

        DB::statement('ALTER TABLE credit_note_lines ADD CONSTRAINT ck_cnl_amount CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_lines');
        Schema::dropIfExists('credit_notes');
    }
};
