<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions\Review;

use App\Modules\Identity\Domain\Permission;
use App\Support\Ledger\LedgerSourceRegistry;
use App\Support\Ledger\SourceReference;
use Illuminate\Support\Facades\Gate;

/**
 * Resolves a journal entry to the document that caused it,
 * docs/specs/2026-08-12-accounting-finance-architecture.md 6.1.
 *
 * THE LINK IS A REVERSE ONE. journal_entries.source_type is always the
 * literal 'posting_event' and source_id is never populated - verified
 * 2026-08-12. The usable link is the journal_entry_id foreign key each
 * document model carries.
 *
 * This Action asks the shared-kernel registry rather than importing other
 * modules' models, so the reverse lookup stays legal under the boundary
 * rule (00-core 6.2) - the same reasoning that put Audit\Actor in the
 * shared kernel.
 *
 * Read-only. It resolves and presents; it never writes.
 */
final readonly class ResolveSourceDocument
{
    public const PERMISSION = Permission::LedgerView->value;

    public function __construct(private LedgerSourceRegistry $registry) {}

    public function handle(int $journalEntryId): SourceReference
    {
        return $this->forEntryIds([$journalEntryId])[$journalEntryId];
    }

    /**
     * @param  list<int>  $journalEntryIds
     * @return array<int, SourceReference>
     */
    public function forEntryIds(array $journalEntryIds): array
    {
        Gate::authorize(self::PERMISSION);

        $resolved = $this->registry->forEntryIds($journalEntryIds);

        // Every requested id gets an answer. An entry no document owns is a
        // manual journal - a complete answer, not a gap.
        foreach ($journalEntryIds as $id) {
            $resolved[$id] ??= SourceReference::inert(__('opes.accounting.review.source_manual'));
        }

        return $resolved;
    }
}
