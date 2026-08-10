<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\FiscalYearStatus;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Accounting\Domain\YearEndItemStatus;
use App\Modules\Accounting\Domain\YearEndStep;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\FiscalYear;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\YearEndChecklistItem;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §18.1, step 9 of §17.2 - THE closing entry.
 * Every class 6, 7 and 8 account with a balance is emptied against **13**
 * *Résultat en instance d'affectation*, in journal **CL**, dated
 * `fiscal_year.ends_on`. After it, classes 6, 7 and 8 are zero, which is
 * §18.2's precondition.
 *
 * The write goes through `PostFromEvent` on `year_end.closing` - the ONLY
 * door into the ledger (§11.1). This Action's job is to compute the payload
 * and to refuse, loudly and specifically, when it must not run:
 *
 *  - the exercice is already closed, or its DSF is filed;
 *  - a closing entry already exists (IDEMPOTENCY: it names the piece_no
 *    rather than posting a second one - a double close would double the
 *    result and is the single most expensive mistake available here);
 *  - step 7's trial-balance validation has neither passed nor been waived;
 *  - compte 13 is missing, unpostable or archived;
 *  - a class 6/7/8 balance sits on a partner-bearing account with no
 *    partner (L8 would reject the line anyway - better a sentence than a
 *    trigger error);
 *  - there is nothing to close.
 *
 * The compte-13 line is computed as the exact residual of the lines this
 * Action is emitting (Σ of the signed amounts, negated), so the entry
 * balances by construction rather than by a second, independent sum that
 * could disagree - 00-core §7.3's balancing-line rule, applied in the
 * Action because the residual's ACCOUNT is data the rule cannot know.
 */
final class PostYearEndClosingEntry
{
    public const PERMISSION = Permission::LedgerPost->value;

    /** SYSCOHADA 13 - Résultat en instance d'affectation. */
    public const RESULT_ACCOUNT_CODE = '13';

