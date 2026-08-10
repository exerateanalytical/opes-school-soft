<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §21.3 "Expense capture" - the screen v1 never
 * described: how an operator records that the school paid 125 000 FCFA cash
 * for library books.
 *
 * `Expense` is a LIGHT document over the ledger, not a second ledger: it
 * carries a payee, a treasury account (the float that actually paid), and
 * expense lines, and its only monetary consequence is the journal entry
 * `PostExpense` obtains from `PostFromEvent` on the `expense.recorded`
 * event. `journal_entry_id` FK RESTRICT is the link, exactly as
 * supplier_invoices does it.
 *
 * Where the payee is a REGISTERED supplier the Procurement flow (03) is
 * used instead; this document exists for the petty, unregistered,
 * cash-and-receipt case. `payee_id` is therefore a plain unsigned integer
 * with NO foreign key - it points at suppliers OR users depending on
 * `payee_type`, the same polymorphic-without-FK convention as
 * `visitor_logs.host_id` (00-core §6.2: no cross-module FK on a
 * discriminated reference).
 *
 * Money is BIGINT SIGNED whole FCFA (00-core §7.1), computed in Money in
 * the Actions and never by SQL; `total_amount` is a stored snapshot that
 * the CHECK constraints keep non-negative, and the line CHECK keeps every
 * line strictly positive so a "balancing" negative line can never smuggle a
 * credit into an expense.
 *
 * Maker-checker (§21.3, "above a configurable threshold") is enforced in
 * `ApproveExpense` rather than here: the threshold is a Setting and can
 * change, so the database records WHO submitted and WHO approved and the
 * trigger below only guarantees that a non-draft expense is never silently
 * deleted.
 *
 * `treasury_account_id` FKs `chart_of_accounts` - the class-5 convention
 * `supplier_payments.treasury_account_id` already established. The class-5
 * requirement itself is validated in the Action, because `account_class` is
 * a GENERATED column on the parent table and MySQL cannot reference another
 * table's column in a CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // DEP/2026/000123 - allocated from the row-locked `DEP.<year>`
            // sequence inside the recording transaction (00-core §12, gaps
            // permitted). Never max()+1.
            $table->string('expense_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_expenses_no');

            $table->date('expense_date');

            // supplier | staff | other. `payee_id` is set for the first two
            // and NULL for `other`; `payee_name` is ALWAYS stored, so a
            // printed voucher stays legible after the referenced record is
            // renamed (the audit denormalisation rule of 00-core §14).
            $table->string('payee_type', 20)->default('other');
            $table->unsignedBigInteger('payee_id')->nullable();
            $table->string('payee_name', 200);

            $table->string('description', 255);

            // Which float paid: 57 Caisse / 52x Banque / 552x Mobile Money.
            $table->foreignId('treasury_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->bigInteger('total_amount')->default(0);
            $table->char('currency', 3)->default('XAF');

            $table->string('status', 20)->default('draft');

            // AUDCIF Art. 17 / L15: the receipt photo. Enforced as mandatory
            // at SUBMIT time by the Action, not at insert, so a draft can be
            // keyed at the desk before the phone photo arrives.
            $table->string('attachment_ref', 255)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('posted_at')->nullable();

            // Whether this expense needed a second pair of eyes at the time
            // it was submitted - snapshotted, because the threshold Setting
            // may move afterwards and an auditor must see the rule as it
            // stood (same reasoning as tax_rate_bp_applied).
            $table->boolean('requires_approval')->default(false);
            $table->bigInteger('approval_threshold_applied')->default(0);

            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->string('rejection_reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->char('idempotency_key', 36)->nullable()->unique('uq_expenses_idem');

            $table->timestamps();

            $table->index(['status', 'expense_date'], 'ix_expenses_status_date');
            $table->index('treasury_account_id', 'ix_expenses_treasury');
            $table->index(['payee_type', 'payee_id'], 'ix_expenses_payee');
            $table->index('expense_date', 'ix_expenses_date');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
              ADD CONSTRAINT ck_expenses_status CHECK (
                status IN ('draft', 'submitted', 'approved', 'posted', 'rejected')
              ),
              ADD CONSTRAINT ck_expenses_payee_type CHECK (
                payee_type IN ('supplier', 'staff', 'other')
              ),
              ADD CONSTRAINT ck_expenses_total_non_negative CHECK (
                total_amount >= 0
              ),
              ADD CONSTRAINT ck_expenses_posted_has_entry CHECK (
                status <> 'posted' OR journal_entry_id IS NOT NULL
              )
        SQL);

        Schema::create('expense_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE gated by the header's draft-only delete trigger below,
            // the same pairing supplier_invoice_lines uses.
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            $table->unsignedInteger('line_no');

            $table->string('label', 255);

            // Class 6 for an operating charge, class 2 when the purchase is
            // capex (§21.3). Postability and class are validated in the
            // Action against chart_of_accounts.
            $table->foreignId('account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignId('analytic_value_id')->nullable()->constrained('analytic_values')->restrictOnDelete();
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();

            $table->bigInteger('amount');

            $table->timestamps();

            $table->unique(['expense_id', 'line_no'], 'uq_expense_lines_no');
            $table->index('account_id', 'ix_expense_lines_account');
            $table->index('analytic_value_id', 'ix_expense_lines_analytic');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expense_lines
              ADD CONSTRAINT ck_expense_lines_amount_positive CHECK (
                amount > 0
              )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_expenses_draft_only_delete
            BEFORE DELETE ON expenses
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An expense may only be deleted while draft; reject or reverse its entry instead (02-accounting 21.3)';
                END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_expense_lines_frozen_after_draft
            BEFORE UPDATE ON expense_lines
            FOR EACH ROW
            BEGIN
                DECLARE header_status VARCHAR(20);
                SELECT status INTO header_status FROM expenses WHERE id = OLD.expense_id;
                IF header_status IS NOT NULL AND header_status NOT IN ('draft') THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Expense lines are frozen once the expense leaves draft (02-accounting 21.3)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_expense_lines_frozen_after_draft');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_expenses_draft_only_delete');
        Schema::dropIfExists('expense_lines');
        Schema::dropIfExists('expenses');
    }
};
