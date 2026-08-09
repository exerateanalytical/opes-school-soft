<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §5.6 - `tax_code_id` defaults on the
 * catalog tables that exist today.
 *
 * `fee_items.tax_code_id` already exists (240002) but was created WITHOUT
 * its FK because `tax_codes` (240012) had a later timestamp; this migration
 * completes it now that both tables exist. `chart_of_accounts.
 * default_tax_code_id` was handled the same way in 240012.
 *
 * AssetCategory / InventoryItem / InventoryItemCategory are Phase 9 tables
 * that do not exist yet - their defaults are DEFERRED DEBT recorded here,
 * not silently forgotten: Phase 9's migrations must add `tax_code_id` to
 * each, per §5.6.
 *
 * Everything is hasTable/hasColumn-guarded so the migration is idempotent
 * across the parallel-package databases.
 */
return new class extends Migration
{
    private const FK_NAME = 'fk_fee_items_tax_code';

    public function up(): void
    {
        if (! Schema::hasTable('fee_items') || ! Schema::hasTable('tax_codes')) {
            return;
        }

        if (! Schema::hasColumn('fee_items', 'tax_code_id')) {
            Schema::table('fee_items', function (Blueprint $table): void {
                $table->unsignedBigInteger('tax_code_id')->nullable();
            });
        }

        if (! $this->foreignKeyExists()) {
            Schema::table('fee_items', function (Blueprint $table): void {
                $table->foreign('tax_code_id', self::FK_NAME)
                    ->references('id')->on('tax_codes')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if ($this->foreignKeyExists()) {
            Schema::table('fee_items', function (Blueprint $table): void {
                $table->dropForeign(self::FK_NAME);
            });
        }
    }

    private function foreignKeyExists(): bool
    {
        foreach (Schema::getForeignKeys('fee_items') as $foreignKey) {
            if (($foreignKey['name'] ?? null) === self::FK_NAME) {
                return true;
            }
        }

        return false;
    }
};
