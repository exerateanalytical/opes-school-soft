<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * A module's offer to name the documents it owns for a set of journal
 * entries, docs/specs/2026-08-12-accounting-finance-architecture.md 6.1.
 *
 * Each module implements this for its OWN models only. That is what keeps
 * the reverse lookup legal under the module boundary rule - Accounting asks
 * the registry, never another module's Models.
 *
 * Implementations MUST resolve a batch in a bounded number of queries. One
 * query per entry would make every ledger screen quadratic.
 */
interface ResolvesLedgerSource
{
    /**
     * @param  list<int>  $journalEntryIds
     * @return array<int, SourceReference>  keyed by journal entry id; entries
     *                                      this module does not own are absent
     */
    public function forEntryIds(array $journalEntryIds): array;
}
