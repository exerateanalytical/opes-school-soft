<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §3.4 - SupplierCategory reference data.
 *
 * Archive-flag deletion (§9): the unique `code` would be permanently blocked
 * by SoftDeletes, so there is no deleted_at and never will be.
 *
 * `default_withholding_profile_id` targets `withholding_profiles`, which is
 * created by 2026_08_09_250003 (Phase 5 Block A, agent F1). Block A and
 * Block B are built in parallel work packages, so the FK is added only when
 * the table already exists at migrate time; on the integrated database the
 * 250003 file sorts before this one and the constraint is always created.
 * The plain indexed column exists either way, so application behaviour does
 * not depend on which branch ran.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_categories', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->string('code', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_supplier_categories_code');
            $table->string('name', 160);
            $table->string('name_fr', 160)->nullable();

            $table->foreignId('default_expense_account_id')->nullable()
                ->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('default_tax_code_id')->nullable()
                ->constrained('tax_codes')->restrictOnDelete();
            $table->unsignedBigInteger('default_withholding_profile_id')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index('default_withholding_profile_id', 'ix_supplier_categories_wh_profile');
        });

        if (Schema::hasTable('withholding_profiles')) {
            Schema::table('supplier_categories', function (Blueprint $table): void {
                $table->foreign('default_withholding_profile_id', 'fk_supplier_categories_wh_profile')
                    ->references('id')->on('withholding_profiles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_categories');
    }
};
