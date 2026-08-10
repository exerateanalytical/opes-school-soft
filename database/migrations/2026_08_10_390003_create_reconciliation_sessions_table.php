<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.1 `ReconciliationSession` and §13.3 - one
 * reconciliation of one treasury float for one accounting period, and the
 * persisted **état de rapprochement** it produces.
 *
 * The five money columns ARE §13.3's statement, in its order:
 *
 *     statement_balance                                        4 812 400
 *   + deposits_in_transit    (ledger debits not on the relevé)    350 000
 *   − unpresented_payments   (ledger credits not on the relevé)  (128 500)
 *   − unrecorded_statement_items (on the relevé, not in books)    (12 000)
 *   = book_balance                                             5 021 900
 *
 * They are PERSISTED, not recomputed on read: the état is a document with a
 * date on it, and a document that silently changes when a later entry is
 * posted is not evidence. `computed_difference` is the residual the five
 * numbers do not explain.
 *
 * BR-3 - "a session may only be completed when the état reconciles to zero" -
 * is enforced twice: in CloseReconciliationSession, which refuses and says
 * why, and by `ck_reconciliation_sessions_completed_ties` here, so no other
 * write path can produce a completed-but-untrue session. `unrecorded` must
 * ALSO be zero at completion, per §13.3: something the bank recorded and the
 * books did not is a real transaction that must be POSTED, never reconciled
 * away.
 *
 * UNIQUE(treasury_account_id, accounting_period_id): one session per float
 * per month (§13.1). This is also what §17.9's "any is_reconcilable account
 * with an incomplete session for any period" check reads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_sessions', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // RAP/2026/000001, from the row-locked `reconciliation_session`
            // sequence inside the opening transaction (00-core §12) - never
            // max()+1.
            $table->string('session_no', 30)->collation('utf8mb4_0900_as_cs')
                ->unique('uq_reconciliation_sessions_no');

            $table->foreignId('treasury_account_id')->constrained('chart_of_accounts')->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained('accounting_periods')->restrictOnDelete();

            // The statement being reconciled. Nullable so a session can be
            // opened before the relevé arrives - the normal sequence in a
            // school, where the books are ready days before MTN sends the
            // export.
            $table->foreignId('bank_statement_id')->nullable()->constrained('bank_statements')->restrictOnDelete();

            $table->string('status', 20)->collation('utf8mb4_0900_as_cs')->default('draft');

            $table->bigInteger('book_balance')->default(0);
            $table->bigInteger('statement_balance')->default(0);
            $table->bigInteger('deposits_in_transit')->default(0);
            $table->bigInteger('unpresented_payments')->default(0);
            $table->bigInteger('unrecorded_statement_items')->default(0);
            $table->bigInteger('computed_difference')->default(0);

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('opened_at');
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('completed_at')->nullable();

            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['treasury_account_id', 'accounting_period_id'], 'uq_reconciliation_sessions_scope');
            $table->index('status', 'ix_reconciliation_sessions_status');
        });

        foreach (self::CHECKS as $name => $expression) {
            DB::statement("ALTER TABLE `reconciliation_sessions` ADD CONSTRAINT `{$name}` CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_sessions');
    }

    /** @var array<string, string> */
    private const CHECKS = [
        'ck_reconciliation_sessions_status' => "`status` IN ('draft','completed')",

        // BR-3, at the database. A completed session ties to zero and has
        // nothing left that the bank saw and the books did not.
        'ck_reconciliation_sessions_completed_ties' =>
            "`status` <> 'completed' OR (`computed_difference` = 0 AND `unrecorded_statement_items` = 0)",

        // A completed session names who signed it and when, and rests on an
        // actual statement.
        'ck_reconciliation_sessions_completed_complete' =>
            "`status` <> 'completed' OR (`completed_by` IS NOT NULL AND `completed_at` IS NOT NULL "
            .'AND `bank_statement_id` IS NOT NULL)',

        'ck_reconciliation_sessions_draft_clean' =>
            "`status` <> 'draft' OR (`completed_by` IS NULL AND `completed_at` IS NULL)",
    ];
};
