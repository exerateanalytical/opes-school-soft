<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // docs/specs/06-assets-stores.md §7.5 - the locked row of the whole
        // inventory design. The weighted average is NEVER stored as a unit
        // price: `value_on_hand` (whole FCFA, BIGINT) is authoritative and
        // the unit cost is derived at the moment of use (§7.1), so there is
        // no rounded scalar to drift. Every movement takes SELECT..FOR UPDATE
        // on this row; transfers lock both rows ordered by
        // (item_id, store_location_id) ascending (deadlock rule, Pest-tested).
        //
        // `quantity_available` (on_hand - reserved) is derived and never
        // stored - only two of the mockup's three columns are facts.
        DB::statement(<<<'SQL'
            CREATE TABLE stock_balances (
                item_id            BIGINT UNSIGNED NOT NULL,
                store_location_id  BIGINT UNSIGNED NOT NULL,
                quantity_on_hand   DECIMAL(14,3)   NOT NULL DEFAULT 0,
                quantity_reserved  DECIMAL(14,3)   NOT NULL DEFAULT 0,
                value_on_hand      BIGINT SIGNED   NOT NULL DEFAULT 0,
                last_movement_at   TIMESTAMP NULL DEFAULT NULL,
                created_at         TIMESTAMP NULL DEFAULT NULL,
                updated_at         TIMESTAMP NULL DEFAULT NULL,

                PRIMARY KEY (item_id, store_location_id),

                CONSTRAINT fk_stock_balances_item
                    FOREIGN KEY (item_id) REFERENCES items (id)
                    ON DELETE RESTRICT,
                CONSTRAINT fk_stock_balances_location
                    FOREIGN KEY (store_location_id) REFERENCES store_locations (id)
                    ON DELETE RESTRICT,

                -- I6: negative stock is REJECTED, not permitted-and-warned.
                CONSTRAINT chk_stock_balances_i6 CHECK (quantity_on_hand >= 0),
                -- I7: reservations are a subset of what is physically there.
                CONSTRAINT chk_stock_balances_i7 CHECK (
                    quantity_reserved >= 0 AND quantity_reserved <= quantity_on_hand
                ),
                -- I8: an empty bin is worth exactly zero, and only an empty
                -- bin is worth zero (the empty-bin issue rule of §7.1 is what
                -- makes this satisfiable).
                CONSTRAINT chk_stock_balances_i8 CHECK (
                    (quantity_on_hand = 0) = (value_on_hand = 0)
                ),
                -- I9.
                CONSTRAINT chk_stock_balances_i9 CHECK (value_on_hand >= 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
