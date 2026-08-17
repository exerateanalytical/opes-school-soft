<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\ReconciliationSessionStatus;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Accounting\Models\ReconciliationStatement;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Barryvdh\DomPDF\Facade\Pdf;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

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
 * §13.3 also asks for the état to be persisted as an immutable PDF with its
 * own document hash, `StatutoryBook`-style (§14). That happens here, inside
 * this same transaction: the frozen figures are rendered through the same
 * `reports.pdf-shell` view the screen's on-demand `exportPdf()` uses, hashed,
 * written to storage, and recorded as a `ReconciliationStatement` row - a
 * NEW table rather than a row in `statutory_books`, because that table's own
 * CHECK constraint enumerates AUDCIF's four named books and requires a
 * `fiscal_year_id`, neither of which an état de rapprochement is. Doing this
 * inside the transaction that marks the session completed means a completed
 * session and its registered document appear together or not at all.
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

            $this->registerStatement($session, $etat, $actor);

            return $session->refresh();
        });
    }

    /**
     * @param  array{book_balance: int, statement_balance: int, deposits_in_transit: int, unpresented_payments: int, unrecorded_statement_items: int, computed_difference: int, ties: bool}  $etat
     */
    private function registerStatement(ReconciliationSession $session, array $etat, Actor $actor): void
    {
        $account = $session->treasuryAccount()->first();
        $period = $session->accountingPeriod()->first();
        $generatedAt = now();

        $pdf = Pdf::loadView('reports.pdf-shell', [
            'title' => sprintf(
                'Etat de rapprochement %s - %s %s',
                $session->session_no,
                $account?->code ?? '',
                $period === null ? '' : $period->ends_on->toDateString(),
            ),
            'headers' => ['Libelle', 'Montant (FCFA)'],
            'rows' => [
                ['Solde du releve au '.($period === null ? '' : $period->ends_on->toDateString()), $etat['statement_balance']],
                ['+ Encaissements comptabilises non encore au releve', $etat['deposits_in_transit']],
                ['- Decaissements comptabilises non encore au releve', -$etat['unpresented_payments']],
                ['- Operations au releve non encore comptabilisees', -$etat['unrecorded_statement_items']],
                ['= Solde comptable au '.($period === null ? '' : $period->ends_on->toDateString()), $etat['book_balance']],
                ['Difference non expliquee', $etat['computed_difference']],
            ],
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
        ])->setPaper('a4', 'portrait');

        $binary = $pdf->output();
        $sha256 = hash('sha256', $binary);

        $path = sprintf(
            'reconciliation-statements/%s-%s.pdf',
            str_replace('/', '-', $session->session_no),
            $generatedAt->format('YmdHis'),
        );

        Storage::disk('local')->put($path, $binary);

        $previous = ReconciliationStatement::query()
            ->where('reconciliation_session_id', $session->getKey())
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        ReconciliationStatement::query()->create([
            'reconciliation_session_id' => $session->getKey(),
            'generated_at' => $generatedAt,
            'generated_by' => $actor->id,
            'file_path' => $path,
            'sha256' => $sha256,
            'supersedes_id' => $previous?->getKey(),
        ]);
    }
}
