<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\Journal;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Opening-balance migration, part 1 of 2: the trial balance.
 * docs/specs/02-accounting.md §18.4.
 *
 * A school adopts mid-exercice with a live trial balance. This Action turns
 * that trial balance into ONE posted entry in the `AN` journal, dated at the
 * cut-over date, with `is_migration = true` (§4.1: suppresses posting-rule
 * evaluation and downstream domain events, and marks the entry on every
 * report so an auditor can tell opening position from in-system activity).
 *
 * Why this Action may write the AN journal at all: §3 states "`AN` and `CL`
 * are `is_system` and only writable by the year-end Actions (§18) and the
 * opening-balance import (§18.4)". This class IS the opening-balance import
 * - it is one of the two sanctioned writers, so it addresses `AN` directly.
 *
 * Posting path: the existing DraftJournalEntry / PostJournalEntry Actions do
 * NOT hard-block system journals (only ConfigureJournal guards `is_system`),
 * so this Action routes through them rather than duplicating their write
 * order. The ONE thing their API lacks is an `is_migration` flag, so it is
 * stamped on the entry between draft and post - while the entry is still a
 * draft (the L3 line-lock trigger only freezes posted entries), inside the
 * same outer transaction, so no observer ever sees a migration entry without
 * the flag. Extending DraftJournalEntry's signature silently was explicitly
 * off the table; this is the minimal, commented seam instead.
 *
 * Collective accounts (4111 students, 401 suppliers, ...) are REFUSED here:
 * L8's trigger makes a lump partner-less line on a collective account
 * structurally impossible, and carrying a lump would destroy the auxiliary
 * ledger on day one (§18.2). Their totals belong on a non-collective
 * migration suspense account in this file, and their per-partner detail goes
 * through ImportOpeningAuxiliaryBalances, which clears the suspense.
 *
 * §20 (segregation): the matrix demands maker/checker on the opening-balance
 * import commit (Accountant makes, Administrator checks). The approval
 * workflow proper (approved_by, a pending state) is a later phase; the
 * simplest honest version implemented here is that `created_by`/`posted_by`
 * record the actor, and the deferral is stated rather than faked.
 */
final class ImportOpeningTrialBalance
{
    public function __construct(
        private readonly WriteAuditEntry $audit,
        private readonly DraftJournalEntry $draft,
        private readonly PostJournalEntry $post,
    ) {
    }

    /**
     * Validates without writing. Returns every problem at once - an operator
     * fixing a 60-row trial balance should not discover errors one by one.
     *
     * @param  array<int, array{account_code: string, debit: int, credit: int}>  $rows
     * @return array{errors: list<string>, total_debit: int, total_credit: int}
     */
    public function validate(array $rows): array
    {
        $errors = [];
        $totalDebit = Money::of(0);
        $totalCredit = Money::of(0);

        if ($rows === []) {
            $errors[] = 'The import contains no rows.';
        }

        $codes = array_values(array_unique(array_map(
            static fn (array $row): string => $row['account_code'],
            $rows,
        )));

        $accounts = ChartOfAccount::query()->whereIn('code', $codes)->get()->keyBy('code');

        foreach (array_values($rows) as $index => $row) {
            $rowNo = $index + 1;
            $code = $row['account_code'];
            $debit = Money::of($row['debit']);
            $credit = Money::of($row['credit']);

            /** @var ChartOfAccount|null $account */
            $account = $accounts->get($code);

            if ($account === null) {
                $errors[] = "Row {$rowNo}: account code {$code} does not exist in the chart of accounts.";

                continue;
            }

            if ($account->is_archived) {
                $errors[] = "Row {$rowNo}: account {$code} ({$account->name_fr}) is archived and cannot receive postings.";

                continue;
            }

            if (! $account->is_postable) {
                $errors[] = "Row {$rowNo}: account {$code} ({$account->name_fr}) is not postable - post to one of its postable sub-accounts instead.";

                continue;
            }

            if ($account->is_collective) {
                $errors[] = "Row {$rowNo}: account {$code} ({$account->name_fr}) is a collective account - every line on it must "
                    ."carry a partner (L8), so an opening trial balance cannot carry it as a lump. Put this row's amount on a "
                    ."postable, non-collective migration suspense account in this file, then load the per-partner detail with "
                    ."the auxiliary import (opes:ledger:import-opening --auxiliary), which moves the suspense onto {$code} "
                    .'partner by partner.';

                continue;
            }

            if ($debit->isNegative() || $credit->isNegative()) {
                $errors[] = "Row {$rowNo}: account {$code} carries a negative amount - state the balance on the other column instead.";

                continue;
            }

            if ($debit->isZero() === $credit->isZero()) {
                $errors[] = "Row {$rowNo}: account {$code} must have exactly one of debit/credit non-zero (L1).";

                continue;
            }

            $totalDebit = $totalDebit->plus($debit);
            $totalCredit = $totalCredit->plus($credit);
        }

        // Sigma debit = Sigma credit across the WHOLE import, asserted before
        // anything is written. An unbalanced opening trial balance is a hard
        // refusal that names the difference.
        if ($errors === [] && ! $totalDebit->equals($totalCredit)) {
            $errors[] = sprintf(
                'The trial balance does not balance: total debit %s <> total credit %s, difference %s. Nothing was posted.',
                $totalDebit->format(),
                $totalCredit->format(),
                $totalDebit->minus($totalCredit)->absolute()->format(),
            );
        }

        return [
            'errors' => $errors,
            'total_debit' => $totalDebit->amount(),
            'total_credit' => $totalCredit->amount(),
        ];
    }

