<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §7.1 - tax declarations and their
 * satellites:
 *
 * - `tax_declarations`: one row per (type, period), the idempotency
 *   backstop. The spec asks for UNIQUE(declaration_type, period_year,
 *   period_month) AND an amendment chain (`amends_declaration_id`, one
 *   amendment per original) - an amendment is a second row for the SAME
 *   period, so the unique key includes a stored generated `period_slot`
 *   (= COALESCE(amends_declaration_id, 0)): originals all share slot 0
 *   (one original per period), and each amendment occupies the slot of the
 *   row it amends (one amendment per original, doubly guaranteed by the
 *   UNIQUE on amends_declaration_id itself).
 * - `tax_declaration_lines`: the form's own boxes. Form box codes ship
 *   EMPTY (NEEDS VERIFICATION): lines carry internal codes and the
 *   declaration cannot be marked filed until the type's `form_boxes`
 *   mapping is configured (added below on tax_declaration_types).
 * - `tax_declaration_entries`: the normalised pivot to the contributing
 *   JournalEntryLines - the JSON on the header is for human inspection,
 *   this pivot is what queries and reconciles.
 * - `tax_credits`: negative TVA nets carried forward (§7.2 step 6).
 *
 * `period_month` is 0 for annual declarations (a NULL month would let
 * MySQL admit duplicate annual rows through the unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §7.1 "Form box codes ship empty ... NEEDS VERIFICATION; until
        // supplied, declarations generate with internal codes and cannot be
        // marked filed." The mapping is DATA on the reference type: NULL =
        // unmapped-and-blocking. (dsf_annual is exempt from this gate - its
        // line codes come from ChartOfAccount.dsf_line_code, which IS the
        // verified mapping mechanism, §7.5.)
        Schema::table('tax_declaration_types', function (Blueprint $table): void {
            $table->json('form_boxes')->nullable()->after('period_type');
        });

        Schema::create('tax_declarations', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The reference type's code, FK'd to its UNIQUE code column so
            // the unique key below stays human-readable per the spec.
            $table->string('declaration_type', 40)->collation('utf8mb4_0900_as_cs');
            $table->foreign('declaration_type', 'fk_tax_declarations_type')
                ->references('code')->on('tax_declaration_types')->restrictOnDelete();

            // month | quarter | year.
            $table->string('period_type', 10);

            $table->unsignedSmallInteger('period_year');
            // 0 = annual (no month component).
            $table->unsignedTinyInteger('period_month')->default(0);

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();

            // Computed from the obligation's due_rule (§7.6); nullable when
            // no obligation is configured for the type yet.
            $table->date('due_date')->nullable();

            $table->string('status', 15)->default('draft');

            $table->dateTime('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();

            $table->dateTime('filed_at')->nullable();
            $table->foreignId('filed_by')->nullable()
                ->constrained('users')->restrictOnDelete();

            // impots_cm | paper | other. The SYSTEM never files anything
            // (§7.4) - this records how the bursar filed.
            $table->string('filing_channel', 12)->nullable();

            // The DGI acknowledgement number - mandatory when filed.
            $table->string('external_reference', 60)->nullable();

            $table->bigInteger('amount_declared')->default(0);
            $table->bigInteger('amount_paid')->default(0);
            $table->dateTime('paid_at')->nullable();
            $table->string('payment_reference', 60)->nullable();

            // §7.4: lateness costs are DISTINCT lines, never buried in tax.
            $table->bigInteger('penalty_amount')->default(0);
            $table->bigInteger('interest_amount')->default(0);

            // One amendment per original, chained.
            $table->foreignId('amends_declaration_id')->nullable()
                ->unique('uq_tax_declarations_amends')
                ->constrained('tax_declarations')->restrictOnDelete();

            // Slot 0 = original, slot N = amendment of declaration N - see
            // the header comment for why the unique key needs it.
            $table->bigInteger('period_slot')
                ->storedAs('COALESCE(amends_declaration_id, 0)');

            // Human-inspection copy of the contributing entry ids; the
            // tax_declaration_entries pivot is the queryable truth.
            $table->json('generated_from_entry_ids')->nullable();

            // SHA-256 over the contributing line set, stored at generation
            // and RE-VERIFIED at filing (§7.1).
            $table->char('inputs_hash', 64)->nullable();

            // The generated form / filed acknowledgement. Plain column: the
            // Documents module (Phase 13) owns its table; no FK target yet.
            $table->unsignedBigInteger('document_id')->nullable();

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(
                ['declaration_type', 'period_year', 'period_month', 'period_slot'],
                'uq_tax_declarations_period'
            );
            $table->index(['fiscal_year_id', 'status'], 'ix_tax_declarations_year');
        });

        DB::statement(
            "ALTER TABLE tax_declarations ADD CONSTRAINT chk_td_status CHECK (status IN ('draft','generated','under_review','filed','paid','amended','cancelled'))"
        );
        DB::statement(
            "ALTER TABLE tax_declarations ADD CONSTRAINT chk_td_period_type CHECK (period_type IN ('month','quarter','year'))"
        );
        DB::statement(
            'ALTER TABLE tax_declarations ADD CONSTRAINT chk_td_month CHECK (period_month <= 12)'
        );
        DB::statement(
            "ALTER TABLE tax_declarations ADD CONSTRAINT chk_td_channel CHECK (filing_channel IS NULL OR filing_channel IN ('impots_cm','paper','other'))"
        );

        Schema::create('tax_declaration_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // CASCADE while draft: the model observer forbids deleting a
            // declaration past draft, so the cascade can only ever fire on
            // a draft's lines.
            $table->foreignId('tax_declaration_id')
                ->constrained('tax_declarations')->cascadeOnDelete();

            $table->unsignedSmallInteger('line_no');

            // Internal code until the official form boxes are verified.
            $table->string('line_code', 40);
            $table->string('label', 200);

            $table->bigInteger('base_amount')->default(0);
            // App\Support\Rate scale (100 000 = 100%); NULL where a rate is
            // meaningless (credit carried, net line).
            $table->unsignedBigInteger('rate_bp')->nullable();
            $table->bigInteger('tax_amount')->default(0);

            $table->boolean('is_late_claim')->default(false);

            // computed | manual. Manual requires a reason.
            $table->string('source', 10)->default('computed');
            $table->string('manual_reason', 255)->nullable();

            // §7.3 per-supplier annex snapshot - name and NIU AT THE TIME,
            // impossible to reconstruct later without the attestations.
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('supplier_name', 200)->nullable();
            $table->string('supplier_niu', 20)->nullable();

            $table->timestamps();

            $table->unique(['tax_declaration_id', 'line_no'], 'uq_tdl_line_no');
        });

        DB::statement(
            "ALTER TABLE tax_declaration_lines ADD CONSTRAINT chk_tdl_source CHECK (source IN ('computed','manual'))"
        );

        Schema::create('tax_declaration_entries', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('tax_declaration_id')
                ->constrained('tax_declarations')->cascadeOnDelete();
            $table->foreignId('journal_entry_id')
                ->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('journal_entry_line_id')
                ->constrained('journal_entry_lines')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['tax_declaration_id', 'journal_entry_line_id'], 'uq_tde_line');
        });

        Schema::create('tax_credits', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')
                ->constrained('fiscal_years')->restrictOnDelete();

            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->default(0);

            // Always positive - the credit the school carries forward.
            $table->bigInteger('amount');

            $table->foreignId('source_declaration_id')
                ->constrained('tax_declarations')->restrictOnDelete();
            $table->foreignId('consumed_in_declaration_id')->nullable()
                ->constrained('tax_declarations')->restrictOnDelete();

            $table->timestamps();

            $table->index(['fiscal_year_id', 'consumed_in_declaration_id'], 'ix_tax_credits_open');
        });

        DB::statement(
            'ALTER TABLE tax_credits ADD CONSTRAINT chk_tc_amount CHECK (amount > 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_credits');
        Schema::dropIfExists('tax_declaration_entries');
        Schema::dropIfExists('tax_declaration_lines');
        Schema::dropIfExists('tax_declarations');
        Schema::table('tax_declaration_types', function (Blueprint $table): void {
            $table->dropColumn('form_boxes');
        });
    }
};
