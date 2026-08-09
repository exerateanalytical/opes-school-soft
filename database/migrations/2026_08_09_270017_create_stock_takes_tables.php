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
        // docs/specs/06-assets-stores.md §7.10 - physical inventory. On
        // draft -> counting the Action snapshots system quantity/value for
        // every item at the location and BLOCKS movements there (the
        // `store_locations.counting_stock_take_id` flag, checked under the
        // location row lock) - reconciling afterwards is where stock-take
        // arithmetic goes wrong in every system that tries it.
        Schema::create('stock_takes', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('reference', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_stock_takes_reference');

            $table->foreignId('store_location_id')
                ->constrained('store_locations')
                ->restrictOnDelete();

            $table->boolean('is_full_count')->default(false);

            $table->date('count_date');

            $table->enum('status', [
                'draft', 'counting', 'counted', 'approved', 'posted', 'cancelled',
            ])->default('draft');

            $table->foreignId('counted_by')->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()
                ->constrained('users')
                ->restrictOnDelete();
            // Segregated: approved_by <> counted_by (Action rule).
            $table->foreignId('approved_by')->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')
                ->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')
                ->restrictOnDelete();

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();
        });

        Schema::create('stock_take_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('stock_take_id')
                ->constrained('stock_takes')
                ->restrictOnDelete();

            $table->foreignId('item_id')
                ->constrained('items')
                ->restrictOnDelete();

            // Frozen at freeze time (§7.10).
            $table->decimal('system_quantity', 14, 3);
            $table->bigInteger('system_value');

            $table->decimal('counted_quantity', 14, 3)->nullable();

            // Signed money value of the variance, priced at the frozen
            // derived cost with the §7.1 empty-bin rule (shortage negative).
            $table->bigInteger('variance_value')->nullable();

            $table->string('reason_code', 40)->nullable();
            $table->text('note')->nullable();

            // §8.4: the 658-family shortage routing override exists but is
            // NOT seeded and unavailable until V16 is resolved.
            $table->foreignId('loss_account_id')->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(['stock_take_id', 'item_id'], 'uq_stock_take_lines_item');
        });

        // Generated variance (counted - system); NULL until counted.
        DB::statement(<<<'SQL'
            ALTER TABLE stock_take_lines
            ADD COLUMN variance_quantity DECIMAL(14,3)
                GENERATED ALWAYS AS (counted_quantity - system_quantity) STORED
        SQL);

        // Complete the freeze-flag FK shipped plain in 2026_08_09_270012.
        Schema::table('store_locations', function (Blueprint $table): void {
            $table->foreign('counting_stock_take_id', 'fk_store_locations_counting_take')
                ->references('id')->on('stock_takes')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('store_locations', function (Blueprint $table): void {
            $table->dropForeign('fk_store_locations_counting_take');
        });
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
    }
};
