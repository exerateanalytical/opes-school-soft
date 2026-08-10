<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\StatementLineStatus;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\ReconciliationMatch;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.1: "unmatching deletes the match, never the
 * ledger line."
 *
 * Taken literally. Both sides are cleared FIRST - the statement line back to
 * `unmatched`, the ledger line's `reconciliation_match_id` back to NULL -
 * and only then is the match row deleted, its join rows going with it. The
 * FK is `ON DELETE SET NULL` as a second line of defence, but the ledger
 * line is never left to a cascade: on `bank_statement_lines` a bare SET NULL
 * would leave `status = 'matched'` beside a null match and violate that
 * table's CHECK, which is exactly the sort of half-state this ordering
 * removes.
 *
 * A COMPLETED session cannot be unmatched. Its état is signed evidence; the
 * correction path for a wrong reconciliation is a new session in a later
 * period, mirroring how a wrong entry is corrected by reversal and not by
 * editing.
 */
final class UnmatchReconciliation
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly BuildReconciliationStatement $etat,
    ) {}

    public function handle(int $matchId, Actor $actor): ReconciliationSession
    {
        Gate::authorize(Permission::LedgerPost->value);

        $sessionId = DB::transaction(function () use ($matchId, $actor): int {
            /** @var ReconciliationMatch $match */
            $match = ReconciliationMatch::query()->lockForUpdate()->findOrFail($matchId);

            /** @var ReconciliationSession $session */
            $session = ReconciliationSession::query()
                ->lockForUpdate()
                ->findOrFail($match->reconciliation_session_id);

            if (! $session->isDraft()) {
                throw new DomainException(
                    'This reconciliation is completed and its état is evidence; correct it in a later period.'
                );
            }

            /** @var list<int> $statementLineIds */
            $statementLineIds = DB::table('reconciliation_match_statement_lines')
                ->where('reconciliation_match_id', $matchId)
                ->pluck('bank_statement_line_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            /** @var list<int> $ledgerLineIds */
            $ledgerLineIds = DB::table('reconciliation_match_ledger_lines')
                ->where('reconciliation_match_id', $matchId)
                ->pluck('journal_entry_line_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($statementLineIds !== []) {
                BankStatementLine::query()->whereIn('id', $statementLineIds)->update([
                    'reconciliation_match_id' => null,
                    'status' => StatementLineStatus::Unmatched->value,
                    'updated_at' => now(),
                ]);
            }

            if ($ledgerLineIds !== []) {
                JournalEntryLine::query()->whereIn('id', $ledgerLineIds)->update([
                    'reconciliation_match_id' => null,
                ]);
            }

            $match->delete();

            $this->audit->handle(
                action: AuditAction::Deleted,
                module: 'Accounting',
                auditableType: ReconciliationMatch::class,
                auditableId: $matchId,
                before: [
                    'session_no' => $session->session_no,
                    'statement_line_ids' => $statementLineIds,
                    'journal_entry_line_ids' => $ledgerLineIds,
                    'amount' => $match->amount,
                ],
                actor: $actor,
            );

            return (int) $session->getKey();
        });

        /** @var ReconciliationSession $session */
        $session = ReconciliationSession::query()->findOrFail($sessionId);
        $this->etat->handle($session, persist: true);

        return $session->refresh();
    }
}
