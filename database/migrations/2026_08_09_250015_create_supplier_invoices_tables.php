<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.5 - SupplierInvoice header and lines.
 *
 * The single most effective duplicate-payment control there is lives HERE,
 * at the database: UNIQUE(supplier_id, supplier_invoice_no). Validation may
 * catch it first with a friendlier message, but the constraint is what makes
 * the control real (§11 test obligation 5).
 *
 * Money columns are BIGINT SIGNED whole FCFA (00-core §7.1), computed in
 * Money in the Actions, never by SQL. Every line snapshots its tax
 * (`tax_code_id` + `tax_rate_bp_applied`, App\Support\Rate scale where
 * 100 000 bp = 100%) and its prorata split; the CHECK on the line table
 * makes the §5.4 conservation (`deductible + non_deductible = tax`)
 * impossible to violate even by direct SQL.
 *
 * `journal_entry_id` is the main posting; `secondary_journal_entry_id`
 * carries the second payable-family entry of a MIXED invoice (§3.3: split
 * at posting, one payable per family, both carrying the supplier partner);
 * `withholding_journal_entry_id` the on_invoice §4.6 recognition entry.
 * All three FK RESTRICT.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // FF/2026/000123 - our own reference from the row-locked `FF`
            // sequence; gaps permitted (§9).
            $table->string('internal_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_supplier_invoices_internal_no');

            // The SUPPLIER'S own number - unique per supplier at the DB.
            $table->string('supplier_invoice_no', 60)->collation('utf8mb4_0900_as_cs');

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();

            // invoice_date drives the TVA period (§5.6); value_date is
            // retained when a late invoice is forward-posted into the first
            // open period (02-accounting C4).
            $table->date('invoice_date');
            $table->date('received_date');
            $table->date('value_date');
            $table->date('due_date');

            // §3.5: source-document metadata only; the ledger is XAF-only.
            $table->char('currency', 3)->default('XAF');
            $table->bigInteger('exchange_rate_bp')->nullable();

            $table->bigInteger('subtotal_ht')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total_ttc')->default(0);
            $table->bigInteger('withholding_total')->default(0);
            // total_ttc - withholding_total, stored.
            $table->bigInteger('net_payable')->default(0);
            // §3.3 retenue de garantie computed from the PO's
            // retention_rate_bp; the 4817 credit is recorded by the payables
            // package (F4) - the amount is snapshotted here.
            $table->bigInteger('retention_amount')->default(0);

            // §3.3: 401 operating / 481-family investment; authoritative per
            // document, defaulted from the supplier.
            $table->foreignId('payable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->string('status', 20)->default('draft');
            $table->string('match_status', 20)->default('not_required');

            $table->string('match_override_reason', 255)->nullable();
            $table->foreignId('match_override_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('match_override_at')->nullable();

            // §4.4 mode "none": approving an unmatched direct invoice needs
            // procurement.invoice_approve_unmatched and a stored reason.
            $table->string('unmatched_reason', 255)->nullable();

            // §6.4 step 7 - no rule matched; silence is not an answer.
            $table->boolean('withholding_unresolved')->default(false);
            $table->string('withholding_waived_reason', 255)->nullable();
            $table->foreignId('withholding_waived_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('withholding_waived_at')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();

            $table->dateTime('posted_at')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('secondary_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('withholding_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason', 255)->nullable();

            // 02-accounting H: a migrated invoice must not re-trigger
            // posting rules.
            $table->boolean('is_migration')->default(false);

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();

            // The scanned original (10-documents.md owns the table; no FK
            // until that phase lands).
            $table->unsignedBigInteger('document_id')->nullable();

            $table->unsignedInteger('version')->default(0);
            $table->char('idempotency_key', 36)->nullable()->unique('uq_supplier_invoices_idem');

            $table->timestamps();

            $table->unique(['supplier_id', 'supplier_invoice_no'], 'uq_supplier_invoices_supplier_no');
            $table->index(['supplier_id', 'status'], 'ix_supplier_invoices_supplier');
            $table->index(['status', 'invoice_date'], 'ix_supplier_invoices_status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_invoices
              ADD CONSTRAINT ck_supplier_invoices_status CHECK (
                status IN ('draft', 'pending_match', 'match_exception', 'pending_approval', 'approved',
                           'posted', 'partially_paid', 'paid', 'cancelled', 'disputed')
              ),
              ADD CONSTRAINT ck_supplier_invoices_match_status CHECK (
                match_status IN ('not_required', 'matched', 'exception', 'overridden')
              ),
              ADD CONSTRAINT ck_supplier_invoices_net CHECK (
                net_payable = total_ttc - withholding_total
              )
        SQL);

        Schema::create('supplier_invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE gated by the header's draft-only delete trigger (§9).
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->unsignedInteger('line_no');

            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->nullable()->constrained('goods_receipt_lines')->restrictOnDelete();

            $table->string('description', 255);
            $table->decimal('quantity', 12, 3)->default(1);
            $table->string('unit_of_measure', 20)->nullable();
            $table->bigInteger('unit_price_ht');
            $table->bigInteger('discount_rate_bp')->default(0);
            $table->bigInteger('amount_ht');

            // Per line, MANDATORY (§4.5): one invoice legitimately mixes an
            // exempt line and a 19.25% line. Rate snapshotted from the
            // version in force at invoice_date - never re-derived (§5.3).
            $table->foreignId('tax_code_id')->constrained('tax_codes')->restrictOnDelete();
            $table->bigInteger('tax_rate_bp_applied')->default(0);
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('deductible_tax_amount')->default(0);
            $table->bigInteger('non_deductible_tax_amount')->default(0);

            // Per line, MANDATORY - a class-2 account when capitalised.
            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->boolean('is_capitalised')->default(false);
            // Assets/Inventory are Phase 9: recorded intent, no FK yet.
            $table->unsignedBigInteger('asset_id')->nullable();
            $table->unsignedBigInteger('asset_category_id')->nullable();
            $table->unsignedBigInteger('inventory_item_id')->nullable();

            // §6.4 resolution snapshot; rule nullable = exempt / below
            // threshold / unresolved, `withholding_reason` says which.
            $table->foreignId('withholding_rule_id')->nullable()->constrained('withholding_rules')->restrictOnDelete();
            $table->bigInteger('withholding_base')->default(0);
            $table->bigInteger('withholding_rate_bp_applied')->default(0);
            $table->bigInteger('withholding_amount')->default(0);
            $table->string('withholding_reason', 30)->nullable();
            $table->string('withholding_exemption_ref', 120)->nullable();

            // §4.4 - per-LINE match state so the exception report names the
            // line, not the invoice.
            $table->string('match_status', 20)->default('not_required');
            $table->decimal('matched_qty', 12, 3)->default(0);
            $table->bigInteger('price_variance')->default(0);
            $table->decimal('quantity_variance', 12, 3)->default(0);
            $table->string('match_exception_reason', 30)->nullable();

            $table->timestamps();

            $table->unique(['supplier_invoice_id', 'line_no'], 'uq_supplier_invoice_lines_no');
            $table->index('purchase_order_line_id', 'ix_sil_po_line');
            $table->index('goods_receipt_line_id', 'ix_sil_gr_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE supplier_invoice_lines
              ADD CONSTRAINT ck_sil_tax_split CHECK (
                deductible_tax_amount + non_deductible_tax_amount = tax_amount
              ),
              ADD CONSTRAINT ck_sil_match_status CHECK (
                match_status IN ('not_required', 'matched', 'exception', 'overridden')
              )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_supplier_invoices_draft_only_delete
            BEFORE DELETE ON supplier_invoices
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A supplier invoice may only be deleted while draft; cancel it instead (03-tax-procurement 9)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_supplier_invoices_draft_only_delete');
        Schema::dropIfExists('supplier_invoice_lines');
        Schema::dropIfExists('supplier_invoices');
    }
};
