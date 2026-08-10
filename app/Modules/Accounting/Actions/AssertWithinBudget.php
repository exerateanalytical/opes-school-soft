<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\BudgetControl;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Support\Money\Money;
use DomainException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;

/**
 * docs/specs/02-accounting.md §16, "Over-budget control" - the Action that
 * makes `chart_of_accounts.budget_control` mean something.
 *
 * That column has been on the chart, seeded, since the chart was built, and
 * until now NOTHING read it. §16 defines it precisely:
 *
 *   - `none`  - no check.
 *   - `warn`  - "the posting Action computes YTD actual + this entry against
 *               YTD phased budget and returns a warning in the response; the
 *               UI shows it and the operator may proceed".
 *   - `block` - "posting is refused unless the actor holds
 *               `accounting.override_budget`".
 *
 * This is a CHECK, not a posting. It writes nothing, touches no ledger row,
 * and takes no lock: it is safe to call from inside the posting transaction
 * (which is where it belongs) or standalone from a screen that wants to warn
 * an operator before they commit.
 *
 * ── WIRING (read this) ────────────────────────────────────────────────────
 *
 * `PostJournalEntry` and `PostFromEvent` are deliberately NOT modified by the
 * change that introduced this Action - a second posting path, or an edit to
 * the one posting path made without the owner of that file, is a
 * review-blocking defect in this codebase. So the check currently has ONE
 * integration point and it is one line, in `PostJournalEntry::handle()`,
 * immediately after the L2 debit = credit assertion and before the period
 * lock:
 *
 *     app(AssertWithinBudget::class)->handle($lines->all(), $entry->date, $entry->fiscal_year_id);
 *
 * At that point the lines are already frozen under `FOR UPDATE`, the entry
 * balances, and nothing has been stamped - a refusal there rolls back an
 * entry that never became real. Placing it any earlier would check an
 * unbalanced set; any later, and a `block` refusal would have already burned
 * a `piece_no` from the gapless sequence (L7), which is exactly the kind of
 * hole an auditor asks about.
 *
 * Warnings are RETURNED, never thrown, per §16: the caller decides whether to
 * show them. `PostJournalEntry` returning the JournalEntry rather than a
 * result object is why the warning half needs the entry-level
 * `budget_warning_shown` column §16 mentions and this codebase does not yet
 * have - that column, and surfacing warnings through the posting Action's
 * return, are the remaining half of the wiring and are flagged, not faked.
 *
 * ── SCOPE OF ENFORCEMENT ──────────────────────────────────────────────────
 *
 * The comparison is against the SINGLE approved, current budget for the
 * entry's fiscal year (§16 B-3). If the year has no approved budget, nothing
 * is enforced at all - a draft budget must never refuse a posting. If a
 * budget IS approved but the account carries no line in it, the budget for
 * that account is zero and enforcement applies: unbudgeted spend on an
 * account somebody deliberately marked `block` is the case the setting
 * exists for.
 */
final class AssertWithinBudget
{
    /**
     * The override §16 names. It is NOT in `Identity\Domain\Permission` yet;
     * adding a case there was out of scope for the change that introduced
     * this Action (that enum is under concurrent edit), so the constant is
     * declared here and the override path is opt-in from the caller until the
     * permission exists.
     */
    public const OVERRIDE_PERMISSION = 'accounting.override_budget';

    public function __construct(private readonly BudgetVsActual $budgetVsActual)
    {
    }

