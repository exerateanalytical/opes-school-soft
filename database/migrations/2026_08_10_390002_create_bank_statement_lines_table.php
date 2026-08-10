<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.1 `BankStatementLine` - one movement as the
 * BANK (or the operator) recorded it.
 *
 * Sign convention, stated once here and obeyed by every Action:
 *
 *   `credit` = money INTO the school's account (a receipt);
 *   `debit`  = money OUT of it (a payment, a charge, a commission).
 *
 * which is the counterparty's wording on the document the bursar is holding.
 * The LEDGER's convention on the same treasury account is the mirror image -
 * money in is a DEBIT on the class-5 account - so a match compares
 * `Σ(credit − debit)` on the statement side against `Σ(debit − credit)` on
 * the ledger side. Getting this backwards is the classic reconciliation bug;
 * it is written down rather than left to be rediscovered.
 *
 * `CHECK ((debit=0) <> (credit=0))` is §13.1 verbatim: a statement line is
 * one-sided and never zero, exactly as a journal line is.
 *
 * `reconciliation_match_id` is added nullable here and given its FK in
 * 390005, once `reconciliation_matches` exists - the same ordering the
 * ledger side needs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statement_lines', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->foreignId('bank_statement_id')->constrained('bank_statements')->restrictOnDelete();

            // Position on the document as printed - the number an auditor
            // reads out loud. UNIQUE per statement (§13.1).
            $table->unsignedInteger('line_no');

            $table->date('operation_date');
            $table->date('value_date')->nullable();

            $table->string('label', 255);

            // The operator/bank reference - "MM-88421", a cheque number. The
            // auto-matcher scores a substring hit on this against the ledger
            // line's label and its entry's reference, so it is indexed.
            $table->string('reference', 120)->nullable();

            $table->bigInteger('debit')->default(0);
            $table->bigInteger('credit')->default(0);

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('unmatched');

            // §13.1: why a line was set aside without being matched. Required
            // when status is `ignored` - a line dismissed with no reason is
            // precisely what an audit trail must not contain.
            $table->string('ignore_reason', 400)->nullable();

            $table->unsignedBigInteger('reconciliation_match_id')->nullable();

            // Set when §13.3's "post this statement line" turns a bank charge
            // or a MoMo commission the books had never seen into a real
            // entry. Nullable and RESTRICT: the entry outlives the line.
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();

            $table->timestamps();

            $table->unique(['bank_statement_id', 'line_no'], 'uq_bank_statement_lines_no');
            $table->index(['bank_statement_id', 'status'], 'ix_bank_statement_lines_status');
            $table->index(['operation_date'], 'ix_bank_statement_lines_date');
            $table->index(['reference'], 'ix_bank_statement_lines_reference');
            $table->index(['reconciliation_match_id'], 'ix_bank_statement_lines_match');
        });

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `bank_statement_lines` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
    }

    /** @var array<string, string> */
    private const CHECKS = [
        'ck_bank_statement_lines_status' => "`status` IN ('unmatched','matched','ignored')",
        'ck_bank_statement_lines_nonneg' => '`debit` >= 0 AND `credit` >= 0',

        // §13.1 verbatim - one-sided, never zero.
        'ck_bank_statement_lines_one_side' => '(`debit` = 0) <> (`credit` = 0)',

        // An ignored line explains itself; a line that is not ignored carries
        // no dangling excuse.
        'ck_bank_statement_lines_ignore_reason' =>
            "(`status` <> 'ignored' AND `ignore_reason` IS NULL) "
            ."OR (`status` = 'ignored' AND `ignore_reason` IS NOT NULL AND `ignore_reason` <> '')",

        // `matched` and "has a match" are the same fact; they may not drift.
        'ck_bank_statement_lines_match_status' =>
            "(`status` = 'matched') = (`reconciliation_match_id` IS NOT NULL)",
    ];
};
