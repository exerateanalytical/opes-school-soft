<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Domain\BudgetStatus;
use App\Modules\Accounting\Models\Budget;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §16 - budget vs actual, per account per period,
 * with variance and variance %.
 *
 * Actuals come from `JournalEntry::postedLedger()` (§4.3 L13) and from
 * nowhere else. That scope, not `where('status','posted')`, is the whole
 * point: a reversal and the entry it reverses are two halves of one
 * correction and must net to zero here exactly as they do in the trial
 * balance. Reading only `posted` would drop the original half and flip the
 * sign of the corrected transaction.
 *
 * SIGN CONVENTION, which is the one thing to understand before reading a
 * figure off this Action:
 *
 *   - Charges (class 6) and capex (class 2) are debit-normal: actual is
 *     Σdebit − Σcredit. A budget of 3 000 000 means "we intend to spend
 *     3 000 000", so both numbers are positive and comparable.
 *   - Produits (class 7) are credit-normal: actual is Σcredit − Σdebit. A
 *     budget of 40 000 000 means "we intend to earn 40 000 000".
 *
 * So `variance = actual − budget` is a single arithmetic rule across both,
 * but it means opposite things: over-spending a charge and over-earning a
 * revenue are both a positive variance. `favourable` carries that reading so
 * the screen never has to re-derive it, and so a green/red cell cannot
 * disagree with the number beside it.
 *
 * `variance_pct` is NULL when the budget is zero. An "∞%" or a 0% would both
 * be inventions - the same rule the Statements screen's comparative uses.
 *
 * The budget side is the PHASED figure (`budget_phasings.amount`), joined to
 * the actual side on `accounting_periods.period_month`. This is what makes
 * "YTD budget" mean the sum of the periods elapsed rather than a twelfth of
 * the annual figure times the month number, which is exactly the distinction
 * §16's seasonal-phasing paragraph exists to make.
 *
 * Read-only: gated on `ledger.view`, no audit entry, no write of any kind.
 *
 * @phpstan-type BudgetActualRow array{
 *     account_id: int,
 *     code: string,
 *     name: string,
 *     account_class: int,
 *     type: string,
 *     budget_control: string,
 *     analytic_value_id: int|null,
 *     analytic_code: string|null,
 *     period_month: string,
 *     budget: int,
 *     actual: int,
 *     variance: int,
 *     variance_pct: float|null,
 *     favourable: bool,
 * }
 */
