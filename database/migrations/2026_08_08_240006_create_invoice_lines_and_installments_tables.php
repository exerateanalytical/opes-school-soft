<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/04-fees.md §3.2 (invoice_lines) and §3.3 (invoice_installments).
 *
 * Lines SNAPSHOT everything money-bearing at issue: a later change to the fee
 * item must never reclassify historical revenue. `fee_item_id` is nullable
 * and carried for reporting only - the snapshot columns are authoritative.
 *
 * Instalments are a payment schedule over the invoice as a WHOLE, not a
 * partition of lines (§3.3) - allocation stays line-level (A2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RESTRICT - never cascade into a financial record (00-core §10.5).
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedSmallInteger('line_no');

            $table->foreignId('fee_item_id')->nullable()->constrained('fee_items')->restrictOnDelete();

            // Snapshots (§3.2).
            $table->string('description', 200);
            $table->string('description_fr', 200)->nullable();
            $table->string('fee_category_code', 30)->nullable();
            $table->enum('collection_basis', ['own_revenue', 'agent_for_third_party']);
            $table->foreignId('third_party_fund_id')->nullable()->constrained('third_party_funds')->restrictOnDelete();
            $table->foreignId('revenue_account_id')->nullable()->constrained('chart_of_accounts')->restrictOnDelete();
            $table->enum('recognition_method', ['on_issue', 'straight_line_over_period', 'on_collection'])->default('on_issue');
            // Phase-5 tax integration: plain nullable reference, same pattern
            // journal_entries used for posting_rule_id before that engine landed.
            $table->unsignedBigInteger('tax_code_id')->nullable();

            $table->unsignedInteger('quantity')->default(1);
            $table->bigInteger('unit_amount');
            $table->bigInteger('amount');
            $table->bigInteger('tax_amount')->default(0);

            // C4 - drive revenue recognition (§6).
            $table->date('service_period_start')->nullable();
            $table->date('service_period_end')->nullable();

            $table->json('analytic_values_json')->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'line_no'], 'uq_invoice_lines_no');
        });

        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT ck_il_amounts CHECK (unit_amount >= 0 AND amount >= 0)');
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT ck_il_service_period CHECK ((service_period_start IS NULL) = (service_period_end IS NULL))');
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT ck_il_service_order CHECK (service_period_end IS NULL OR service_period_end >= service_period_start)');

        Schema::create('invoice_installments', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->unsignedTinyInteger('sequence_no');
            $table->string('label', 120);
            $table->string('label_fr', 120)->nullable();
            $table->bigInteger('amount');
            // Aging is by INSTALMENT due date, not invoice date (§11.3).
            $table->date('due_date');
            // Set by WithdrawalSettlement for unbilled future tranches; always
            // paired with a credit note or write-off, never a silent reduction.
            $table->boolean('is_cancelled')->default(false);
            $table->string('cancelled_reason', 200)->nullable();
            $table->timestamps();

            $table->unique(['invoice_id', 'sequence_no'], 'uq_invoice_installments_seq');
        });

        DB::statement('ALTER TABLE invoice_installments ADD CONSTRAINT ck_ii_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_installments');
        Schema::dropIfExists('invoice_lines');
    }
};
