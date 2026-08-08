<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The balance générale, docs/specs/02-accounting.md §4.3 L13 / §9.3: per
 * account, sigma-debit and sigma-credit for every entry that is real
 * accounting reality - `posted` AND `reversed`, never `draft`. This is why
 * the join goes through `JournalEntry::query()->postedLedger()` (D3's scope,
 * `app/Modules/Accounting/Models/JournalEntry.php`) rather than a bare
 * `where('status', 'posted')`: filtering out `reversed` would keep the
 * reversal but drop the entry it cancels, flipping the sign of the pair and
 * still leaving the trial balance numerically "balanced" - nothing else
 * catches that mistake, which is the entire point of the single scope.
 *
 * Read-only: no audit log entry, gated on `ledger.view` only.
 */
final readonly class TrialBalance
{
    /**
     * @return Collection<int, object{
     *     account_id: int,
     *     code: string,
     *     name: string,
     *     name_fr: string,
     *     total_debit: int,
     *     total_credit: int,
     * }>
     */
    public function handle(int $fiscalYearId, ?string $asOfDate = null): Collection
    {
        Gate::authorize(Permission::LedgerView->value);

        $postedEntryIds = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->when($asOfDate !== null, function ($query) use ($asOfDate): void {
                $query->whereDate('date', '<=', $asOfDate);
            })
            ->select('id');

        /** @var Collection<int, object{account_id:int, code:string, name:string, name_fr:string, total_debit:int, total_credit:int}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($postedEntryIds, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->groupBy('a.id', 'a.code', 'a.name', 'a.name_fr')
            ->orderBy('a.code')
            ->select([
                'a.id as account_id',
                'a.code',
                'a.name',
                'a.name_fr',
                DB::raw('CAST(COALESCE(SUM(l.debit), 0) AS SIGNED) as total_debit'),
                DB::raw('CAST(COALESCE(SUM(l.credit), 0) AS SIGNED) as total_credit'),
            ])
            ->get();

        return $rows;
    }
}
