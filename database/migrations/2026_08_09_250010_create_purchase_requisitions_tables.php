<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §4.1 - PurchaseRequisition header + lines.
 *
 * Deletion (§9): deletable ONLY in draft, enforced by a BEFORE DELETE
 * trigger at the database - not only in the model observer, because raw
 * DB::table deletes and future import tooling are separate write paths.
 * Lines CASCADE from their header, so the header's draft-only rule gates
 * the whole document.
 *
 * `budget_line_id` is a plain column: the Accounting budget tables
 * (02-accounting §16, `budgets`/`budget_lines`) are not built in this
 * checkout yet. The FK lands with that phase; approval's budget check treats
 * a missing budget model as UNCONFIGURED and blocking/warning per
 * `procurement_settings.budget_enforcement` (empty-and-blocking, spec §12).
 *
 * `inventory_item_id` / `asset_category_id` are likewise plain columns until
 * Phase 9 (06-assets-stores.md) creates their targets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // REQ/2026/000123 - allocated from the row-locked `REQ` sequence
            // at creation (00-core §12, gaps permitted, globally unique
            // across fiscal years; the year in the format is legibility only).
            $table->string('requisition_no', 30)->collation('utf8mb4_0900_as_cs')->unique('uq_requisitions_no');

            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('school_section_id')->nullable()->constrained('school_sections')->restrictOnDelete();

            $table->date('requested_on');
            $table->date('needed_by')->nullable();
            $table->text('justification')->nullable();

            $table->string('status', 20)->default('draft');

            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->string('rejected_reason', 255)->nullable();

            $table->unsignedBigInteger('budget_line_id')->nullable();

            // Derived from lines, stored for indexing (§4.1).
            $table->bigInteger('estimated_total')->default(0);

            // Dual calendar per 02-accounting C3.
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();

            $table->char('idempotency_key', 36)->nullable()->unique('uq_requisitions_idem');

            $table->timestamps();

            $table->index(['status', 'requested_on'], 'ix_requisitions_status');
            $table->index('budget_line_id', 'ix_requisitions_budget_line');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_requisitions
              ADD CONSTRAINT ck_requisitions_status CHECK (
                status IN ('draft', 'submitted', 'approved', 'partially_ordered', 'ordered', 'rejected', 'cancelled')
              )
        SQL);

        Schema::create('purchase_requisition_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE is safe ONLY because the header's BEFORE DELETE trigger
            // rejects any non-draft delete (§9).
            $table->foreignId('requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->unsignedInteger('line_no');
            $table->string('description', 255);

            // Phase 9 targets - plain columns until 06-assets-stores lands.
            $table->unsignedBigInteger('inventory_item_id')->nullable();
            $table->unsignedBigInteger('asset_category_id')->nullable();

            $table->decimal('quantity', 12, 3);
            $table->string('unit_of_measure', 20)->nullable();
            $table->bigInteger('estimated_unit_price');
            $table->bigInteger('estimated_amount');

            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->decimal('qty_ordered', 12, 3)->default(0);

            $table->timestamps();

            $table->unique(['requisition_id', 'line_no'], 'uq_requisition_lines_no');
        });

        // §9: a requisition that has left draft is cancelled, never deleted.
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_requisitions_draft_only_delete
            BEFORE DELETE ON purchase_requisitions
            FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A purchase requisition may only be deleted while draft; cancel it instead (03-tax-procurement 9)';
                END IF;
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_requisitions_draft_only_delete');
        Schema::dropIfExists('purchase_requisition_lines');
        Schema::dropIfExists('purchase_requisitions');
    }
};
