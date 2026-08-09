<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4 - the procurement policy singleton and
 * the ordered approval-threshold table.
 *
 * `procurement_settings` is a true singleton (CHECK id = 1) like the Phase 5
 * fiscal-identity tables: the thresholds that decide whether a purchase needs
 * a requisition, a PO or a receipt are school-wide policy, not per-row data.
 * Thresholds are FCFA amounts where 0 = "always required" and NULL = "never
 * required" - the spec's "optional-by-configuration" per step.
 *
 * `approval_thresholds` (§4.2): ordered bands (min_amount, max_amount,
 * required_role, sequence) so a 5,000,000 FCFA order routes to the Principal
 * while a 50,000 one stops at the Bursar. `required_role` stores the
 * Identity Role enum value as a string - roles are seeded reference data,
 * not a table to FK against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procurement_settings', function (Blueprint $table): void {
            // NOT auto-increment: MySQL 8 refuses a CHECK constraint that
            // references an auto-increment column, and a singleton has no use
            // for one - the row is always written with id = 1.
            $table->unsignedBigInteger('id')->primary();

            // FCFA thresholds. NULL = the step is never required, 0 = always.
            $table->bigInteger('requisition_required_above')->nullable();
            $table->bigInteger('po_required_above')->nullable();
            $table->boolean('receipt_required_for_goods')->default(true);

            // Basis points per 00-core §7.2 (10000 = 100%).
            $table->bigInteger('over_receipt_tolerance_bp')->default(0);
            $table->bigInteger('price_tolerance_bp')->default(0);
            $table->bigInteger('price_tolerance_absolute')->default(0);
            $table->bigInteger('quantity_tolerance_bp')->default(0);

            $table->string('budget_enforcement', 10)->default('none');

            $table->timestamps();
        });

        DB::statement('ALTER TABLE procurement_settings ADD CONSTRAINT ck_procurement_settings_singleton CHECK (id = 1)');
        DB::statement("ALTER TABLE procurement_settings ADD CONSTRAINT ck_procurement_settings_budget CHECK (budget_enforcement IN ('none', 'warn', 'block'))");

        Schema::create('approval_thresholds', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->bigInteger('min_amount');
            // NULL = unbounded top band.
            $table->bigInteger('max_amount')->nullable();
            $table->string('required_role', 40);
            $table->unsignedInteger('sequence');

            $table->timestamps();

            $table->unique('sequence', 'uq_approval_thresholds_sequence');
        });

        DB::statement('ALTER TABLE approval_thresholds ADD CONSTRAINT ck_approval_thresholds_band CHECK (max_amount IS NULL OR max_amount >= min_amount)');
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_thresholds');
        Schema::dropIfExists('procurement_settings');
    }
};
