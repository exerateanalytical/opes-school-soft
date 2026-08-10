<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §13.1: "`JournalEntryLine.reconciliation_match_id`
 * is `ON DELETE SET NULL` - unmatching deletes the match, never the ledger
 * line."
 *
 * The COLUMN has existed since 2026_08_07_230008 (which also wrote the L3
 * update trigger so that `lettering_id` and `reconciliation_match_id` remain
 * writable on a posted line - that trigger was built for this feature). What
 * was missing is the constraint, because until 390004 there was no table to
 * point at. Adding it now is purely additive: every existing line has NULL
 * there, so no row can fail validation.
 *
 * SET NULL rather than RESTRICT is deliberate and is the one deviation from
 * 00-core §9's default: a match is an ANNOTATION on the ledger, not a
 * dependency of it. Unmatching must be a cheap, reversible act at 16:00 on a
 * Friday; RESTRICT would make deleting a match require touching the ledger
 * line first, and touching ledger lines is exactly what this feature must
 * never make routine.
 *
 * `bank_statement_lines.reconciliation_match_id` gets a RESTRICT instead -
 * see the note at the statement below for why MySQL leaves no choice, and
 * why that is the right answer anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded for the same reason 390004 is: this batch first ran
        // interleaved with another agent's migrate on the same development
        // database, and DROP is not an available repair here.
        if (! $this->constraintExists('fk_jel_reconciliation_match')) {
            Schema::table('journal_entry_lines', function (Blueprint $table): void {
                $table->foreign('reconciliation_match_id', 'fk_jel_reconciliation_match')
                    ->references('id')->on('reconciliation_matches')
                    ->nullOnDelete();
            });
        }

        // Not created in 390002 with the column: `reconciliation_matches`
        // did not exist yet.
        //
        // RESTRICT here, unlike the ledger side, and MySQL settled the
        // argument: `ck_bank_statement_lines_match_status` ties `status` to
        // this column, and MySQL 8 refuses (error 3823) to let a column
        // participate in both a CHECK and a SET NULL referential action -
        // rightly, because a SET NULL would leave `status = 'matched'`
        // beside a null match and break that CHECK. RESTRICT is also the
        // house default, and it costs nothing: UnmatchReconciliation clears
        // both columns on the statement line before it deletes the match, so
        // the constraint never fires in the normal path. What it does is
        // stop any OTHER code from deleting a match out from under a line
        // that still claims to be matched.
        if (! $this->constraintExists('fk_bsl_reconciliation_match')) {
            Schema::table('bank_statement_lines', function (Blueprint $table): void {
                $table->foreign('reconciliation_match_id', 'fk_bsl_reconciliation_match')
                    ->references('id')->on('reconciliation_matches')
                    ->restrictOnDelete();
            });
        }

        // §17.9's trial-balance validation asks "any is_reconcilable account
        // with an incomplete session for any period" - a per-account,
        // per-period lookup over posted lines on class-5 accounts. The
        // existing indexes are (journal_entry_id) and (account_id); this one
        // makes the reconciliation screen's "unmatched ledger lines for this
        // float" query - account + no match - an index range rather than a
        // scan of every line ever written.
        $indexExists = DB::table('information_schema.STATISTICS')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->where('TABLE_NAME', 'journal_entry_lines')
            ->where('INDEX_NAME', 'ix_jel_account_reconciliation')
            ->exists();

        if (! $indexExists) {
            DB::statement(
                'CREATE INDEX `ix_jel_account_reconciliation` '
                .'ON `journal_entry_lines` (`account_id`, `reconciliation_match_id`)'
            );
        }
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
            ->where('CONSTRAINT_NAME', $name)
            ->exists();
    }

    public function down(): void
    {
        DB::statement('DROP INDEX `ix_jel_account_reconciliation` ON `journal_entry_lines`');

        Schema::table('bank_statement_lines', function (Blueprint $table): void {
            $table->dropForeign('fk_bsl_reconciliation_match');
        });

        Schema::table('journal_entry_lines', function (Blueprint $table): void {
            $table->dropForeign('fk_jel_reconciliation_match');
        });
    }
};
