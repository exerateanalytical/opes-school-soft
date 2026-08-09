<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §6.1 - asset_disposals. One disposal per
 * asset (UNIQUE); a reversal cancels this row, it never creates a second.
 *
 * `gain_or_loss` is GENERATED ALWAYS AS (proceeds − nbv) STORED: a derived
 * reporting figure that can never drift from its inputs and is NEVER
 * posted - the P&L already carries it as the 812/822 gross pair (§6.2).
 *
 * Also adds the FK for assets.disposal_id that F1's 270002 left
 * unconstrained (this migration owns the target table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            $table->enum('disposal_type', [
                'sale', 'scrap', 'donation_out', 'loss', 'theft', 'trade_in',
            ]);
            $table->date('disposal_date');

            $table->bigInteger('proceeds_amount')->default(0);

            // The partner registry is the supplier table (same as donors).
            $table->foreignId('buyer_partner_id')->nullable()
                ->constrained('suppliers')->restrictOnDelete();

            $table->enum('settlement', ['receivable', 'cash', 'bank', 'mobile_money'])
                ->nullable();

            // Snapshotted at disposal, after the §4.5 depreciate-to-date.
            $table->bigInteger('nbv_at_disposal');
            $table->bigInteger('accumulated_at_disposal');

            $table->foreignId('approved_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');
            $table->string('reason', 255);
            $table->string('document_ref', 80)->nullable();

            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()->unique('uq_disposals_idem');

            $table->timestamps();

            $table->unique(['asset_id'], 'uq_disposals_asset');
        });

        // The generated column - Laravel's storedAs quoting is awkward with
        // signed arithmetic, so declare it directly.
        DB::statement(
            'ALTER TABLE asset_disposals ADD COLUMN gain_or_loss BIGINT '
            .'GENERATED ALWAYS AS (proceeds_amount - nbv_at_disposal) STORED AFTER accumulated_at_disposal'
        );

        DB::statement(
            'ALTER TABLE asset_disposals ADD CONSTRAINT chk_disposals_proceeds CHECK ( proceeds_amount >= 0 )'
        );

        // A sale needs its buyer (§6.1).
        DB::statement(
            'ALTER TABLE asset_disposals ADD CONSTRAINT chk_disposals_buyer CHECK ( '
            ."disposal_type <> 'sale' OR buyer_partner_id IS NOT NULL )"
        );

        // F1's 270002 noted: FK added by the owning phase's migration.
        Schema::table('assets', function (Blueprint $table): void {
            $table->foreign('disposal_id', 'fk_assets_disposal')
                ->references('id')->on('asset_disposals')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table): void {
            $table->dropForeign('fk_assets_disposal');
        });

        Schema::dropIfExists('asset_disposals');
    }
};
