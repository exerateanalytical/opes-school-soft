<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/06-assets-stores.md §7.4 - the mockup's Store A /
        // Store B / Lab Store / AV Room / Library values map to `type`.
        Schema::create('store_locations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_store_locations_code');

            $table->string('name', 120);
            $table->enum('type', ['store', 'lab', 'av_room', 'library', 'kitchen', 'classroom'])
                ->default('store');

            $table->foreignId('school_section_id')->nullable()
                ->constrained('school_sections')
                ->restrictOnDelete();

            $table->foreignId('keeper_staff_id')->nullable()
                ->constrained('staff_members')
                ->restrictOnDelete();

            $table->boolean('is_sellable_point')->default(false);
            $table->boolean('is_active')->default(true);

            // §7.10 freeze semantics: while a stock take is `counting` at
            // this location every movement is BLOCKED (checked under the
            // location row lock). Plain column - the FK to stock_takes is
            // added by 2026_08_09_270017 once that table exists.
            $table->unsignedBigInteger('counting_stock_take_id')->nullable();

            $table->timestamps();
        });

        // docs/specs/06-assets-stores.md §7.3.
        Schema::create('items', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // ITM0001 in the mockup.
            $table->string('item_code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_items_code');
            $table->string('barcode', 64)->collation('utf8mb4_0900_as_cs')->nullable()->unique('uq_items_barcode');

            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->foreignId('item_category_id')
                ->constrained('item_categories')
                ->restrictOnDelete();

            $table->enum('item_type', ['consumable', 'equipment', 'merchandise']);

            $table->foreignId('unit_of_measure_id')
                ->constrained('units_of_measure')
                ->restrictOnDelete();

            $table->boolean('is_stock_tracked')->default(true);

            // Drives the mockup's Low Stock panel.
            $table->decimal('reorder_level', 14, 3)->default(0);
            $table->decimal('reorder_quantity', 14, 3)->default(0);

            // Merchandise only (BIGINT whole FCFA).
            $table->bigInteger('standard_sale_price')->nullable();

            $table->foreignId('sale_tax_code_id')->nullable()
                ->constrained('tax_codes')
                ->restrictOnDelete();

            // I4 / §8.6: set when an `equipment` receipt should ALSO create
            // an Asset. Deliberately NOT a foreign key: asset_categories is
            // owned by the parallel F1 package (2026_08_09_270001) and the
            // phase-09 plan ships this as an unconstrained nullable id; the
            // FK lands in an integration follow-up migration.
            $table->unsignedBigInteger('asset_category_id')->nullable();

            // I5: `discontinued` blocks receipts and sales but permits
            // issues/transfers/stock-takes (run the remaining stock down);
            // `archived` blocks everything and requires zero on hand.
            $table->enum('status', ['active', 'discontinued', 'archived'])->default('active');

            // §7.1: display-only mirror of value/quantity, NEVER an input to
            // any posting - an architecture assertion in the F3 suite greps
            // that no Action reads it.
            $table->bigInteger('weighted_avg_cost')->nullable();

            $table->string('image_path', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
        Schema::dropIfExists('store_locations');
    }
};
