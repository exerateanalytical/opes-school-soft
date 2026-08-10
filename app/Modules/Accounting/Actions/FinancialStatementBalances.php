<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Per-account movement enriched with the classification columns the OHADA
 * financial statements are grouped by (docs/specs/02-accounting.md §14.2,
 * §17.7, §21.1): `chart_of_accounts.dsf_statement`, `.dsf_line_code`,
 * `.account_class`, `.type`.
 *
 * This is deliberately a THIN sibling of `Actions\TrialBalance` (same
 * `JournalEntry::postedLedger()` read path, same Σdebit/Σcredit per account,
 * same `ledger.view` gate) and of `Tax\Actions\GenerateDsf::mappedBalancesFor()`
 * - it is NOT a second accounting engine. The only thing it adds over the
 * trial balance is the classification columns, because a balance sheet is a
 * trial balance folded onto the statement structure carried by the chart.
 *
 * Date-bounded rather than period-id-bounded on purpose: a balance sheet is
 * cumulative to a date (from the start of the year) while an income
 * statement covers a date RANGE, and the comparative column needs the same
 * range shifted backwards - possibly across the fiscal-year boundary, which
 * is why `$fiscalYearId` is nullable.
 *
 * Read-only: no audit entry.
 */
final readonly class FinancialStatementBalances
{
    /**
     * @return Collection<int, object{
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     name_fr: string,
     *     account_class: int,
     *     type: string,
     *     dsf_line_code: string|null,
     *     dsf_statement: string|null,
     *     total_debit: int,
     *     total_credit: int,
     * }>
     */
    public function handle(
        ?int $fiscalYearId = null,
        ?string $fromDate = null,
        ?string $toDate = null,
    ): Collection {
        Gate::authorize(Permission::LedgerView->value);

        $postedEntryIds = JournalEntry::query()
            ->postedLedger()
            ->when($fiscalYearId !== null, function ($query) use ($fiscalYearId): void {
                $query->where('fiscal_year_id', $fiscalYearId);
            })
            ->when($fromDate !== null, function ($query) use ($fromDate): void {
                $query->whereDate('date', '>=', $fromDate);
            })
            ->when($toDate !== null, function ($query) use ($toDate): void {
                $query->whereDate('date', '<=', $toDate);
            })
            ->select('id');

        /** @var Collection<int, object{account_id:int, code:string, name:string, name_fr:string, account_class:int, type:string, dsf_line_code:string|null, dsf_statement:string|null, total_debit:int, total_credit:int}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($postedEntryIds, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.name_fr', 'a.account_class', 'a.type', 'a.dsf_line_code', 'a.dsf_statement')
            ->orderBy('a.code')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.name_fr',
                DB::raw('CAST(COALESCE(a.account_class, 0) AS SIGNED) as account_class'),
                'a.type',
                'a.dsf_line_code',
                'a.dsf_statement',
                DB::raw('CAST(COALESCE(SUM(l.debit), 0) AS SIGNED) as total_debit'),
                DB::raw('CAST(COALESCE(SUM(l.credit), 0) AS SIGNED) as total_credit'),
            ])
            ->get();

        return $rows;
    }
}
