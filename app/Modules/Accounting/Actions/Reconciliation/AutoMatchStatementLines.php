<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.3: "Auto-matching proposes matches on
 * (amount, date ± n days, reference substring) with a confidence score. It
 * PROPOSES; a human accepts."
 *
 * Two entry points, and the difference between them is the spec's sentence:
 *
 *  - `propose()` is read-only. It returns the candidate pairs and their
 *    scores and writes nothing - this is what the screen renders;
 *  - `handle()` commits the proposals whose confidence reaches the caller's
 *    threshold, through MatchReconciliationLines, which re-proves BR-1 and
 *    BR-2 rather than trusting the matcher. Auto-accept is therefore
 *    something a human triggers with a threshold in front of them, never a
 *    background job; §13.3's "auto-accept above a threshold is a setting,
 *    off by default" is honoured by there being no code path that runs this
 *    unattended.
 *
 * Scoring (basis points): 6000 for the amount agreeing to the centime -
 * without which there is no candidate at all - plus up to 2500 for date
 * proximity (2500 same day, decaying to 0 at the window edge) plus 1500 when
 * the relevé's reference appears in the ledger line's label or in its
 * entry's reference. 10000 is a same-day, same-amount, same-reference hit.
 *
 * Strictly 1:1. A statement line covering three ledger lines is real and the
 * screen supports it manually; guessing which three is how an auto-matcher
 * quietly reconciles the wrong money. Ambiguity is resolved greedily by
 * score, which is safe precisely BECAUSE candidates tie only when they are
 * the same amount on the same day - ten identical 92 500 MoMo receipts are
 * interchangeable, and pairing any one with any other is the same statement.
 */
final class AutoMatchStatementLines
{
    /** §13.3's "date ± n days". Five days covers a weekend plus a holiday. */
    private const DATE_WINDOW_DAYS = 5;

    public function __construct(
        private readonly MatchReconciliationLines $match,
    ) {}

    /**
     * @return list<array{statement_line_id: int, statement_line_no: int, statement_label: string, journal_entry_line_id: int, ledger_label: string, piece_no: string|null, amount: int, confidence_bp: int}>
     */
    public function propose(ReconciliationSession $session): array
    {
        Gate::authorize(Permission::LedgerView->value);

        if ($session->bank_statement_id === null) {
            return [];
        }

        /** @var AccountingPeriod $period */
        $period = AccountingPeriod::query()->findOrFail($session->accounting_period_id);

        /** @var list<BankStatementLine> $statementLines */
        $statementLines = BankStatementLine::query()
            ->where('bank_statement_id', $session->bank_statement_id)
            ->where('status', StatementLineStatus::Unmatched->value)
            ->orderBy('line_no')
            ->get()
            ->all();

        $ledgerLines = $this->unmatchedLedgerLines(
            (int) $session->treasury_account_id,
            (int) $period->fiscal_year_id,
            $period->ends_on->toDateString(),
        );

        $taken = [];
        $proposals = [];

        foreach ($statementLines as $statementLine) {
            $best = null;
            $bestScore = -1;

            foreach ($ledgerLines as $ledgerLine) {
                if (isset($taken[$ledgerLine->id])) {
                    continue;
                }

                $signedLedger = (int) $ledgerLine->debit - (int) $ledgerLine->credit;

                if ($signedLedger !== $statementLine->signedAmount()) {
                    continue;
                }

                $days = abs(Carbon::parse($ledgerLine->date)->diffInDays($statementLine->operation_date));

                if ($days > self::DATE_WINDOW_DAYS) {
                    continue;
                }

                $score = 6000
                    + (int) round(2500 * (1 - ($days / self::DATE_WINDOW_DAYS)))
                    + ($this->referenceHits($statementLine->reference, $ledgerLine) ? 1500 : 0);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $ledgerLine;
                }
            }

            if ($best === null) {
                continue;
            }

            $taken[$best->id] = true;

            $proposals[] = [
                'statement_line_id' => (int) $statementLine->getKey(),
                'statement_line_no' => $statementLine->line_no,
                'statement_label' => $statementLine->label,
                'journal_entry_line_id' => (int) $best->id,
                'ledger_label' => (string) $best->label,
                'piece_no' => $best->piece_no === null ? null : (string) $best->piece_no,
                'amount' => $statementLine->signedAmount(),
                'confidence_bp' => min(10000, $bestScore),
            ];
        }

        return $proposals;
    }

    /**
     * Commit every proposal at or above `$thresholdBp`.
     *
     * @return array{matched: int, skipped: int, amount: int}
     */
    public function handle(int $sessionId, Actor $actor, int $thresholdBp = 8000): array
    {
        Gate::authorize(Permission::LedgerPost->value);

        /** @var ReconciliationSession $session */
        $session = ReconciliationSession::query()->findOrFail($sessionId);

        if (! $session->isDraft()) {
            throw new DomainException('This reconciliation is completed; nothing more can be matched into it.');
        }

        $matched = 0;
        $skipped = 0;
        $amount = 0;

        foreach ($this->propose($session) as $proposal) {
            if ($proposal['confidence_bp'] < $thresholdBp) {
                $skipped++;

                continue;
            }

            $this->match->handle(
                sessionId: $sessionId,
                statementLineIds: [$proposal['statement_line_id']],
                ledgerLineIds: [$proposal['journal_entry_line_id']],
                actor: $actor,
                isAuto: true,
                confidenceBp: $proposal['confidence_bp'],
            );

            $matched++;
            $amount += $proposal['amount'];
        }

        return ['matched' => $matched, 'skipped' => $skipped, 'amount' => $amount];
    }

    /**
     * Real ledger lines on the float, still unmatched, up to the period end -
     * with their entry's date, piece number and reference, which is what the
     * scorer needs.
     *
     * @return list<object{id: int, label: string, debit: int, credit: int, date: string, piece_no: string|null, reference: string|null}>
     */
    private function unmatchedLedgerLines(int $accountId, int $fiscalYearId, string $asOf): array
    {
        $entries = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->whereDate('date', '<=', $asOf)
            ->select(['id', 'date', 'piece_no', 'reference']);

        /** @var list<object{id: int, label: string, debit: int, credit: int, date: string, piece_no: string|null, reference: string|null}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($entries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->whereNull('l.reconciliation_match_id')
            ->orderBy('e.date')
            ->orderBy('l.id')
            ->select([
                'l.id',
                'l.label',
                'l.debit',
                'l.credit',
                'e.date',
                'e.piece_no',
                'e.reference',
            ])
            ->get()
            ->all();

        return $rows;
    }

    private function referenceHits(?string $reference, object $ledgerLine): bool
    {
        if ($reference === null || trim($reference) === '') {
            return false;
        }

        $needle = mb_strtolower(trim($reference));

        foreach ([$ledgerLine->label ?? '', $ledgerLine->reference ?? '', $ledgerLine->piece_no ?? ''] as $haystack) {
            if (is_string($haystack) && $haystack !== '' && str_contains(mb_strtolower($haystack), $needle)) {
                return true;
            }
        }

        return false;
    }
}
