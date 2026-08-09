<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §2.2 - the fixed-asset register row.
 *
 * Money BIGINT SIGNED; identifiers utf8mb4_0900_as_cs; dual calendar
 * (fiscal_year_id + academic_year_id); RESTRICT everywhere with financial
 * history (A13: no hard delete, ever). CHECKs A6/A8 plus the donation and
 * A12 shape constraints live here; A7 (residual snapshot), A9-A11 are
 * Action/engine-enforced.
 *
 * Cross-phase columns without FK constraints (added by the owning phase's
 * follow-up migration): investment_subsidy_id (F2, 270009), disposal_id
 * (F2, 270008), location_id (rooms / F3's store_locations, polymorphic by
 * design - no FK possible).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The physical sticker. Global uniqueness is the register's
            // spine (§2.2).
            $table->string('tag_number', 40)->collation('utf8mb4_0900_as_cs')->unique('uq_assets_tag');

            // MySQL permits many NULLs under a UNIQUE key - correct here:
            // "no serial" is not a duplicate.
            $table->string('serial_number', 80)->collation('utf8mb4_0900_as_cs')->nullable()
                ->unique('uq_assets_serial');

            $table->foreignId('asset_category_id')
                ->constrained('asset_categories')->restrictOnDelete();

            // Component accounting (§4.6). Cycle/depth guarded in the
            // Action (A10) by ancestor walk under FOR UPDATE.
            $table->foreignId('parent_asset_id')->nullable()
                ->constrained('assets')->restrictOnDelete();

            $table->string('name', 160);
            $table->text('description')->nullable();

            $table->enum('status', [
                'draft', 'in_progress', 'in_service', 'idle', 'under_maintenance',
                'impaired', 'disposed', 'written_off', 'lost',
            ])->default('draft');

            $table->date('acquisition_date');
            $table->bigInteger('acquisition_cost');

            // 03-tax-procurement prorata rule: when the input VAT is
            // non-recoverable it is capitalised and the basis says so.
            $table->enum('cost_basis', ['ht', 'ttc_non_recoverable_vat_capitalised']);
            $table->bigInteger('non_recoverable_vat_amount')->default(0);

            // A7: an AMOUNT, snapshotted at capitalisation - never a live
            // rate lookup.
            $table->bigInteger('residual_value')->default(0);

            $table->date('in_service_date')->nullable();

            // Derived (= in_service_date, §5.1), stored, immutable once the
            // first schedule row exists (F2 enforces).
            $table->date('depreciation_start_date')->nullable();

            // Copies from the category at capitalisation (§5.3) - snapshots,
            // not lookups; independently editable as a change in estimate.
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->enum('depreciation_method', ['none', 'straight_line', 'declining_balance'])->nullable();
            $table->enum('prorata_convention', ['daily', 'monthly', 'full_month', 'half_year'])->nullable();

            $table->enum('acquisition_type', [
                'purchase', 'donation', 'grant_funded', 'self_constructed', 'transfer_in', 'opening_balance',
            ]);
            $table->bigInteger('fair_value_at_donation')->nullable();

            // The partner registry for donors is the supplier table (no
            // separate partners table exists).
            $table->foreignId('donor_id')->nullable()
                ->constrained('suppliers')->restrictOnDelete();

            // F2's investment_subsidies table (270009) adds the FK.
            $table->unsignedBigInteger('investment_subsidy_id')->nullable();

            // Phase 5 landed: real FKs.
            $table->foreignId('supplier_id')->nullable()
                ->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()
                ->constrained('supplier_invoices')->restrictOnDelete();

            // Physical location only, no accounting effect. Polymorphic
            // across rooms / store_locations (F3), so no FK by design.
            $table->unsignedBigInteger('location_id')->nullable();

            $table->foreignId('custodian_staff_id')->nullable()
                ->constrained('staff_members')->restrictOnDelete();
            $table->foreignId('school_section_id')->nullable()
                ->constrained('school_sections')->restrictOnDelete();

            // Dual calendar, 02-accounting C3.
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')->restrictOnDelete();

            $table->string('insurance_policy_ref', 80)->nullable();
            $table->date('warranty_expires_on')->nullable();

            // Set by F2's DisposeAsset (270008 owns the target table).
            $table->unsignedBigInteger('disposal_id')->nullable();

            // The capitalisation entry (asset.acquired / asset.commissioned),
            // stamped once by CapitaliseAsset so reversal targets THE entry.
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->text('notes')->nullable();

            // Every mutating Action takes one (00-core idempotency rule).
            $table->string('idempotency_key', 100)->nullable()->unique('uq_assets_idem');

            $table->timestamps();

            $table->index(['status'], 'ix_assets_status');
            $table->index(['parent_asset_id'], 'ix_assets_parent');
        });

        // A6: an asset cannot enter service before it was acquired.
        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_a6 CHECK ( '
            .'in_service_date IS NULL OR in_service_date >= acquisition_date )'
        );

        // A8 (with the §8.6 expense_and_track carve-out: a tracking-only
        // asset carries cost 0 AND residual 0).
        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_a8 CHECK ( '
            .'residual_value >= 0 AND acquisition_cost >= 0 '
            .'AND (residual_value < acquisition_cost OR (acquisition_cost = 0 AND residual_value = 0)) )'
        );

        // Donation requires its fair value (§2.2).
        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_donation CHECK ( '
            ."acquisition_type <> 'donation' OR fair_value_at_donation IS NOT NULL )"
        );

        // A12: disposed requires the disposal row.
        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_a12 CHECK ( '
            ."status <> 'disposed' OR disposal_id IS NOT NULL )"
        );

        DB::statement(
            'ALTER TABLE assets ADD CONSTRAINT chk_assets_vat CHECK ( non_recoverable_vat_amount >= 0 )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
