<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use stdClass;

/**
 * The grand livre for a single account, docs/specs/02-accounting.md §4.3 L13
 * / §9.3: a chronological line listing with a running balance, built only
 * from `JournalEntry::query()->postedLedger()` (D3's scope) so a reversed
 * entry and the reversal that cancels it both appear and net to zero, rather
 * than one half silently vanishing and flipping the account's sign.
 *
 * Read-only: no audit log entry, gated on `ledger.view` only. Queries via
 * the plain query builder against journal_entry_lines/journal_entries
 * directly (rather than JournalEntry::query()->join(...)->get()) so the
 * result rows are plain stdClass objects matching the docblock shape below,
 * not JournalEntry models carrying attributes their own schema does not
 * declare.
 */
final readonly class GeneralLedgerQuery
{
    /**
     * @param  array{from?: string, to?: string}|null  $dateRange
     * @return Collection<int, stdClass&object{
     *     line_id: int,
     *     entry_id: int,
     *     date: string,
     *     piece_no: string|null,
     *     label: string,
     *     debit: int,
     *     credit: int,
     *     running_balance: int,
     * }>
     */
    public function handle(int $accountId, int $fiscalYearId, ?array $dateRange = null): Collection
    {
        Gate::authorize(Permission::LedgerView->value);

        $from = $dateRange['from'] ?? null;
        $to = $dateRange['to'] ?? null;

        $postedEntryIds = JournalEntry::query()
            ->postedLedger()
            ->where('fiscal_year_id', $fiscalYearId)
            ->when($from !== null, fn ($query) => $query->whereDate('date', '>=', $from))
            ->when($to !== null, fn ($query) => $query->whereDate('date', '<=', $to))
            ->select('id', 'date', 'piece_no');

        /** @var Collection<int, object{line_id: int, entry_id: int, date: string, piece_no: string|null, label: string, debit: int, credit: int}> $rows */
        $rows = DB::table('journal_entry_lines as l')
            ->joinSub($postedEntryIds, 'e', 'e.id', '=', 'l.journal_entry_id')
            ->where('l.account_id', $accountId)
            ->orderBy('e.date')
            ->orderBy('e.id')
            ->orderBy('l.sequence')
            ->select([
                'l.id as line_id',
                'e.id as entry_id',
                'e.date',
                'e.piece_no',
                'l.label',
                DB::raw('CAST(l.debit AS SIGNED) as debit'),
                DB::raw('CAST(l.credit AS SIGNED) as credit'),
            ])
            ->get();

        $running = 0;

        return $rows->map(function (object $row) use (&$running): object {
            $running = (int) ($running + $row->debit - $row->credit);

            return (object) [
                'line_id' => $row->line_id,
                'entry_id' => $row->entry_id,
                'date' => $row->date,
                'piece_no' => $row->piece_no,
                'label' => $row->label,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'running_balance' => $running,
            ];
        });
    }
}