    /**
     * @param  array<int, array{account_code: string, debit: int, credit: int}>  $rows
     */
    public function handle(int $fiscalYearId, array $rows, string $asOfDate, Actor $actor): JournalEntry
    {
        Gate::authorize(Permission::LedgerPost->value);

        $validation = $this->validate($rows);

        if ($validation['errors'] !== []) {
            throw new DomainException(implode("\n", $validation['errors']));
        }

        /** @var Journal $journal */
        $journal = Journal::query()->where('code', 'AN')->firstOrFail();

        $accounts = ChartOfAccount::query()
            ->whereIn('code', array_column($rows, 'account_code'))
            ->get()
            ->keyBy('code');

        $lines = [];

        foreach (array_values($rows) as $row) {
            /** @var ChartOfAccount $account */
            $account = $accounts->get($row['account_code']);

            $lines[] = [
                'account_id' => (int) $account->getKey(),
                'label' => "Opening balance {$account->code} - {$account->name_fr}",
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ];
        }

        return DB::transaction(function () use ($fiscalYearId, $journal, $lines, $asOfDate, $actor, $validation): JournalEntry {
            $entry = $this->draft->handle(
                journalId: (int) $journal->getKey(),
                date: $asOfDate,
                valueDate: null,
                label: "Opening trial balance as of {$asOfDate} (migration)",
                reference: null,
                lines: $lines,
                actor: $actor,
            );

            // The caller states which fiscal year it believes it is migrating
            // into; the ledger derives it from the date (L6). A mismatch means
            // the cut-over date and the fiscal year disagree - refuse rather
            // than post an opening balance into the wrong exercice.
            if ($entry->fiscal_year_id !== $fiscalYearId) {
                throw new DomainException(
                    "The as-of date {$asOfDate} falls in fiscal year {$entry->fiscal_year_id}, not the requested fiscal year {$fiscalYearId}."
                );
            }

            // §4.1: is_migration suppresses posting-rule evaluation and
            // downstream events. Stamped while still a draft, in the same
            // transaction, because DraftJournalEntry deliberately has no such
            // parameter (see class docblock).
            $entry->forceFill(['is_migration' => true])->save();

            $posted = $this->post->handle((int) $entry->getKey(), $actor);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: JournalEntry::class,
                auditableId: (int) $posted->getKey(),
                after: [
                    'kind' => 'opening_trial_balance_import',
                    'as_of' => $asOfDate,
                    'fiscal_year_id' => $fiscalYearId,
                    'line_count' => count($lines),
                    'total_debit' => $validation['total_debit'],
                    'total_credit' => $validation['total_credit'],
                ],
                actor: $actor,
            );

            return $posted;
        });
    }
}
