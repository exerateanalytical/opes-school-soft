<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/06-assets-stores.md §3 - asset_construction_costs. Cost
 * accumulation for assets under construction (status = in_progress).
 * Append-only: BEFORE UPDATE/DELETE triggers reject; a wrong cost line is
 * corrected by the owning document's reversal, never by editing this row.
 *
 * journal_entry_id is nullable: costs usually arrive via a posted Phase 5
 * supplier invoice (which carries the entry), but the manual document_ref
 * path is legitimate until every upstream document type exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_construction_costs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('asset_id')
                ->constrained('assets')->restrictOnDelete();

            $table->foreignId('supplier_invoice_id')->nullable()
                ->constrained('supplier_invoices')->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();

            $table->bigInteger('amount');
            $table->date('incurred_on');
            $table->string('description', 255);
            $table->string('document_ref', 80)->nullable();

            // Dual calendar - financially significant row (00-core §5).
            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('academic_year_id')
                ->constrained('academic_years')->restrictOnDelete();

            $table->foreignId('recorded_by')
                ->constrained('users')->restrictOnDelete();

            $table->string('idempotency_key', 100)->nullable()->unique('uq_construction_idem');

            $table->timestamps();

            $table->index(['asset_id', 'incurred_on'], 'ix_construction_asset');
        });

        DB::statement(
            'ALTER TABLE asset_construction_costs ADD CONSTRAINT chk_construction_amount CHECK ( amount > 0 )'
        );

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_construction_append_only_before_update
            BEFORE UPDATE ON asset_construction_costs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_construction_costs is append-only: rows are never updated';
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_construction_append_only_before_delete
            BEFORE DELETE ON asset_construction_costs
            FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'asset_construction_costs is append-only: rows are never deleted';
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_construction_append_only_before_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_construction_append_only_before_delete');
        Schema::dropIfExists('asset_construction_costs');
    }
};
