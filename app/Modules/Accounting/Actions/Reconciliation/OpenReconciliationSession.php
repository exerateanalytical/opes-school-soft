<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Domain\ReconciliationSessionStatus;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\BankStatement;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.1 - open (or re-open the handle on) the
 * reconciliation of ONE float for ONE month.
 *
 * `UNIQUE(treasury_account_id, accounting_period_id)` makes "one session per
 * account per period" a database fact, so this Action does not check-then-
 * insert: it looks for the existing session and returns it, and a genuine
 * race lands on the unique key rather than producing two sessions. That is
 * also why MTN 5521 and Orange 5522 reconcile SEPARATELY - they are
 * different accounts, so they get different sessions, which is the whole
 * operational point of §1.3 having given them their own chart rows.
 *
 * `session_no` comes from the row-locked SequenceAllocator (00-core §12),
 * never max()+1.
 */
final class OpenReconciliationSession
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly SequenceAllocator $sequences,
        private readonly BuildReconciliationStatement $etat,
    ) {}

    public function handle(
        int $treasuryAccountId,
        int $accountingPeriodId,
        Actor $actor,
        ?int $bankStatementId = null,
    ): ReconciliationSession {
        Gate::authorize(Permission::LedgerPost->value);

        $account = ChartOfAccount::query()->findOrFail($treasuryAccountId);

        if (! $account->is_postable || $account->account_class !== 5 || ! $account->is_reconcilable) {
            throw new DomainException(sprintf(
                'Account %s cannot be reconciled: §13 reconciles postable class-5 accounts flagged is_reconcilable.',
                $account->code,
            ));
        }

        /** @var AccountingPeriod $period */
        $period = AccountingPeriod::query()->findOrFail($accountingPeriodId);

        if ($bankStatementId !== null) {
            $this->assertStatementFits($bankStatementId, $treasuryAccountId, $period);
        }

        $session = DB::transaction(function () use (
            $treasuryAccountId, $accountingPeriodId, $bankStatementId, $actor, $period,
        ): ReconciliationSession {
            $existing = ReconciliationSession::query()
                ->where('treasury_account_id', $treasuryAccountId)
                ->where('accounting_period_id', $accountingPeriodId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Attaching the relevé to a session opened before it arrived
                // is the normal sequence; changing it under a COMPLETED
                // session would rewrite signed evidence.
                if ($bankStatementId !== null && (int) ($existing->bank_statement_id ?? 0) !== $bankStatementId) {
                    if (! $existing->isDraft()) {
                        throw new DomainException(
                            'This period is already reconciled and signed off; its statement cannot be swapped.'
                        );
                    }

                    $existing->forceFill(['bank_statement_id' => $bankStatementId])->save();
                }

                return $existing;
            }

            $year = Carbon::parse($period->ends_on)->year;
            $n = $this->sequences->allocate('reconciliation_session.'.$year);

            $session = ReconciliationSession::query()->create([
                'session_no' => sprintf('RAP/%d/%06d', $year, $n),
                'treasury_account_id' => $treasuryAccountId,
                'accounting_period_id' => $accountingPeriodId,
                'bank_statement_id' => $bankStatementId,
                'status' => ReconciliationSessionStatus::Draft->value,
                'opened_by' => $actor->id,
                'opened_at' => now(),
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: ReconciliationSession::class,
                auditableId: (int) $session->getKey(),
                after: [
                    'session_no' => $session->session_no,
                    'treasury_account_id' => $treasuryAccountId,
                    'accounting_period_id' => $accountingPeriodId,
                    'bank_statement_id' => $bankStatementId,
                ],
                actor: $actor,
            );

            return $session;
        });

        // The état is computed from live data on every open, so a session
        // picked up a week later shows today's truth rather than the figures
        // that happened to hold when it was created.
        if ($session->isDraft()) {
            $this->etat->handle($session, persist: true);
        }

        return $session->refresh();
    }

    private function assertStatementFits(int $bankStatementId, int $treasuryAccountId, AccountingPeriod $period): void
    {
        /** @var BankStatement $statement */
        $statement = BankStatement::query()->findOrFail($bankStatementId);

        if ((int) $statement->treasury_account_id !== $treasuryAccountId) {
            throw new DomainException('That statement belongs to a different treasury account.');
        }

        // The relevé must close ON or BEFORE the period end: reconciling
        // August against a statement that runs into September would put
        // September's movements into August's état.
        if ($statement->period_end->greaterThan(Carbon::parse($period->ends_on)->endOfDay())) {
            throw new DomainException(sprintf(
                'The statement runs to %s, past the end of the period being reconciled (%s).',
                $statement->period_end->toDateString(),
                Carbon::parse($period->ends_on)->toDateString(),
            ));
        }
    }
}