    public function __construct(
        private readonly PostFromEvent $poster,
        private readonly YearEndBalances $balances,
        private readonly EvaluateYearEndChecklist $checklist,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $fiscalYearId, Actor $actor): JournalEntry
    {
        Gate::authorize(self::PERMISSION);

        $state = $this->checklist->handle($fiscalYearId, $actor);

        $this->assertStepAllowed($state);

        /** @var FiscalYear $fiscalYear */
        $fiscalYear = FiscalYear::query()->findOrFail($fiscalYearId);

        $resultAccount = $this->resultAccount();

        [$lines, $partnerLines, $residual] = $this->buildLines($fiscalYearId, (int) $resultAccount->getKey());

        $entry = DB::transaction(function () use (
            $fiscalYearId, $actor, $resultAccount, $lines, $partnerLines, $residual
        ): JournalEntry {
            /** @var FiscalYear $locked */
            $locked = FiscalYear::query()->whereKey($fiscalYearId)->lockForUpdate()->firstOrFail();

            // Re-read under the lock: two operators pressing "Post closing
            // entry" at once must produce one entry and one refusal.
            if ($locked->closing_entry_id !== null) {
                $pieceNo = DB::table('journal_entries')->where('id', $locked->closing_entry_id)->value('piece_no');

                throw new DomainException(sprintf(
                    'Fiscal year %s already has a closing entry (%s). Closing twice would double the result; reverse the existing entry first if it is wrong.',
                    $locked->code,
                    is_string($pieceNo) ? $pieceNo : (string) $locked->closing_entry_id,
                ));
            }

            $payload = [
                'closing' => [
                    'amount' => $this->entryTotal($lines, $partnerLines),
                    'reference' => 'CLOTURE-'.$locked->code,
                    'result_account_id' => (int) $resultAccount->getKey(),
                    'counterpart_account_id' => (int) $resultAccount->getKey(),
                    'lines' => $lines,
                    'partner_lines' => $partnerLines,
                ],
            ];

            $entry = $this->poster->handle(
                event: PostingEvent::YearEndClosing->value,
                payload: $payload,
                date: $locked->ends_on->toDateString(),
                actor: $actor,
                reference: 'CLOTURE-'.$locked->code,
            );

            $locked->forceFill([
                'closing_entry_id' => $entry->getKey(),
                // §17.2: the year is now IN the close sequence. It reaches
                // `closed` only through CloseFiscalYear, once YE-1 holds.
                'status' => $locked->status === FiscalYearStatus::Open
                    ? FiscalYearStatus::Closing->value
                    : $locked->status->value,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Accounting',
                auditableType: FiscalYear::class,
                auditableId: (int) $locked->getKey(),
                after: [
                    'closing_entry_id' => (int) $entry->getKey(),
                    'result_residual' => $residual,
                    'line_count' => count($lines) + count($partnerLines),
                ],
                actor: $actor,
            );

            return $entry;
        });

        return $entry;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function assertStepAllowed(array $state): void
    {
        /** @var FiscalYear $fiscalYear */
        $fiscalYear = $state['fiscal_year'];

        // Checked HERE as well as under the lock below: without it, a second
        // close attempt falls through to buildLines() and reports "nothing
        // to close" - true, but a confusing way to say "already closed". The
        // lock-side check is what makes it safe; this one makes it legible.
        if ($fiscalYear->closing_entry_id !== null) {
            $pieceNo = DB::table('journal_entries')->where('id', $fiscalYear->closing_entry_id)->value('piece_no');

            throw new DomainException(sprintf(
                'Fiscal year %s already has a closing entry (%s). Closing twice would double the result; reverse the existing entry first if it is wrong.',
                $fiscalYear->code,
                is_string($pieceNo) ? $pieceNo : (string) $fiscalYear->closing_entry_id,
            ));
        }

        if ($fiscalYear->status === FiscalYearStatus::Closed) {
            throw new DomainException(sprintf('Fiscal year %s is closed.', $fiscalYear->code));
        }

        if ($fiscalYear->dsf_filed_at !== null) {
            throw new DomainException(sprintf(
                'The DSF for %s was filed on %s; nothing further may be posted into it.',
                $fiscalYear->code,
                $fiscalYear->dsf_filed_at->toDateString(),
            ));
        }

        /** @var list<YearEndChecklistItem> $items */
        $items = $state['items'];

        foreach ($items as $item) {
            if ($item->code !== YearEndStep::TrialBalance->value) {
                continue;
            }

            if ($item->status === YearEndItemStatus::Pending) {
                throw new DomainException(
                    'Step 7 (the §17.9 trial-balance validation) has not passed and has not been waived. '
                    .'The closing entry may not be posted over an unvalidated trial balance; fix the failing checks or waive step 7 with a reason.'
                );
            }
        }

        // Structural refusals that have nothing to do with the checklist:
        // no period covers 31 December, the period is locked, and so on.
        /** @var list<array{code: string, message: string}> $blockers */
        $blockers = $state['blockers'];

        foreach ($blockers as $blocker) {
            if (in_array($blocker['code'], ['no_closing_period', 'closing_period_locked'], true)) {
                throw new DomainException($blocker['message']);
            }
        }
    }

    private function resultAccount(): ChartOfAccount
    {
        /** @var ChartOfAccount|null $account */
        $account = ChartOfAccount::query()->where('code', self::RESULT_ACCOUNT_CODE)->first();

        if ($account === null) {
            throw new DomainException(
                'Compte 13 (Résultat en instance d\'affectation) is not in the chart of accounts; the §18.1 closing entry has no counterpart.'
            );
        }

        if (! $account->is_postable || $account->is_archived) {
            throw new DomainException(sprintf(
                'Compte 13 is %s; the closing entry cannot land on it.',
                $account->is_archived ? 'archived' : 'not postable',
            ));
        }

        return $account;
    }

    /**
     * @return array{
     *     list<array{amount: int, target_account_id: int, label: string}>,
     *     list<array{amount: int, target_account_id: int, label: string, partner: array{type: string, id: int}, due_date: string|null}>,
     *     int
     * }
     */
    private function buildLines(int $fiscalYearId, int $resultAccountId): array
    {
        $classes = [6, 7, 8];

        $orphans = $this->balances->orphanedCollectiveLines($fiscalYearId, $classes);

        if ($orphans !== []) {
            $codes = implode(', ', array_map(
                static fn (array $row): string => $row['code'],
                $orphans,
            ));

            throw new DomainException(
                "These partner-bearing class 6/7/8 accounts carry lines with no partner (L8): {$codes}. "
                .'Fix the offending lines before closing; the closing entry would inherit the same defect.'
            );
        }

        $lines = [];
        $partnerLines = [];
        $signedTotal = Money::zero();

        foreach ($this->balances->perAccount($fiscalYearId, $classes) as $row) {
            // The line that EMPTIES the account is its balance, negated.
            $amount = -$row['balance'];
            $signedTotal = $signedTotal->plus(Money::of($amount));

            $lines[] = [
                'amount' => $amount,
                'target_account_id' => $row['account_id'],
                'label' => 'Cloture '.$row['code'].' - '.$row['name'],
            ];
        }

        foreach ($this->balances->perPartner($fiscalYearId, $classes) as $row) {
            $amount = -$row['balance'];
            $signedTotal = $signedTotal->plus(Money::of($amount));

            $partnerLines[] = [
                'amount' => $amount,
                'target_account_id' => $row['account_id'],
                'label' => 'Cloture '.$row['code'].' - '.$row['name'],
                'partner' => ['type' => $row['partner_type'], 'id' => $row['partner_id']],
                'due_date' => $row['due_date'],
            ];
        }

        if ($lines === [] && $partnerLines === []) {
            throw new DomainException(
                'No class 6, 7 or 8 account carries a balance in this exercice; there is nothing to close.'
            );
        }

        // The residual: whatever the emptying lines left. Profit (class 7 >
        // class 6) makes this negative, i.e. a CREDIT to 13, which is what
        // §18.1's table shows.
        $residual = $signedTotal->negated()->amount();

        $lines[] = [
            'amount' => $residual,
            'target_account_id' => $resultAccountId,
            'label' => 'Resultat en instance d\'affectation',
        ];

        return [$lines, $partnerLines, $residual];
    }

    /**
     * The entry total (§11.2's `closing.amount`): the debit side, which for
     * a balanced entry is also the credit side.
     *
     * @param  list<array{amount: int, target_account_id: int, label: string}>  $lines
     * @param  list<array{amount: int, target_account_id: int, label: string, partner: array{type: string, id: int}, due_date: string|null}>  $partnerLines
     */
    private function entryTotal(array $lines, array $partnerLines): int
    {
        $total = Money::zero();

        foreach ([$lines, $partnerLines] as $set) {
            foreach ($set as $line) {
                if ($line['amount'] > 0) {
                    $total = $total->plus(Money::of($line['amount']));
                }
            }
        }

        return $total->amount();
    }
}
