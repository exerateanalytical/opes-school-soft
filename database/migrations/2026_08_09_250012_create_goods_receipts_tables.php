<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.3 - GoodsReceipt header + lines.
 *
 * A receipt POSTS NOTHING (§4.3): stock and expense recognition happen on
 * the invoice, or at cut-off via 4818. Posting on receipt would double-count
 * when the invoice arrives - there is deliberately no journal_entry_id here.
 *
 * `store_location_id` is a plain column until Phase 9 (06-assets-stores.md)
 * creates `store_locations`; same for the line-level inventory/asset ids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_receipts', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // BR/2026/000123 (bon de reception) from the row-locked `BR`
            // sequence - globally unique across fiscal years (§9).
            $table->string('receipt_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_goods_receipts_no');

            // Nullable: direct receipt permitted where a PO is not required.
            $table->foreignId('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            $table->date('received_on');
            $table->foreignId('received_by')->constrained('users')->restrictOnDelete();
            $table->string('delivery_note_ref', 80)->nullable();
            $table->unsignedBigInteger('store_location_id')->nullable();

            $table->string('status', 20)->default('draft');

            // Derived (any line with qty_rejected > 0) and stored (§4.3).
            $table->boolean('has_discrepancy')->default(false);

            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->timestamps();

            $table->index(['supplier_id', 'status'], 'ix_goods_receipts_supplier');
            $table->index('purchase_order_id', 'ix_goods_receipts_po');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE goods_receipts
              ADD CONSTRAINT ck_goods_receipts_status CHECK (status IN ('draft', 'confirmed', 'cancelled'))
        SQL);

        Schema::create('goods_receipt_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE gated by the header's draft-only delete trigger (§9).
            $table->foreignId('goods_receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->foreignId('purchase_order_line_id')->nullable()->constrained('purchase_order_lines')->restrictOnDelete();

            $table->string('description', 255);
            $table->decimal('qty_ordered', 12, 3)->default(0);
            $table->decimal('qty_received', 12, 3);
            $table->decimal('qty_accepted', 12, 3);
            $table->decimal('qty_rejected', 12, 3)->default(0);
            $table->string('rejection_reason', 255)->nullable();

            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('asset_category_id')->nullable();
            $table->json('serial_numbers')->nullable();

            $table->timestamps();

            $table->unique(['goods_receipt_id', 'line_no'], 'uq_gr_lines_no');
            $table->index('purchase_order_line_id', 'ix_gr_lines_po_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE goods_receipt_lines
              ADD CONSTRAINT ck_gr_lines_quantities CHECK (
                qty_received >= 0 AND qty_accepted >= 0 AND qty_rejected >= 0
                AND qty_accepted + qty_rejected = qty_received
              )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_goods_receipts_draft_only_delete
            BEFORE DELETE ON goods_receipts
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A goods receipt may only be deleted while draft; cancel it instead (03-tax-procurement 9)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_goods_receipts_draft_only_delete');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
    }
};
