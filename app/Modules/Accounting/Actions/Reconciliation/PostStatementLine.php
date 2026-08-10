<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Reconciliation;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Models\BankStatementLine;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Models\ReconciliationSession;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §13.3 - "the session offers a one-click *post
 * this statement line* using the `bank.charge.recorded` /
 * `bank.interest.received` posting rules", plus §11.3's MoMo commission,
 * which is the same operation on a 552x float.
 *
 * This is the ONE place in the reconciliation feature that creates money,
 * and it does so the only way this codebase allows: through PostFromEvent,
 * with a posting rule (seeded in 390006) choosing the accounts and the
 * sides. Nothing here writes a journal line directly.
 *
 * The sequence matters. Post first, then match the resulting treasury line
 * to the statement line that caused it, in the SAME transaction: a charge
 * that is posted but left unmatched still shows in the état as a difference,
 * and an operator would post it twice trying to make the difference go away.
 *
 * The side is not a parameter. A statement DEBIT is money leaving the float
 * (a charge, a commission); a statement CREDIT is money arriving (interest).
 * Letting a caller pass a direction is how a commission gets booked as
 * income.
 */
final class PostStatementLine
{
    public function __construct(
        private readonly PostFromEvent $postFromEvent,
        private readonly MatchReconciliationLines $match,
    ) {}

    /**
     * @param  int  $counterAccountId  the 631x charge account, or the 77x
     *                                 income account for interest. The
     *                                 school's accountant owns that choice
     *                                 (§1.3); no code path assumes 6317.
     */
    public function handle(
        int $sessionId,
        int $statementLineId,
        PostingEvent $event,
        int $counterAccountId,
        Actor $actor,
    ): BankStatementLine {
        Gate::authorize(Permission::LedgerPost->value);

        if (! in_array($event, self::supportedEvents(), true)) {
            throw new DomainException(
                'Only a bank charge, bank interest or a mobile-money commission can be posted from a statement line.'
            );
        }

        /** @var ReconciliationSession $session */
        $session = ReconciliationSession::query()->findOrFail($sessionId);

        if (! $session->isDraft()) {
            throw new DomainException('This reconciliation is completed; nothing can be posted into it.');
        }

        /** @var BankStatementLine $line */
        $line = BankStatementLine::query()->findOrFail($statementLineId);

        if ((int) $line->bank_statement_id !== (int) $session->bank_statement_id) {
            throw new DomainException('That statement line belongs to a different relevé.');
        }

        if ($line->reconciliation_match_id !== null || $line->journal_entry_id !== null) {
            throw new DomainException('That statement line is already in the books.');
        }

        $isReceipt = $event === PostingEvent::BankInterestReceived;

        if ($isReceipt && $line->credit === 0) {
            throw new DomainException('Interest received is money arriving; this line is a debit.');
        }

        if (! $isReceipt && $line->debit === 0) {
            throw new DomainException('A charge or commission is money leaving; this line is a credit.');
        }

        $counterAccount = ChartOfAccount::query()->findOrFail($counterAccountId);

        if (! $counterAccount->is_postable) {
            throw new DomainException(sprintf('Account %s is not postable.', $counterAccount->code));
        }

        $amount = $isReceipt ? $line->credit : $line->debit;

        return DB::transaction(function () use (
            $session, $line, $event, $counterAccountId, $amount, $isReceipt, $actor,
        ): BankStatementLine {
            $payload = [
                'statement_item' => [
                    'treasury_account_id' => (int) $session->treasury_account_id,
                    'charge_account_id' => $isReceipt ? null : $counterAccountId,
                    'income_account_id' => $isReceipt ? $counterAccountId : null,
                    'amount' => $amount,
                    'label' => $line->label,
                    'reference' => $line->reference ?? '',
                ],
            ];

            $entry = $this->postFromEvent->handle(
                event: $event->value,
                payload: $payload,
                date: $line->operation_date->toDateString(),
                actor: $actor,
                reference: $line->reference,
                valueDate: $line->value_date?->toDateString(),
            );

            // The treasury leg of what we just posted - the line the relevé
            // is actually talking about.
            /** @var JournalEntryLine|null $treasuryLine */
            $treasuryLine = JournalEntryLine::query()
                ->where('journal_entry_id', $entry->getKey())
                ->where('account_id', $session->treasury_account_id)
                ->first();

            if ($treasuryLine === null) {
                throw new DomainException(
                    'The posting rule produced no line on this treasury account; check its configuration.'
                );
            }

            $line->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            $this->match->handle(
                sessionId: (int) $session->getKey(),
                statementLineIds: [(int) $line->getKey()],
                ledgerLineIds: [(int) $treasuryLine->getKey()],
                actor: $actor,
                isAuto: true,
                confidenceBp: 10000,
            );

            return $line->refresh();
        });
    }

    /** @return list<PostingEvent> */
    public static function supportedEvents(): array
    {
        return [
            PostingEvent::BankChargeRecorded,
            PostingEvent::BankInterestReceived,
            PostingEvent::MobileMoneyCommissionCharged,
        ];
    }
}
