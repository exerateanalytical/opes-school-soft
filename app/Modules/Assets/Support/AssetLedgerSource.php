<?php

declare(strict_types=1);

namespace App\Modules\Assets\Support;

use App\Modules\Assets\Models\Asset;
use App\Support\Ledger\ResolvesLedgerSource;
use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Names the asset that caused a journal entry.
 *
 * Imports only this module's own model, which is what makes the reverse
 * lookup legal under the boundary rule.
 */
final readonly class AssetLedgerSource implements ResolvesLedgerSource
{
    private const ROUTE = 'assets.show';

    public function forEntryIds(array $journalEntryIds): array
    {
        if (! RouteFacade::has(self::ROUTE)) {
            return [];
        }

        $resolved = [];

        foreach (Asset::query()->whereIn('journal_entry_id', $journalEntryIds)->get(['id', 'journal_entry_id']) as $row) {
            $resolved[(int) $row->journal_entry_id] = SourceReference::linked(
                __('opes.accounting.review.source_asset', ['id' => $row->id]),
                route(self::ROUTE, ['asset' => $row->id]),
            );
        }

        return $resolved;
    }
}
