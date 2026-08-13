<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * Non-zero suspense balances,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.4.
 *
 * A suspense account should read zero outside a migration window. A balance
 * sitting on one is a standing exception that needs an owner and a date -
 * it is money the books cannot yet explain.
 *
 * WHY CLASS 47. In SYSCOHADA, class 47 is "comptes transitoires ou d'attente".
 * That is the framework's own classification, not a heuristic invented here,
 * which is why matching on it does not fall foul of §1.1 - no account is
 * created, and none is assumed to exist. If the chart later grows an explicit
 * suspense flag, prefer it and delete the prefix.
 *
 * Read-only.
 */
final readonly class SuspenseBalances
{
    public const PERMISSION = Permission::LedgerView->value;

    /** SYSCOHADA: comptes transitoires ou d'attente. */
    private const SUSPENSE_CLASS = '47';

    /**
     * @return Collection<int, stdClass&object{code: string, name: string, balance: int}>
     */
    public function handle(?string $asOf = null): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        $accounts = ChartOfAccount::query()
            ->where('is_archived', false)
            ->where('code', 'like', self::SUSPENSE_CLASS.'%')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        if ($accounts->isEmpty()) {
            return collect();
        }

        // One grouped query for every suspense account, not one per account.
        $balances = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->whereIn('l.account_id', $accounts->pluck('id')->all())
            ->whereIn('e.status', JournalEntry::postedLedgerStatuses())
            ->where('e.date', '<=', $asOf)
            ->groupBy('l.account_id')
            ->selectRaw('l.account_id as account_id, SUM(l.debit) - SUM(l.credit) as balance')
            ->pluck('balance', 'account_id');

        return $accounts
            ->map(fn (ChartOfAccount $a): stdClass => (object) [
                'code' => (string) $a->code,
                'name' => (string) $a->name,
                'balance' => (int) ($balances[$a->id] ?? 0),
            ])
            // A zero suspense account is the healthy case and is not news.
            ->filter(fn (stdClass $row): bool => $row->balance !== 0)
            ->values();
    }
}
