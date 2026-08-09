<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/06-assets-stores.md §7.6 - the stock ledger, strictly
        // append-only (I11): corrections are compensating movements carrying
        // `reversal_of_movement_id`, mirroring 02-accounting.md C9. The
        // snapshotted running balance makes the ledger printable as at any
        // date without replay.
        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->enum('movement_type', [
                'receipt', 'issue', 'transfer_out', 'transfer_in',
                'adjustment_in', 'adjustment_out', 'sale',
                'return_in', 'return_out', 'opening_balance',
            ]);

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->foreignId('store_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();

            // Signed delta: positive in, negative out.
            $table->decimal('quantity', 14, 3);

            // I12: descriptive snapshot of the derived cost actually applied,
            // equal to round(abs(total_cost)/abs(quantity)). `total_cost` is
            // authoritative; a total is NEVER recomputed from unit_cost.
            $table->bigInteger('unit_cost')->default(0);

            // Signed like quantity - the authoritative amount.
            $table->bigInteger('total_cost');

            $table->decimal('balance_qty_after', 14, 3);
            $table->bigInteger('balance_value_after');

            $table->date('moved_on');

            // Polymorphic source document: SupplierInvoice, StockIssue,
            // StockTransfer, StockTakeLine, Invoice (merchandise credit
            // sale), or a manual document_ref until Phase 5 lands.
            $table->string('reference_type', 60)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('document_ref', 120)->nullable();

            // I13: set at insert time when the movement's accounting leg
            // posted in the same transaction; otherwise the reason is stated.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();
            $table->string('posting_deferred_reason', 200)->nullable();

            // The requesting department (analytic defensibility, §7.8). FK
            // added by 2026_08_09_270015 once store_requisitions exists.
            $table->unsignedBigInteger('store_requisition_id')->nullable();

            // Dual calendar (00-core).
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('performed_by')
                ->constrained('users')
                ->restrictOnDelete();

            // C9 mirror: a movement is reversed at most once (UNIQUE), and a
            // reversal is never itself reversed (Action rule).
            $table->foreignId('reversal_of_movement_id')->nullable()
                ->unique('uq_stock_movements_reversal')
                ->constrained('stock_movements')
                ->restrictOnDelete();

            // Retry-safe Action front door (phase-09 plan §4).
            $table->string('idempotency_key', 64)->collation('utf8mb4_0900_as_cs')
                ->nullable()->unique('uq_stock_movements_idem');

            // Append-only: created_at only, no updated_at to touch.
            $table->timestamp('created_at')->nullable();

            // The stock-ledger query.
            $table->index(['item_id', 'store_location_id', 'moved_on', 'id'], 'ix_stock_movements_ledger');
            $table->index(['reference_type', 'reference_id'], 'ix_stock_movements_reference');
            $table->index('moved_on', 'ix_stock_movements_moved_on');
        });

        // I10: sign(quantity) must be compatible with sign(total_cost) - a
        // movement never takes quantity one way and money the other. Stated
        // as sign-compatibility (not strict equality) so a legitimately
        // zero-cost rounding edge cannot be forced to lie about direction.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_movements
            ADD CONSTRAINT chk_stock_movements_i10 CHECK (
                (quantity >= 0 AND total_cost >= 0) OR (quantity <= 0 AND total_cost <= 0)
            )
        SQL);

        // I11: movements are never updated or deleted. BEFORE triggers make
        // the table append-only at the engine level - the same defence the
        // posted journal carries.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_stock_movements_no_update
            BEFORE UPDATE ON stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'Stock movements are append-only (06-assets-stores I11); corrections are compensating movements';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_stock_movements_no_delete
            BEFORE DELETE ON stock_movements
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT =
                    'Stock movements are append-only (06-assets-stores I11); corrections are compensating movements';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movements_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_stock_movements_no_delete');
        Schema::dropIfExists('stock_movements');
    }
};
