<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * docs/specs/02-accounting.md §8.4 - L9: "Σ auxiliary balances per
 * collective account = the collective account's GL balance." Per the
 * invariant table (§4.3): "L8 makes violation structurally impossible; L9
 * PROVES it." The two SQL blocks below are the spec's §8.4 queries
 * verbatim, both filtered through `status IN ('posted','reversed')` per
 * L13/§9.3 - filtering to `status = 'posted'` alone would drop the
 * original half of every reversed pair and leave the reversal, flipping
 * every sign and making a perfectly healthy account look broken.
 *
 * NOT this agent's scope to build: the `AuxiliaryReconciliation` persistence
 * table (§8.4) and the nightly scheduled job that calls this Action and
 * writes rows to it - that migration is not in this agent's file-ownership
 * list, and wiring a `routes/console.php` schedule entry touches a file
 * this agent does not own either. This class is deliberately the pure,
 * callable-on-demand COMPUTATION only; see this agent's final report for
 * the handoff.
 *
 * Read-only: no audit log entry, gated on `ledger.view` only.
 */
final readonly class ReconcileAuxiliaryBalances
{
    /**
     * @return Collection<int, stdClass&object{
     *     account_id: int,
     *     code: string,
     *     gl_balance: int,
     *     auxiliary_sum: int,
     *     difference: int,
     *     status: 'balanced'|'out_of_balance',
     * }>
     */
    public function handle(?string $asOf = null, ?int $accountId = null): Collection
    {
        Gate::authorize(Permission::LedgerView->value);

        $asOf = $asOf ?? BusinessDate::today();
        $statuses = [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED];

        $glBalances = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.is_collective', true)
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->whereIn('e.status', $statuses)
            ->where('e.date', '<=', $asOf)
            ->groupBy('l.account_id', 'a.code')
            ->selectRaw('l.account_id as account_id, a.code as code, SUM(l.debit) - SUM(l.credit) as gl_balance')
            ->get()
            ->keyBy('account_id');

        $auxiliarySums = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.is_collective', true)
            ->when($accountId !== null, fn ($query) => $query->where('l.account_id', $accountId))
            ->whereIn('e.status', $statuses)
            ->where('e.date', '<=', $asOf)
            ->groupBy('l.account_id', 'l.partner_type', 'l.partner_id')
            ->selectRaw('l.account_id as account_id, SUM(l.debit) - SUM(l.credit) as bal')
            ->get()
            ->groupBy('account_id')
            ->map(fn (Collection $rows): int => (int) $rows->sum('bal'));

        return $glBalances->map(function (object $row) use ($auxiliarySums): object {
            $glBalance = (int) $row->gl_balance;
            $auxiliarySum = (int) ($auxiliarySums[$row->account_id] ?? 0);
            $difference = $glBalance - $auxiliarySum;

            return (object) [
                'account_id' => (int) $row->account_id,
                'code' => (string) $row->code,
                'gl_balance' => $glBalance,
                'auxiliary_sum' => $auxiliarySum,
                'difference' => $difference,
                'status' => $difference === 0 ? 'balanced' : 'out_of_balance',
            ];
        })->values();
    }
}