final class BudgetVsActual
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * The budget that governs a fiscal year: the single approved, current one
     * (§16 B-3). Returns null when the year has no approved budget, which is
     * a legitimate state and NOT an error - it simply means nothing to
     * compare against and nothing to enforce.
     */
    public static function currentBudgetFor(int $fiscalYearId): ?Budget
    {
        /** @var Budget|null $budget */
        $budget = Budget::query()
            ->where('fiscal_year_id', $fiscalYearId)
            ->where('is_current', true)
            ->where('status', BudgetStatus::Approved->value)
            ->first();

        return $budget;
    }

    /**
     * One row per (budget line, period). Periods with neither budget nor
     * actual are omitted; a period that is budgeted but unspent, or spent but
     * unbudgeted, IS returned - both are findings.
     *
     * @param  string|null  $fromMonth  'YYYY-MM-01' inclusive
     * @param  string|null  $toMonth  'YYYY-MM-01' inclusive
     * @return Collection<int, BudgetActualRow>
     */
    public function handle(
        int $fiscalYearId,
        ?int $budgetId = null,
        ?string $fromMonth = null,
        ?string $toMonth = null,
        ?int $accountId = null,
    ): Collection {
        Gate::authorize(self::PERMISSION);

        $budget = $budgetId === null
            ? self::currentBudgetFor($fiscalYearId)
            : Budget::query()->whereKey($budgetId)->first();

        $from = $fromMonth === null ? null : Carbon::parse($fromMonth)->startOfMonth()->toDateString();
        $to = $toMonth === null ? null : Carbon::parse($toMonth)->startOfMonth()->toDateString();

        $budgeted = $budget === null
            ? collect()
            : $this->budgetedRows((int) $budget->getKey(), $from, $to, $accountId);

        $actuals = $this->actualRows($fiscalYearId, $from, $to, $accountId);

        /** @var array<string, BudgetActualRow> $merged */
        $merged = [];

        foreach ($budgeted as $row) {
            $key = $row->account_id.'|'.($row->analytic_value_id ?? 0).'|'.$row->period_month;

            $merged[$key] = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'account_class' => (int) $row->account_class,
                'type' => (string) $row->type,
                'budget_control' => (string) $row->budget_control,
                'analytic_value_id' => $row->analytic_value_id === null ? null : (int) $row->analytic_value_id,
                'analytic_code' => $row->analytic_code === null ? null : (string) $row->analytic_code,
                'period_month' => (string) $row->period_month,
                'budget' => (int) $row->budget,
                'actual' => 0,
                'variance' => 0,
                'variance_pct' => null,
                'favourable' => true,
            ];
        }

        foreach ($actuals as $row) {
            // Actuals are not analytic-split here: a budget line narrowed to
            // an analytic value is compared against the account's movement,
            // and the screen labels that. Splitting the ledger by analytic
            // allocation is §12's report, not this one, and silently mixing
            // the two would produce a variance nobody can reconcile.
            $key = $row->account_id.'|0|'.$row->period_month;

            $actual = self::signedActual(
                (string) $row->type,
                (int) $row->total_debit,
                (int) $row->total_credit,
            );

            if (isset($merged[$key])) {
                $merged[$key]['actual'] = $actual;

                continue;
            }

            $merged[$key] = [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'account_class' => (int) $row->account_class,
                'type' => (string) $row->type,
                'budget_control' => (string) $row->budget_control,
                'analytic_value_id' => null,
                'analytic_code' => null,
                'period_month' => (string) $row->period_month,
                'budget' => 0,
                'actual' => $actual,
                'variance' => 0,
                'variance_pct' => null,
                'favourable' => true,
            ];
        }

        foreach ($merged as $key => $row) {
            $variance = $row['actual'] - $row['budget'];
            $isRevenue = $row['type'] === 'revenue';

            $merged[$key]['variance'] = $variance;
            $merged[$key]['variance_pct'] = $row['budget'] === 0
                ? null
                : round(($variance / abs($row['budget'])) * 100, 1);
            // Over-earning a produit is good news; over-spending a charge is
            // not. Same number, opposite reading.
            $merged[$key]['favourable'] = $isRevenue ? $variance >= 0 : $variance <= 0;
        }

        /** @var Collection<int, BudgetActualRow> $rows */
        $rows = collect(array_values($merged))
            ->sortBy(fn (array $row): string => $row['code'].'|'.$row['period_month'])
            ->values();

        return $rows;
    }

    /**
     * The YTD phased budget and YTD actual for ONE account, up to and
     * including a month - the exact pair §16's over-budget control compares.
     * `AssertWithinBudget` is its only caller.
     *
     * @return array{budget: int, actual: int}
     */
    public function ytdFor(int $fiscalYearId, int $accountId, string $upToMonth, ?int $budgetId = null): array
    {
        $rows = $this->handle($fiscalYearId, $budgetId, null, $upToMonth, $accountId);

        return [
            'budget' => (int) $rows->sum(static fn (array $row): int => $row['budget']),
            'actual' => (int) $rows->sum(static fn (array $row): int => $row['actual']),
        ];
    }

    /**
     * The sign convention of the class docblock, in one place so the query,
     * the screen and `AssertWithinBudget` cannot drift apart on it.
     */
    public static function signedActual(string $type, int $debit, int $credit): int
    {
        return $type === 'revenue' ? $credit - $debit : $debit - $credit;
    }

    /**
     * @return Collection<int, object{account_id: int, code: string, name: string, account_class: int, type: string, budget_control: string, analytic_value_id: int|null, analytic_code: string|null, period_month: string, budget: int}>
     */
    private function budgetedRows(int $budgetId, ?string $from, ?string $to, ?int $accountId): Collection
    {
        /** @var Collection<int, object{account_id: int, code: string, name: string, account_class: int, type: string, budget_control: string, analytic_value_id: int|null, analytic_code: string|null, period_month: string, budget: int}> $rows */
        $rows = DB::table('budget_phasings as p')
            ->join('budget_lines as bl', 'bl.id', '=', 'p.budget_line_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'bl.account_id')
            ->leftJoin('analytic_values as av', 'av.id', '=', 'bl.analytic_value_id')
            ->where('bl.budget_id', $budgetId)
            ->when($from !== null, fn ($query) => $query->where('p.period_month', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('p.period_month', '<=', $to))
            ->when($accountId !== null, fn ($query) => $query->where('bl.account_id', $accountId))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_class', 'a.type', 'a.budget_control', 'bl.analytic_value_id', 'av.code', 'p.period_month')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                DB::raw('CAST(COALESCE(a.account_class, 0) AS SIGNED) as account_class'),
                'a.type',
                'a.budget_control',
                'bl.analytic_value_id',
                'av.code as analytic_code',
                DB::raw('DATE_FORMAT(p.period_month, \'%Y-%m-01\') as period_month'),
                DB::raw('CAST(COALESCE(SUM(p.amount), 0) AS SIGNED) as budget'),
            ])
            ->get();

        return $rows;
    }

    /**
     * @return Collection<int, object{account_id: int, code: string, name: string, account_class: int, type: string, budget_control: string, period_month: string, total_debit: int, total_credit: int}>
     */
    private function actualRows(int $fiscalYearId, ?string $from, ?string $to, ?int $accountId): Collection
    {
        $postedEntries = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->select(['id', 'accounting_period_id']);

        /** @var Collection<int, object{account_id: int, code: string, name: string, account_class: int, type: string, budget_control: string, period_month: string, total_debit: int, total_credit: int}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($postedEntries, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('accounting_periods as ap', 'ap.id', '=', 'e.accounting_period_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->whereIn('a.account_class', [2, 6, 7])
            ->when($from !== null, fn ($query) => $query->where('ap.period_month', '>=', $from))
            ->when($to !== null, fn ($query) => $query->where('ap.period_month', '<=', $to))
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->groupBy('a.id', 'a.code', 'a.name', 'a.account_class', 'a.type', 'a.budget_control', 'ap.period_month')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                DB::raw('CAST(COALESCE(a.account_class, 0) AS SIGNED) as account_class'),
                'a.type',
                'a.budget_control',
                DB::raw('DATE_FORMAT(ap.period_month, \'%Y-%m-01\') as period_month'),
                DB::raw('CAST(COALESCE(SUM(l.debit), 0) AS SIGNED) as total_debit'),
                DB::raw('CAST(COALESCE(SUM(l.credit), 0) AS SIGNED) as total_credit'),
            ])
            ->get();

        return $rows;
    }
}
