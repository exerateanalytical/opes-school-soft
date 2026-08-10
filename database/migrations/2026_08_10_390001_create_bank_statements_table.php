<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.1 - the external document a treasury float
 * is reconciled AGAINST: a bank statement, or - and this is the whole point
 * of §1.3 - an MTN/Orange operator statement, which reconciles by exactly
 * the same mechanics.
 *
 * §13.1 names the owning entity `BankAccount`. There is deliberately no such
 * table here: in this codebase a treasury account IS a `chart_of_accounts`
 * class-5 row (that is what `payments.treasury_account_id`,
 * `supplier_payments.treasury_account_id` and `cash_desk_sessions` already
 * point at, migrations 320001/350001), and `chart_of_accounts.is_reconcilable`
 * already carries §13's "has an external statement" flag. A parallel bank
 * registry would fragment the model and give the MoMo floats a second
 * identity - the very defect §1.3 exists to prevent. `treasury_account_id`
 * therefore points straight at the chart row; the descriptive bank fields
 * (agency, RIB, SWIFT) are the school profile's business, not the ledger's,
 * and are not invented here.
 *
 * Money is BIGINT signed FCFA (00-core). `opening_balance`/`closing_balance`
 * are the ACCOUNT's balance as the counterparty sees it: positive is money
 * the school holds. Signed, because an overdrawn bank account is a real
 * state and a CHECK forbidding it would be a lie.
 *
 * Deletes RESTRICT: a statement is the evidence behind an état de
 * rapprochement, and evidence does not disappear because someone archived
 * an account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_statements', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // The class-5 chart row this statement belongs to. That it is
            // postable, class 5 and `is_reconcilable` is validated in
            // ImportBankStatement, which can dereference the FK; a CHECK
            // cannot reach another table.
            $table->foreignId('treasury_account_id')->constrained('chart_of_accounts')->restrictOnDelete();

            // The counterparty's own reference for the document - "MTN-2026-10",
            // "REL/521/2026/10". Identifier collation (00-core §4): case and
            // accent sensitive, because two statements differing only in case
            // are two different documents.
            $table->string('statement_reference', 60)->collation('utf8mb4_0900_as_cs');

            $table->date('period_start');
            $table->date('period_end');

            $table->bigInteger('opening_balance')->default(0);
            $table->bigInteger('closing_balance')->default(0);

            // How it got here. `ofx` is in §13.1's list and is accepted by
            // the CHECK so an importer can be added without a migration;
            // only `manual` and `csv` have a code path today. There is no
            // bank-API integration and none is implied.
            $table->string('source', 20)->collation('utf8mb4_0900_as_cs')->default('manual');

            // §13.1: the hash of the imported file, so the same file cannot
            // be silently re-imported as a different statement and so an
            // auditor can tie the row to the document on disk.
            $table->char('file_sha256', 64)->nullable();

            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('imported_at');

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            // §13.1 UNIQUE(bank_account_id, statement_reference) - restated
            // against the treasury account, which is what carries identity
            // here.
            $table->unique(['treasury_account_id', 'statement_reference'], 'uq_bank_statements_ref');
            $table->index(['treasury_account_id', 'period_end'], 'ix_bank_statements_account_period');
        });

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `bank_statements` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statements');
    }

    /** @var array<string, string> */
    private const CHECKS = [
        'ck_bank_statements_source' => "`source` IN ('manual','csv','ofx')",
        'ck_bank_statements_period' => '`period_end` >= `period_start`',
        'ck_bank_statements_reference' => "`statement_reference` <> ''",
    ];
};
