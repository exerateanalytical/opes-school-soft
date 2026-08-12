<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Domain\ControlCheck;
use App\Modules\Accounting\Models\ChartOfAccount;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use App\Support\Clock\BusinessDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * AR <-> GL and AP <-> GL, docs/specs/2026-08-12-accounting-finance-architecture.md §4.1.
 *
 * The identity: for every collective account, the sum of its per-partner
 * balances equals the account's own balance. L8 (02-accounting.md §8.3)
 * guarantees a line on a collective account always carries a partner, so
 * both sides sum the same rows and any difference is a real integrity fault.
 *
 * Read path is scopePostedLedger()'s status list - both `posted` AND
 * `reversed`, so a reversal nets its original to zero (§9.3). Filtering on
 * `posted` alone would drop the original half of every reversed pair.
 *
 * This raw-query version reuses the identity already computed by
 * ReconcileAuxiliaryBalances (app/Modules/Accounting/Actions/
 * ReconcileAuxiliaryBalances.php, found per rule 5 before writing this
 * Action) but returns the shared ControlCheck value object this Review
 * subsystem's other checks use, rather than that Action's stdClass rows.
 *
 * Read-only. This Action decides nothing and writes nothing.
 */
final readonly class AuxiliaryControlChecks
{
    public const PERMISSION = Permission::LedgerView->value;

    /**
     * @return Collection<int, ControlCheck>
     */
    public function handle(?string $asOf = null, string $axis = 'fiscal_year'): Collection
    {
        Gate::authorize(self::PERMISSION);

        $asOf ??= BusinessDate::today();

        return ChartOfAccount::query()
            ->where('is_collective', true)
            ->where('is_archived', false)
            ->orderBy('code')
            ->get()
            ->map(fn (ChartOfAccount $account): ControlCheck => ControlCheck::reconciledOrBroken(
                key: 'auxiliary_'.$account->code,
                label: $account->code.' '.$account->name,
                expected: $this->collectiveBalance($account, $asOf),
                actual: $this->auxiliarySum($account, $asOf),
                axis: $axis,
                asOf: $asOf,
            ))
            ->values();
    }

    private function collectiveBalance(ChartOfAccount $account, string $asOf): int
    {
        return (int) $this->linesUpTo($account, $asOf)->sum(DB::raw('debit - credit'));
    }

    private function auxiliarySum(ChartOfAccount $account, string $asOf): int
    {
        return (int) $this->linesUpTo($account, $asOf)
            ->whereNotNull('journal_entry_lines.partner_id')
            ->sum(DB::raw('debit - credit'));
    }

    private function linesUpTo(ChartOfAccount $account, string $asOf): Builder
    {
        return DB::table('journal_entry_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->whereIn('journal_entries.status', JournalEntry::postedLedgerStatuses())
            ->where('journal_entry_lines.account_id', $account->id)
            ->whereDate('journal_entries.date', '<=', $asOf);
    }
}
