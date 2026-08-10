<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\ReconciliationSessionStatus;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.2 BR-3 - "a session may only be completed
 * when the état de rapprochement reconciles to zero" - and §13.3's rule that
 * the fourth line must be zero as well.
 *
 * Both refusals are deliberate and neither is overridable:
 *
 *  - a non-zero residual means the books and the relevé disagree by an
 *    amount nobody has explained. Completing anyway would file a signed
 *    document asserting they agree;
 *  - a non-zero "operations on the relevé not yet in the books" line means
 *    the bank charged a fee, or MTN took a commission, that the ledger has
 *    never seen. §13.3 is explicit: that is a real transaction to POST (see
 *    PostStatementLine), not something to reconcile away. A school that
 *    "reconciles away" its MoMo commissions is the §11.3 defect, restored.
 *
 * The état figures are RECOMPUTED here from live data before the check, so
 * a session cannot be closed against stale numbers that were true an hour
 * ago; they are then frozen on the row, which is what the printable
 * statement renders. The database CHECK
 * `ck_reconciliation_sessions_completed_ties` says the same thing again, so
 * no other write path can produce a completed-but-untrue session.
 *
 * Not implemented here, and named so it is not mistaken for done: §13.3 also
 * asks for the état to be persisted as an immutable PDF with its own
 * document hash in `StatutoryBook`/`IssuedDocument` style (§14). The screen
 * exports a PDF on demand through the shared PdfExport; wiring it into the
 * hashed document register is left to the documents module and reported.
 */
final class CloseReconciliationSession
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly BuildReconciliationStatement $etat,
    ) {}

    public function handle(int $sessionId, Actor $actor, ?string $notes = null): ReconciliationSession
    {
        Gate::authorize(Permission::LedgerPost->value);

        return DB::transaction(function () use ($sessionId, $actor, $notes): ReconciliationSession {
            /** @var ReconciliationSession $session */
            $session = ReconciliationSession::query()->lockForUpdate()->findOrFail($sessionId);

            if (! $session->isDraft()) {
                throw new DomainException('This reconciliation is already completed.');
            }

            if ($session->bank_statement_id === null) {
                throw new DomainException('There is nothing to reconcile against: no statement is attached.');
            }

            $etat = $this->etat->handle($session, persist: true);

            if ($etat['unrecorded_statement_items'] !== 0) {
                throw new DomainException(sprintf(
                    'The relevé still carries %d FCFA the books have never seen. §13.3: post it '
                    .'(a bank charge, an operator commission, a direct debit) - it cannot be reconciled away.',
                    $etat['unrecorded_statement_items'],
                ));
            }

            if ($etat['computed_difference'] !== 0) {
                throw new DomainException(sprintf(
                    'BR-3: the état does not tie. Book balance %d against statement %d '
                    .'+ deposits in transit %d − unpresented %d leaves %d unexplained.',
                    $etat['book_balance'],
                    $etat['statement_balance'],
                    $etat['deposits_in_transit'],
                    $etat['unpresented_payments'],
                    $etat['computed_difference'],
                ));
            }

            $session->forceFill([
                'status' => ReconciliationSessionStatus::Completed->value,
                'completed_by' => $actor->id,
                'completed_at' => now(),
                'notes' => $notes ?? $session->notes,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: ReconciliationSession::class,
                auditableId: (int) $session->getKey(),
                before: ['status' => ReconciliationSessionStatus::Draft->value],
                after: [
                    'status' => ReconciliationSessionStatus::Completed->value,
                    'session_no' => $session->session_no,
                ] + $etat,
                actor: $actor,
            );

            return $session->refresh();
        });
    }
}