    /**
     * @param  iterable<array-key, object{account_id: int|string, debit: int|string, credit: int|string}|array{account_id: int|string, debit: int|string, credit: int|string}>  $lines
     *                                                                                                                                                                                the proposed entry's lines
     * @param  string|null  $overrideReason  mandatory when $override is true (§16)
     * @return list<string> warnings, one per `warn` account that would go over
     *
     * @throws DomainException when a `block` account would go over budget
     */
    public function handle(
        iterable $lines,
        Carbon|string $date,
        int $fiscalYearId,
        bool $override = false,
        ?string $overrideReason = null,
    ): array {
        $budget = BudgetVsActual::currentBudgetFor($fiscalYearId);

        if ($budget === null) {
            return [];
        }

        $month = Carbon::parse($date instanceof Carbon ? $date->toDateString() : $date)
            ->startOfMonth()
            ->toDateString();

        $proposed = $this->proposedByAccount($lines);

        if ($proposed === []) {
            return [];
        }

        /** @var list<ChartOfAccount> $accounts */
        $accounts = ChartOfAccount::query()
            ->whereIn('id', array_keys($proposed))
            ->whereIn('budget_control', [BudgetControl::Warn->value, BudgetControl::Block->value])
            ->get()
            ->all();

        /** @var list<string> $warnings */
        $warnings = [];

        foreach ($accounts as $account) {
            $accountId = (int) $account->getKey();

            $delta = BudgetVsActual::signedActual(
                $account->type->value,
                $proposed[$accountId]['debit'],
                $proposed[$accountId]['credit'],
            );

            // A line that reduces the account's consumption cannot push it
            // over its budget, so it is never worth a warning.
            if ($delta <= 0) {
                continue;
            }

            $ytd = $this->budgetVsActual->ytdFor(
                $fiscalYearId,
                $accountId,
                $month,
                (int) $budget->getKey(),
            );

            $projected = $ytd['actual'] + $delta;

            if ($projected <= $ytd['budget']) {
                continue;
            }

            $overspend = $projected - $ytd['budget'];
            $message = $this->message($account, $budget, $month, $ytd, $delta, $overspend);

            if ($account->budget_control === BudgetControl::Warn) {
                $warnings[] = $message;

                continue;
            }

            if ($override) {
                if ($overrideReason === null || mb_strlen(trim($overrideReason)) < 20) {
                    throw new DomainException(
                        'An over-budget override needs a written reason of at least 20 characters (§16); it is audited.'
                    );
                }

                $warnings[] = 'OVERRIDDEN — '.$message.' Reason: '.trim($overrideReason);

                continue;
            }

            throw new DomainException($message);
        }

        return $warnings;
    }

    /**
     * @param  array{budget: int, actual: int}  $ytd
     */
    private function message(
        ChartOfAccount $account,
        Budget $budget,
        string $month,
        array $ytd,
        int $delta,
        int $overspend,
    ): string {
        return sprintf(
            'Account %s (%s) is %s-controlled and this entry takes it over budget %s v%d: '
            .'YTD budget to %s is %s, YTD actual is %s, this entry adds %s, overspend %s.',
            $account->code,
            $account->name,
            $account->budget_control->value,
            $budget->code,
            $budget->version,
            $month,
            Money::of($ytd['budget'])->format(),
            Money::of($ytd['actual'])->format(),
            Money::of($delta)->format(),
            Money::of($overspend)->format(),
        );
    }

    /**
     * @param  iterable<array-key, object{account_id: int|string, debit: int|string, credit: int|string}|array{account_id: int|string, debit: int|string, credit: int|string}>  $lines
     * @return array<int, array{debit: int, credit: int}>
     */
    private function proposedByAccount(iterable $lines): array
    {
        /** @var array<int, array{debit: int, credit: int}> $byAccount */
        $byAccount = [];

        foreach ($lines as $line) {
            // A plain `(array)` cast on an Eloquent model does NOT yield its
            // attributes - it yields the object's properties, so the real
            // values hide behind mangled "\0*\0attributes" keys and every
            // account_id reads as 0. That made this whole check a silent
            // no-op for the caller that matters most (PostJournalEntry
            // hands it JournalEntryLine models), so models are unwrapped
            // through toArray() before anything else.
            if ($line instanceof Arrayable) {
                $values = $line->toArray();
            } elseif (is_array($line)) {
                $values = $line;
            } else {
                $values = (array) $line;
            }

            $accountId = (int) ($values['account_id'] ?? 0);

            if ($accountId === 0) {
                continue;
            }

            $byAccount[$accountId] ??= ['debit' => 0, 'credit' => 0];
            $byAccount[$accountId]['debit'] += (int) ($values['debit'] ?? 0);
            $byAccount[$accountId]['credit'] += (int) ($values['credit'] ?? 0);
        }

        return $byAccount;
    }
}
