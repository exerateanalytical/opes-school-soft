<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.2 - PurchaseOrder header, lines and
 * amendments.
 *
 * A PO POSTS NOTHING TO THE LEDGER (§4.2 invariant 6) - it is a commitment
 * document. There is deliberately no journal_entry_id column here, so a
 * developer reaching for a posting rule has nowhere to put the stamp.
 *
 * Immutability: an approved PO changes only through a PurchaseOrderAmendment
 * (invariant 5), which snapshots the prior line set as JSON before the new
 * set is applied. Enforced in the model observer + Action; the DB carries
 * the draft-only DELETE trigger (§9) and the optimistic `version` column
 * (00-core §10.6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // BC/2026/000123 (bon de commande) from the row-locked `BC`
            // sequence - globally unique across fiscal years (§9).
            $table->string('po_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_purchase_orders_no');

            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('requisition_id')->nullable()->constrained('purchase_requisitions')->restrictOnDelete();

            $table->date('order_date');
            $table->date('expected_delivery_date')->nullable();
            $table->string('delivery_address', 255)->nullable();

            // §3.5: source-document metadata only; the ledger is XAF-only.
            $table->char('currency', 3)->default('XAF');
            $table->bigInteger('exchange_rate_bp')->nullable();

            // Derived from lines, stored; the header is never independently
            // rounded (00-core §7.3).
            $table->bigInteger('subtotal_ht')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('total_ttc')->default(0);

            // Retenue de garantie (§3.3).
            $table->bigInteger('retention_rate_bp')->default(0);
            $table->date('retention_release_due_on')->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->string('closed_reason', 255)->nullable();

            $table->foreignId('payable_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            // Optimistic lock (00-core §10.6).
            $table->unsignedInteger('version')->default(0);
            $table->char('idempotency_key', 36)->nullable()->unique('uq_purchase_orders_idem');

            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'ix_purchase_orders_supplier');
            $table->index(['status', 'order_date'], 'ix_purchase_orders_status');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_orders
              ADD CONSTRAINT ck_purchase_orders_status CHECK (
                status IN ('draft', 'pending_approval', 'approved', 'sent', 'partially_received', 'received',
                           'partially_invoiced', 'invoiced', 'closed', 'cancelled')
              )
        SQL);

        Schema::create('purchase_order_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE gated by the header's draft-only delete trigger (§9).
            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('requisition_line_id')->nullable()->constrained('purchase_requisition_lines')->restrictOnDelete();

            $table->string('description', 255);
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('asset_category_id')->nullable();
            $table->boolean('is_capitalised')->default(false);

            $table->decimal('quantity', 12, 3);
            $table->string('unit_of_measure', 20)->nullable();
            $table->bigInteger('unit_price_ht');
            $table->bigInteger('discount_rate_bp')->default(0);
            // round_half_up(quantity x unit_price_ht x (1 - discount/10000)),
            // rounded ONCE per line (§4.2 invariant 1).
            $table->bigInteger('amount_ht');
            $table->foreignId('tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->bigInteger('tax_amount')->default(0);
            $table->bigInteger('amount_ttc');

            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // Maintained ONLY under FOR UPDATE by ConfirmGoodsReceipt /
            // invoice capture (§9 concurrency table).
            $table->decimal('qty_received', 12, 3)->default(0);
            $table->decimal('qty_invoiced', 12, 3)->default(0);

            $table->timestamps();

            $table->unique(['purchase_order_id', 'line_no'], 'uq_po_lines_no');
            $table->index('requisition_line_id', 'ix_po_lines_requisition_line');
        });

        // §4.2 invariant 5: UNIQUE(po_id, amendment_no); the snapshot is the
        // prior line set so the history is replayable.
        Schema::create('purchase_order_amendments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $table->unsignedInteger('amendment_no');
            $table->string('reason', 255);
            $table->json('previous_lines');
            $table->bigInteger('previous_subtotal_ht');
            $table->bigInteger('previous_total_ttc');

            $table->foreignId('amended_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('amended_at');

            $table->timestamps();

            $table->unique(['purchase_order_id', 'amendment_no'], 'uq_po_amendments_no');
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_purchase_orders_draft_only_delete
            BEFORE DELETE ON purchase_orders
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A purchase order may only be deleted while draft; cancel it instead (03-tax-procurement 9)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_purchase_orders_draft_only_delete');
        Schema::dropIfExists('purchase_order_amendments');
        Schema::dropIfExists('purchase_order_lines');
        Schema::dropIfExists('purchase_orders');
    }
};
