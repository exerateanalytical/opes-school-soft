<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Journal review worklist,
 * docs/specs/2026-08-12-accounting-finance-architecture.md §4.3.
 *
 * EVERY CATEGORY HERE IS LEGAL. A manual journal, a backdated entry, a
 * forward-posted entry (02-accounting.md §5.4) are all permitted. They are
 * listed because they are the entries an auditor asks about first, not
 * because they are wrong. Nothing here blocks, corrects or accuses.
 *
 * Read-only.
 */
final readonly class JournalExceptions
{
    public const PERMISSION = Permission::LedgerView->value;

    /** The filters the screen offers, in the order it offers them. */
    public const FILTERS = ['draft', 'manual', 'forward_posted', 'reversed', 'missing_piece'];

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        Gate::authorize(self::PERMISSION);

        $counts = [];
        foreach (self::FILTERS as $filter) {
            $counts[$filter] = $this->query($filter)->count();
        }

        return $counts;
    }

    /**
     * @return Builder<JournalEntry>
     */
    public function query(string $filter): Builder
    {
        Gate::authorize(self::PERMISSION);

        return match ($filter) {
            // No posting rule means a human wrote it by hand rather than an
            // operational event producing it.
            'manual' => JournalEntry::query()->postedLedger()->whereNull('posting_rule_id'),
            // §5.4: dated into a hard-locked period, so it landed in the first
            // open one while keeping its original value date.
            'forward_posted' => JournalEntry::query()->postedLedger()->where('is_forward_posted', true),
            'reversed' => JournalEntry::query()->where('status', JournalEntry::STATUS_REVERSED),
            // L15 / AUDCIF Art. 17: a posted entry with no pièce justificative.
            'missing_piece' => JournalEntry::query()->postedLedger()->where('attachment_count', 0),
            default => JournalEntry::query()->where('status', JournalEntry::STATUS_DRAFT),
        };
    }
}
