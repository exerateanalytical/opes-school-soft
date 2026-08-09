<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §6.5/§6.6 - asset_impairments,
 * revaluation_campaigns and asset_revaluations.
 *
 * Both features SHIP DISABLED: the posting accounts are V8/V9 NEEDS
 * VERIFICATION, the seeder configures nothing, and the ImpairAsset /
 * RevalueAssets Actions refuse with a message naming the missing
 * configuration. The schema exists so the refusal is a configuration gap,
 * not a missing feature, and so history can land once verified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_impairments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            $table->date('test_date');
            $table->bigInteger('carrying_amount');
            $table->bigInteger('recoverable_amount');

            $table->enum('basis', ['value_in_use', 'fair_value_less_costs']);
            $table->string('evidence_ref', 120)->nullable();

            $table->foreignId('approved_by')
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('reversed_by_impairment_id')->nullable()
                ->constrained('asset_impairments')->restrictOnDelete();

            $table->timestamps();
        });

        // impairment_loss = carrying − recoverable, generated so it cannot
        // drift; CHECK > 0 - a non-loss is not an impairment.
        DB::statement(
            'ALTER TABLE asset_impairments ADD COLUMN impairment_loss BIGINT '
            .'GENERATED ALWAYS AS (carrying_amount - recoverable_amount) STORED AFTER recoverable_amount'
        );

        DB::statement(
            'ALTER TABLE asset_impairments ADD CONSTRAINT chk_impairments_loss CHECK ( '
            .'carrying_amount > recoverable_amount )'
        );

        Schema::create('revaluation_campaigns', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('reference', 60)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_reval_campaigns_ref');

            // Regulated campaign (§6.6): legal or free revaluation, whole
            // categories, prescribed disclosure.
            $table->enum('legal_basis', ['legal', 'free']);
            $table->date('campaign_date');

            $table->json('asset_category_ids');

            $table->foreignId('approved_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->enum('status', ['draft', 'approved', 'applied', 'cancelled'])
                ->default('draft');

            $table->timestamps();
        });

        Schema::create('asset_revaluations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('campaign_id')
                ->constrained('revaluation_campaigns')->restrictOnDelete();
            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            $table->bigInteger('carrying_before');
            $table->bigInteger('revalued_amount');

            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['campaign_id', 'asset_id'], 'uq_asset_revaluations');
        });

        // écart de réévaluation, SIGNED, derived - cannot drift.
        DB::statement(
            'ALTER TABLE asset_revaluations ADD COLUMN ecart BIGINT '
            .'GENERATED ALWAYS AS (revalued_amount - carrying_before) STORED AFTER revalued_amount'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_revaluations');
        Schema::dropIfExists('revaluation_campaigns');
        Schema::dropIfExists('asset_impairments');
    }
};
