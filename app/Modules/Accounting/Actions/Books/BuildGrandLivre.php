<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §14.2 - the grand livre.
 *
 * Per account in code order: opening balance, every movement, a running
 * balance, closing balance. Reads through `postedLedger()` (posted +
 * reversed, L13) like every other book.
 *
 * The running balance is accumulated in PHP rather than by a SQL window
 * function, so the arithmetic stays plain integer minor units (00-core §5)
 * and behaves identically on every MySQL build the product supports.
 */
final class BuildGrandLivre
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $openingRows = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->where('journal_entries.date', '<', $periodStart)
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->groupBy('c.code')
            ->get(['c.code', DB::raw('SUM(l.debit) - SUM(l.credit) as net')]);

        $opening = [];

        foreach ($openingRows as $row) {
            $opening[(string) $row->code] = (int) $row->net;
        }

        $movements = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereBetween('journal_entries.date', [$periodStart, $periodEnd])
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->orderBy('c.code')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.piece_no')
            ->orderBy('l.sequence')
            ->get([
                'c.code as account_code',
                'c.name as account_name',
                'journal_entries.date',
                'journal_entries.piece_no',
                'journal_entries.label as entry_label',
                'l.label as line_label',
                'l.partner_type',
                'l.partner_id',
                'l.debit',
                'l.credit',
            ]);

        $byAccount = [];

        foreach ($movements as $movement) {
            $code = (string) $movement->account_code;

            if (! isset($byAccount[$code])) {
                $byAccount[$code] = [
                    'account_code' => $code,
                    'account_name' => (string) $movement->account_name,
                    'opening_balance' => $opening[$code] ?? 0,
                    'movements' => [],
                    'closing_balance' => $opening[$code] ?? 0,
                ];
            }

            $running = $byAccount[$code]['closing_balance']
                + ((int) $movement->debit - (int) $movement->credit);

            $label = $movement->line_label !== null && $movement->line_label !== ''
                ? $movement->line_label
                : $movement->entry_label;

            $byAccount[$code]['movements'][] = [
                'date' => (string) $movement->date,
                'piece_no' => (string) $movement->piece_no,
                'label' => (string) $label,
                'partner' => $movement->partner_type === null
                    ? ''
                    : $movement->partner_type.'#'.$movement->partner_id,
                'debit' => (int) $movement->debit,
                'credit' => (int) $movement->credit,
                'running_balance' => $running,
            ];

            $byAccount[$code]['closing_balance'] = $running;
        }

        ksort($byAccount);

        return array_values($byAccount);
    }
}
