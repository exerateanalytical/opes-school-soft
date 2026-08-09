<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/03-tax-procurement.md §3.3 - the 4818 "factures non parvenues"
 * working paper: one row per goods-receipt line that had NO matched
 * supplier invoice at the closing date, valued at PO price.
 *
 * Written by the year-end cut-off run (`RunYearEndPurchaseAccrual`,
 * 02-accounting C8): `Dr 60x/61x/62x / Cr 4818` at the closing date, and
 * REVERSED on the first day of the next period - both entry ids recorded
 * here so an auditor can walk from the working paper to the ledger and
 * back.
 *
 * UNIQUE(fiscal_year_id, goods_receipt_line_id): a receipt line is accrued
 * at most once per closing - re-running the cut-off is idempotent at the
 * database, not by convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_accruals', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('fiscal_year_id')->constrained('fiscal_years')->restrictOnDelete();
            $table->foreignId('goods_receipt_line_id')->constrained('goods_receipt_lines')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();

            // Valued at PO price (§3.3): accepted-not-invoiced quantity at
            // the closing date × the PO line's HT price.
            $table->decimal('quantity', 12, 3);
            $table->bigInteger('amount_ht');

            $table->foreignId('expense_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('accrual_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('reversal_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['fiscal_year_id', 'goods_receipt_line_id'], 'uq_pa_year_line');
            $table->index('supplier_id', 'ix_pa_supplier');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE purchase_accruals
              ADD CONSTRAINT ck_pa_amount CHECK (amount_ht > 0)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_accruals');
    }
};
