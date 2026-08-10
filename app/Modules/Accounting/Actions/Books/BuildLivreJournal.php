<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Books;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/02-accounting.md §14.2 - the livre-journal.
 *
 * Every entry in chronological order by `date` then `piece_no`, carrying
 * journal, piece number, both dates, label, account, partner, debit and
 * credit.
 *
 * Reads through `postedLedger()`, which is `posted` + `reversed` (L13). That
 * is deliberate and it is the difference between a legally correct register
 * and a wrong one: a reversal exists to CANCEL its original by netting to
 * zero in the book, never by making the original disappear. Filtering to
 * `posted` alone would drop the original half of the pair, keep the reversal,
 * and silently flip the sign of the whole transaction - and the book would
 * still balance, so nothing downstream would notice.
 *
 * `draft` is the only status excluded: it has no accounting reality yet.
 */
final class BuildLivreJournal
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(int $fiscalYearId, string $periodStart, string $periodEnd): array
    {
        Gate::authorize(Permission::LedgerView->value);

        $rows = JournalEntry::query()
            ->postedLedger()
            ->where('journal_entries.fiscal_year_id', $fiscalYearId)
            ->whereBetween('journal_entries.date', [$periodStart, $periodEnd])
            ->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'journal_entries.id')
            ->join('chart_of_accounts as c', 'c.id', '=', 'l.account_id')
            ->join('journals as j', 'j.id', '=', 'journal_entries.journal_id')
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.piece_no')
            ->orderBy('l.sequence')
            ->get([
                'journal_entries.date',
                'journal_entries.value_date',
                'journal_entries.piece_no',
                'journal_entries.label as entry_label',
                'j.code as journal_code',
                'c.code as account_code',
                'c.name as account_name',
                'l.label as line_label',
                'l.partner_type',
                'l.partner_id',
                'l.debit',
                'l.credit',
            ]);

        return $rows->map(static fn (object $r): array => [
            'date' => (string) $r->date,
            'value_date' => (string) $r->value_date,
            'piece_no' => (string) $r->piece_no,
            'journal_code' => (string) $r->journal_code,
            'account_code' => (string) $r->account_code,
            'account_name' => (string) $r->account_name,
            'label' => (string) ($r->line_label !== null && $r->line_label !== '' ? $r->line_label : $r->entry_label),
            'partner' => $r->partner_type === null ? '' : $r->partner_type.'#'.$r->partner_id,
            'debit' => (int) $r->debit,
            'credit' => (int) $r->credit,
        ])->all();
    }
}
