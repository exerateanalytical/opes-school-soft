<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Domain\Permission;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.3 - the état de rapprochement, computed.
 *
 *     statement balance                      (the relevé's closing figure)
 *   + deposits in transit                    (ledger DEBITS not on the relevé)
 *   − unpresented payments                   (ledger CREDITS not on the relevé)
 *   − unrecorded statement items             (on the relevé, not in the books)
 *   = book balance                           (the class-5 account's balance)
 *
 * and `difference = book − (that sum)`: the residual the four lines fail to
 * explain. Zero is the only acceptable value at completion, and the
 * unrecorded line must be zero too (§13.3: something the bank recorded and
 * the books did not is a real transaction to POST, not to reconcile away).
 *
 * Conventions, each of which is a decision rather than an accident:
 *
 *  - the book balance comes from `JournalEntry::postedLedger()` - `posted`
 *    AND `reversed`, never `draft` (L13). Dropping `reversed` would keep a
 *    reversal and lose the entry it cancels;
 *  - it is scoped to the period's FISCAL YEAR and dated `<= ends_on`,
 *    matching TrialBalance. Running cumulatively across years would count
 *    both a closing balance and the à-nouveau that restates it;
 *  - an `ignored` statement line counts in the unrecorded figure exactly as
 *    an unmatched one does. Otherwise "ignore" would become a way to make a
 *    session tie by pretending the bank did not say what it said;
 *  - arithmetic in PHP via Money, never in SQL.
 *
 * Read-only unless `$persist` (the caller's write), and gated `ledger.view`.
 *
 * @phpstan-type Etat array{book_balance: int, statement_balance: int, deposits_in_transit: int, unpresented_payments: int, unrecorded_statement_items: int, computed_difference: int, ties: bool}
 */
final class BuildReconciliationStatement
{
    /**
     * @phpstan-return Etat
     */
    public function handle(ReconciliationSession $session, bool $persist = false): array
    {
        Gate::authorize(Permission::LedgerView->value);

        /** @var AccountingPeriod $period */
        $period = AccountingPeriod::query()->findOrFail($session->accounting_period_id);

        $bookBalance = $this->bookBalance(
            (int) $session->treasury_account_id,
            (int) $period->fiscal_year_id,
            $period->ends_on->toDateString(),
        );

        $unmatchedLedger = $this->unmatchedLedgerTotals(
            (int) $session->treasury_account_id,
            (int) $period->fiscal_year_id,
            $period->ends_on->toDateString(),
        );

        $statementBalance = 0;
        $unrecorded = Money::zero();

        if ($session->bank_statement_id !== null) {
            $statement = $session->statement()->first();

            if ($statement !== null) {
                $statementBalance = $statement->closing_balance;

                /** @var iterable<int, BankStatementLine> $open */
                $open = BankStatementLine::query()
                    ->where('bank_statement_id', $statement->getKey())
                    ->whereIn('status', [
                        StatementLineStatus::Unmatched->value,
                        StatementLineStatus::Ignored->value,
                    ])
                    ->get();

                foreach ($open as $line) {
                    $unrecorded = $unrecorded->plus(Money::of($line->signedAmount()));
                }
            }
        }

        $expectedBook = Money::of($statementBalance)
            ->plus(Money::of($unmatchedLedger['deposits']))
            ->minus(Money::of($unmatchedLedger['unpresented']))
            ->minus($unrecorded);

        $difference = Money::of($bookBalance)->minus($expectedBook);

        $etat = [
            'book_balance' => $bookBalance,
            'statement_balance' => $statementBalance,
            'deposits_in_transit' => $unmatchedLedger['deposits'],
            'unpresented_payments' => $unmatchedLedger['unpresented'],
            'unrecorded_statement_items' => $unrecorded->amount(),
            'computed_difference' => $difference->amount(),
            'ties' => $difference->amount() === 0 && $unrecorded->amount() === 0,
        ];

        if ($persist) {
            $session->forceFill([
                'book_balance' => $etat['book_balance'],
                'statement_balance' => $etat['statement_balance'],
                'deposits_in_transit' => $etat['deposits_in_transit'],
                'unpresented_payments' => $etat['unpresented_payments'],
                'unrecorded_statement_items' => $etat['unrecorded_statement_items'],
                'computed_difference' => $etat['computed_difference'],
            ])->save();
        }

        return $etat;
    }

    /** Σ(debit − credit) on the float, real entries only, up to the period end. */
    private function bookBalance(int $accountId, int $fiscalYearId, string $asOf): int
    {
        $entries = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereDate('date', '<=', $asOf)
            ->select('id');

        /** @var object{total_debit: string|int|null, total_credit: string|int|null}|null $row */
        $row = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->selectRaw('COALESCE(SUM(l.debit), 0) as total_debit, COALESCE(SUM(l.credit), 0) as total_credit')
            ->first();

        if ($row === null) {
            return 0;
        }

        return Money::of((int) $row->total_debit)->minus(Money::of((int) $row->total_credit))->amount();
    }

    /**
     * The two middle lines of the état: what the books have and the relevé
     * has not, split by side.
     *
     * @return array{deposits: int, unpresented: int}
     */
    private function unmatchedLedgerTotals(int $accountId, int $fiscalYearId, string $asOf): array
    {
        $entries = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereDate('date', '<=', $asOf)
            ->select('id');

        /** @var object{total_debit: string|int|null, total_credit: string|int|null}|null $row */
        $row = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->whereNull('l.reconciliation_match_id')
            ->selectRaw('COALESCE(SUM(l.debit), 0) as total_debit, COALESCE(SUM(l.credit), 0) as total_credit')
            ->first();

        if ($row === null) {
            return ['deposits' => 0, 'unpresented' => 0];
        }

        return [
            'deposits' => (int) $row->total_debit,
            'unpresented' => (int) $row->total_credit,
        ];
    }
}
