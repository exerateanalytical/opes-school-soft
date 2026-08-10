<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\ReconciliationMatchType;
use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\ReconciliationMatch;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.1/§13.2 - assert that these statement lines
 * and these ledger lines are the same money.
 *
 * This Action POSTS NOTHING. A match is an annotation on lines that already
 * exist: it writes `reconciliation_match_id` on both sides and not one
 * centime anywhere. That is why reconciling can never change the ledger
 * total, and why the L3 trigger in 2026_08_07_230008 was written to allow
 * exactly this column to move on a posted line and nothing else.
 *
 * BR-1 (Σ statement = Σ ledger, same sign convention) is proved here in PHP
 * with Money: statement `credit − debit` against ledger `debit − credit`,
 * because money INTO the account is a credit on the relevé and a debit in
 * the books. BR-2 (each line in at most one match) is the UNIQUE index on
 * the join tables; the explicit check below exists to produce a sentence a
 * bursar can act on instead of a duplicate-key error.
 *
 * Both sides are taken `lockForUpdate()` before their match ids are read -
 * the same race-closing order LetterEntries uses for lettering.
 */
final class MatchReconciliationLines
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly BuildReconciliationStatement $etat,
    ) {}

    /**
     * @param  list<int>  $statementLineIds
     * @param  list<int>  $ledgerLineIds
     */
    public function handle(
        int $sessionId,
        array $statementLineIds,
        array $ledgerLineIds,
        Actor $actor,
        bool $isAuto = false,
        int $confidenceBp = 10000,
    ): ReconciliationMatch {
        Gate::authorize(Permission::LedgerPost->value);

        $statementLineIds = array_values(array_unique($statementLineIds));
        $ledgerLineIds = array_values(array_unique($ledgerLineIds));

        if ($statementLineIds === [] || $ledgerLineIds === []) {
            throw new DomainException('A match needs at least one line on each side.');
        }

        $match = DB::transaction(function () use (
            $sessionId, $statementLineIds, $ledgerLineIds, $actor, $isAuto, $confidenceBp,
        ): ReconciliationMatch {
            /** @var ReconciliationSession $session */
            $session = ReconciliationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $session->isDraft()) {
                throw new DomainException('This reconciliation is completed; re-open nothing - unmatch is refused too.');
            }

            if ($session->bank_statement_id === null) {
                throw new DomainException('Attach the statement to the session before matching against it.');
            }

            /** @var \Illuminate\Support\Collection<int, BankStatementLine> $statementLines */
            $statementLines = BankStatementLine::query()
                ->whereIn('id', $statementLineIds)
                ->lockForUpdate()
                ->get();

            if ($statementLines->count() !== count($statementLineIds)) {
                throw new DomainException('One or more statement lines do not exist.');
            }

            $statementTotal = Money::zero();

            foreach ($statementLines as $line) {
                if ((int) $line->bank_statement_id !== (int) $session->bank_statement_id) {
                    throw new DomainException('A statement line from another relevé cannot join this session.');
                }

                if ($line->reconciliation_match_id !== null) {
                    throw new DomainException('BR-2: statement line '.$line->line_no.' is already matched.');
                }

                $statementTotal = $statementTotal->plus(Money::of($line->signedAmount()));
            }

            /** @var \Illuminate\Support\Collection<int, JournalEntryLine> $ledgerLines */
            $ledgerLines = JournalEntryLine::query()
                ->whereIn('id', $ledgerLineIds)
                ->lockForUpdate()
                ->get();

            if ($ledgerLines->count() !== count($ledgerLineIds)) {
                throw new DomainException('One or more ledger lines do not exist.');
            }

            $ledgerTotal = Money::zero();

            foreach ($ledgerLines as $line) {
                if ((int) $line->account_id !== (int) $session->treasury_account_id) {
                    throw new DomainException('A ledger line on another account cannot be matched to this float.');
                }

                if ($line->reconciliation_match_id !== null) {
                    throw new DomainException('BR-2: a ledger line in this selection is already matched.');
                }

                $ledgerTotal = $ledgerTotal->plus(Money::of((int) $line->debit - (int) $line->credit));
            }

            // Only real accounting reality is matchable - a draft entry is
            // not yet money (L13, mirroring LT-5 for lettering).
            $draftExists = JournalEntry::query()
                ->whereIn('id', $ledgerLines->pluck('journal_entry_id')->unique()->values()->all())
                ->whereNotIn('status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
                ->exists();

            if ($draftExists) {
                throw new DomainException('Only lines on a posted or reversed entry can be reconciled.');
            }

            if (! $statementTotal->equals($ledgerTotal)) {
                throw new DomainException(sprintf(
                    'BR-1: the two sides do not agree - relevé %d against books %d.',
                    $statementTotal->amount(),
                    $ledgerTotal->amount(),
                ));
            }

            $match = ReconciliationMatch::query()->create([
                'reconciliation_session_id' => $sessionId,
                'match_type' => ReconciliationMatchType::forCounts(
                    $statementLines->count(),
                    $ledgerLines->count(),
                )->value,
                'amount' => $ledgerTotal->amount(),
                'is_auto' => $isAuto,
                'confidence_bp' => max(0, min(10000, $confidenceBp)),
                'matched_by' => $actor->id,
                'matched_at' => now(),
            ]);

            $matchId = (int) $match->getKey();

            DB::table('reconciliation_match_statement_lines')->insert(array_map(
                static fn (int $id): array => [
                    'reconciliation_match_id' => $matchId,
                    'bank_statement_line_id' => $id,
                ],
                $statementLineIds,
            ));

            DB::table('reconciliation_match_ledger_lines')->insert(array_map(
                static fn (int $id): array => [
                    'reconciliation_match_id' => $matchId,
                    'journal_entry_line_id' => $id,
                ],
                $ledgerLineIds,
            ));

            BankStatementLine::query()->whereIn('id', $statementLineIds)->update([
                'reconciliation_match_id' => $matchId,
                'status' => StatementLineStatus::Matched->value,
                'ignore_reason' => null,
                'updated_at' => now(),
            ]);

            // The one write this feature makes to the ledger, and it is not
            // a financial one.
            JournalEntryLine::query()->whereIn('id', $ledgerLineIds)->update([
                'reconciliation_match_id' => $matchId,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: ReconciliationMatch::class,
                auditableId: $matchId,
                after: [
                    'session_no' => $session->session_no,
                    'statement_line_ids' => $statementLineIds,
                    'journal_entry_line_ids' => $ledgerLineIds,
                    'amount' => $ledgerTotal->amount(),
                    'is_auto' => $isAuto,
                    'confidence_bp' => $confidenceBp,
                ],
                actor: $actor,
            );

            return $match;
        });

        $session = ReconciliationSession::query()->findOrFail($sessionId);
        $this->etat->handle($session, persist: true);

        return $match->refresh();
    }
}
