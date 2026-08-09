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
        // docs/specs/06-assets-stores.md §7.7 - a reservation holds no cost
        // and posts nothing; it only moves `stock_balances.quantity_reserved`
        // under the same row lock every movement takes.
        Schema::create('stock_reservations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->foreignId('store_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();

            $table->decimal('quantity', 14, 3);

            // A requisition, an exam, a class - whoever the hold is for.
            $table->string('reserved_for_type', 60);
            $table->unsignedBigInteger('reserved_for_id');

            $table->foreignId('reserved_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->date('expires_on')->nullable();

            $table->enum('status', ['active', 'released', 'consumed'])->default('active');

            $table->timestamps();

            $table->index(['item_id', 'store_location_id', 'status'], 'ix_stock_reservations_balance');
            $table->index(['status', 'expires_on'], 'ix_stock_reservations_expiry');
        });

        // "One ACTIVE reservation per (holder, item)" - the NULL-unique
        // generated-column trick (only active rows carry a key).
        DB::statement(<<<'SQL'
            ALTER TABLE stock_reservations
            ADD COLUMN active_key VARCHAR(191)
                GENERATED ALWAYS AS (
                    CASE WHEN status = 'active'
                        THEN CONCAT(reserved_for_type, ':', reserved_for_id, ':', item_id, ':', store_location_id)
                        ELSE NULL
                    END
                ) STORED,
            ADD UNIQUE KEY uq_stock_reservations_active (active_key)
        SQL);

        // §7.8 - the internal-consumption analogue of a purchase requisition;
        // what makes the analytic split (Section = Primary, Activity =
        // Canteen) defensible.
        Schema::create('store_requisitions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('requisition_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_store_requisitions_no');

            $table->foreignId('school_section_id')->nullable()
                ->constrained('school_sections')
                ->restrictOnDelete();
            $table->string('department', 120)->nullable();

            $table->foreignId('requested_by')
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->enum('status', [
                'draft', 'submitted', 'approved', 'rejected', 'fulfilled', 'cancelled',
            ])->default('submitted');

            $table->date('needed_on')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });

        Schema::create('store_requisition_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('store_requisition_id')
                ->constrained('store_requisitions')
                ->cascadeOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            $table->decimal('quantity_requested', 14, 3);
            $table->decimal('quantity_approved', 14, 3)->nullable();
            $table->decimal('quantity_issued', 14, 3)->default(0);

            $table->timestamps();

            $table->unique(['store_requisition_id', 'item_id'], 'uq_store_requisition_lines_item');
        });

        // The column shipped plain in 2026_08_09_270014; complete the FK now
        // that the target exists.
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->foreign('store_requisition_id', 'fk_stock_movements_requisition')
                ->references('id')->on('store_requisitions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropForeign('fk_stock_movements_requisition');
        });
        Schema::dropIfExists('store_requisition_lines');
        Schema::dropIfExists('store_requisitions');
        Schema::dropIfExists('stock_reservations');
    }
};
