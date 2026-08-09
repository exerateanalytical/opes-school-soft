<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/06-assets-stores.md §7.3 - PCS / BOX / KG / L. Stores
        // hold litres of fuel and kilograms of rice as well as pieces;
        // quantity is DECIMAL(14,3) everywhere and is explicitly allow-listed
        // from 00-core's no-DECIMAL rule (that rule is about money).
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 00-core 4: identifier collation - accent/case sensitive.
            $table->string('code', 20)->collation('utf8mb4_0900_as_cs')->unique('uq_units_of_measure_code');

            $table->string('name', 80);
            $table->string('name_fr', 80);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // docs/specs/06-assets-stores.md §7.2 + 02-accounting.md C6: every
        // category names its Achats / Stock / Variation des stocks triple
        // (plus Ventes for merchandise). The account columns are NULLABLE on
        // purpose - 601/604/6031..6033/31/32/33/701 are verified codes but
        // NOTHING is seeded (00-core §16); invariant I2 makes the Actions
        // refuse to move an item whose category is not fully configured,
        // naming the missing account.
        Schema::create('item_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_item_categories_code');

            $table->string('name', 120);
            $table->string('name_fr', 120)->nullable();

            $table->foreignId('parent_id')->nullable()
                ->constrained('item_categories')
                ->restrictOnDelete();

            // Achats: 601 marchandises / 602 matieres premieres / 604
            // fournitures consommables.
            $table->foreignId('purchase_account_id')->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            // Stock (balance sheet): 31 / 32 / 33.
            $table->foreignId('stock_account_id')->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            // Variation des stocks: 6031 / 6032 / 6033. CREDITED on inflow,
            // DEBITED on outflow - the single most commonly reversed sign in
            // the module (§8.1); StockValuationTest carries the golden case.
            $table->foreignId('variation_account_id')->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            // Required iff any item in the category is merchandise (I3):
            // 701 Ventes de marchandises.
            $table->foreignId('sales_account_id')->nullable()
                ->constrained('chart_of_accounts')
                ->restrictOnDelete();

            // §8.5: SYSCOHADA derives cost of sales from Achats ± Variation;
            // the flag exists only to make that choice explicit and its only
            // supported value is 1.
            $table->boolean('cost_of_sales_uses_variation')->default(true);

            $table->foreignId('default_tax_code_id')->nullable()
                ->constrained('tax_codes')
                ->restrictOnDelete();

            $table->boolean('is_archived')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('units_of_measure');
    }
};
