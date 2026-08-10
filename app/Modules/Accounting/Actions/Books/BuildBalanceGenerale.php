<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §14.2 - the balance generale.
 *
 * Per account: opening debit/credit, period movement debit/credit, closing
 * debit/credit, with a grand total where Sigma debit = Sigma credit.
 *
 * Opening is everything in the fiscal year strictly BEFORE `period_start`;
 * movement is the period itself. Both read through `postedLedger()` (posted
 * + reversed, L13) - see BuildLivreJournal for why that matters.
 *
 * Presented in the SYSCOHADA convention: a net debit balance prints in the
 * debit column and a net credit balance in the credit column. Never a signed
 * number in a single column - an auditor reads two columns, and a "-450 000"
 * in the debit column is not a balance générale.
 */
final class BuildBalanceGenerale
{
    /**
     * @return array{rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $opening = $this->aggregate($fiscalYearId, null, $periodStart, true);
        $movement = $this->aggregate($fiscalYearId, $periodStart, $periodEnd, false);

        $codes = array_unique(array_merge(array_keys($opening), array_keys($movement)));
        sort($codes);

        $rows = [];
        $totals = [
            'opening_debit' => 0, 'opening_credit' => 0,
            'movement_debit' => 0, 'movement_credit' => 0,
            'closing_debit' => 0, 'closing_credit' => 0,
        ];

        foreach ($codes as $code) {
            $o = $opening[$code] ?? ['name' => '', 'debit' => 0, 'credit' => 0];
            $m = $movement[$code] ?? ['name' => '', 'debit' => 0, 'credit' => 0];

            $openingNet = $o['debit'] - $o['credit'];
            $closingNet = $openingNet + ($m['debit'] - $m['credit']);

            $row = [
                'account_code' => (string) $code,
                'account_name' => $o['name'] !== '' ? $o['name'] : $m['name'],
                'opening_debit' => max($openingNet, 0),
                'opening_credit' => max(-$openingNet, 0),
                'movement_debit' => $m['debit'],
                'movement_credit' => $m['credit'],
                'closing_debit' => max($closingNet, 0),
                'closing_credit' => max(-$closingNet, 0),
            ];

            foreach (array_keys($totals) as $key) {
                $totals[$key] += (int) $row[$key];
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /**
     * @return array<string, array{name: string, debit: int, credit: int}>
     */
    private function aggregate(int $fiscalYearId, ?string $from, string $to, bool $exclusiveTo): array
    {
        $query = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id');

        if ($from !== null) {
            $query->where('journal_entries.date', '>=', $from);
        }

        $query->where('journal_entries.date', $exclusiveTo ? '<' : '<=', $to);

        $rows = $query->groupBy('c.code', 'c.name')->get([
            'c.code',
            'c.name',
            DB::raw('SUM(l.debit) as total_debit'),
            DB::raw('SUM(l.credit) as total_credit'),
        ]);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->code] = [
                'name' => (string) $row->name,
                'debit' => (int) $row->total_debit,
                'credit' => (int) $row->total_credit,
            ];
        }

        return $out;
    }
}
