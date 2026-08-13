<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Models\PayrollRun;
use App\Support\Ledger\ResolvesLedgerSource;
use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Names the payroll run that caused a journal entry.
 *
 * Imports only this module's own model, which is what makes the reverse
 * lookup legal under the boundary rule.
 */
final readonly class PayrollLedgerSource implements ResolvesLedgerSource
{
    private const ROUTE = 'payroll.runs.show';

    public function forEntryIds(array $journalEntryIds): array
    {
        if (! RouteFacade::has(self::ROUTE)) {
            return [];
        }

        $resolved = [];

        foreach (PayrollRun::query()->whereIn('journal_entry_id', $journalEntryIds)->get(['id', 'journal_entry_id']) as $row) {
            $resolved[(int) $row->journal_entry_id] = SourceReference::linked(
                __('opes.accounting.review.source_payroll_run', ['id' => $row->id]),
                route(self::ROUTE, ['run' => $row->id]),
            );
        }

        return $resolved;
    }
}
