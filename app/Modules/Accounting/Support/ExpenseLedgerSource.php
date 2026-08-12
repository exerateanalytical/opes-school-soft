<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Support;

use App\Modules\Accounting\Models\Expense;
use App\Support\Ledger\ResolvesLedgerSource;
use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Names the expense that caused a journal entry.
 *
 * Imports only this module's own model, which is what makes the reverse
 * lookup legal under the boundary rule.
 */
final readonly class ExpenseLedgerSource implements ResolvesLedgerSource
{
    private const ROUTE = 'accounting.expenses.show';

    public function forEntryIds(array $journalEntryIds): array
    {
        if (! RouteFacade::has(self::ROUTE)) {
            return [];
        }

        $resolved = [];

        foreach (Expense::query()->whereIn('journal_entry_id', $journalEntryIds)->get(['id', 'journal_entry_id']) as $row) {
            $resolved[(int) $row->journal_entry_id] = SourceReference::linked(
                __('opes.accounting.review.source_expense', ['id' => $row->id]),
                route(self::ROUTE, ['expense' => $row->id]),
            );
        }

        return $resolved;
    }
}
