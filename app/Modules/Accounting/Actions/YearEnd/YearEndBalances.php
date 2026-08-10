<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\YearEnd;

use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The one place the year-end Actions ask "what is on this account, right
 * now, in this exercice?" - shared by the §18.1 closing entry (classes 6, 7,
 * 8) and the §18.2 à-nouveaux (classes 1-5) so the two can never disagree
 * about a balance.
 *
 * Read-only, query-builder only. `posted` AND `reversed` for the reason
 * TrialBalance's docblock spells out: filtering `reversed` away keeps the
 * reversal and loses the entry it cancels.
 *
 * Balances are SIGNED, debit-positive: `debit − credit`. A class 7 account
 * comes back negative, and the closing line that empties it is therefore the
 * arithmetic negation - no per-class sign table, no "is this a debit
 * account" lookup that a mis-seeded `normal_balance` could poison.
 *
 * The partner split is NOT an option flag: `perPartner()` groups by
 * (account, partner, due_date) precisely because §18.2 requires the carried
 * line to keep its due date - collapsing to one line per partner would
 * destroy the aging on 1 January, which is the same mistake as collapsing to
 * one line per account destroying the auxiliary ledger.
 */
final readonly class YearEndBalances
{
    /**
     * Per-account net balances for postable accounts in the given classes,
     * excluding accounts that carry partner detail (those come from
     * `perPartner()`), and excluding nil balances.
     *
     * @param  list<int>  $classes
     * @return list<array{account_id: int, code: string, name: string, balance: int}>
     */
    public function perAccount(int $fiscalYearId, array $classes): array
    {
        $rows = $this->base($fiscalYearId, $classes)
            ->where(function ($query): void {
                $query->where('a.is_collective', false)->where('a.requires_partner', false);
            })
            ->groupBy('a.id', 'a.code', 'a.name')
            ->havingRaw('COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0)')
            ->orderBy('a.code')
            ->selectRaw('a.id, a.code, a.name, CAST(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS SIGNED) as balance')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'balance' => (int) $row->balance,
            ];
        }

        return $out;
    }

    /**
     * Per-(account, partner, due_date) net balances on the collective /
     * partner-bearing accounts of the given classes. §18.2's several-thousand
     * -line entry starts here.
     *
     * @param  list<int>  $classes
     * @return list<array{account_id: int, code: string, name: string, partner_type: string, partner_id: int, due_date: string|null, balance: int}>
     */
    public function perPartner(int $fiscalYearId, array $classes): array
    {
        $rows = $this->base($fiscalYearId, $classes)
            ->where(function ($query): void {
                $query->where('a.is_collective', true)->orWhere('a.requires_partner', true);
            })
            ->whereNotNull('l.partner_type')
            ->whereNotNull('l.partner_id')
            ->groupBy('a.id', 'a.code', 'a.name', 'l.partner_type', 'l.partner_id', 'l.due_date')
            ->havingRaw('COALESCE(SUM(l.debit), 0) <> COALESCE(SUM(l.credit), 0)')
            ->orderBy('a.code')
            ->orderBy('l.partner_type')
            ->orderBy('l.partner_id')
            ->selectRaw(
                'a.id, a.code, a.name, l.partner_type, l.partner_id, l.due_date,'
                .' CAST(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS SIGNED) as balance'
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'partner_type' => (string) $row->partner_type,
                'partner_id' => (int) $row->partner_id,
                'due_date' => $row->due_date === null ? null : (string) $row->due_date,
                'balance' => (int) $row->balance,
            ];
        }

        return $out;
    }

    /**
     * Lines on a partner-bearing account that carry NO partner. L8's trigger
     * is supposed to make these impossible; the à-nouveaux asks anyway,
     * because carrying one would be a lump line that silently breaks the
     * auxiliary ledger of the NEW exercice - discovered a year later.
     *
     * @param  list<int>  $classes
     * @return list<array{account_id: int, code: string, balance: int}>
     */
    public function orphanedCollectiveLines(int $fiscalYearId, array $classes): array
    {
        $rows = $this->base($fiscalYearId, $classes)
            ->where(function ($query): void {
                $query->where('a.is_collective', true)->orWhere('a.requires_partner', true);
            })
            ->where(function ($query): void {
                $query->whereNull('l.partner_type')->orWhereNull('l.partner_id');
            })
            ->groupBy('a.id', 'a.code')
            ->selectRaw('a.id, a.code, CAST(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS SIGNED) as balance')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[] = [
                'account_id' => (int) $row->id,
                'code' => (string) $row->code,
                'balance' => (int) $row->balance,
            ];
        }

        return $out;
    }

    /** The signed balance of one account in one exercice. */
    public function accountBalance(int $fiscalYearId, int $accountId): int
    {
        /** @var object{balance: int|string}|null $row */
        $row = DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->where('l.account_id', $accountId)
            ->selectRaw('CAST(COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS SIGNED) as balance')
            ->first();

        return (int) ($row->balance ?? 0);
    }

    /**
     * @param  list<int>  $classes
     */
    private function base(int $fiscalYearId, array $classes): Builder
    {
        return DB::table('journal_entry_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('chart_of_accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.fiscal_year_id', $fiscalYearId)
            ->whereIn('e.status', [JournalEntry::STATUS_POSTED, JournalEntry::STATUS_REVERSED])
            ->whereIn('a.account_class', $classes);
    }
}
