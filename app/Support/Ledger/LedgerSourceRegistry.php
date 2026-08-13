<?php

declare(strict_types=1);

namespace App\Support\Ledger;

/**
 * Collects every module's ledger-source resolver.
 *
 * Registration order is resolution priority: the first resolver to claim an
 * entry wins. An entry claimed by two documents would be a data fault, and
 * a deterministic answer beats one that changes between page loads.
 */
final class LedgerSourceRegistry
{
    /** @var list<ResolvesLedgerSource> */
    private array $resolvers = [];

    public function register(ResolvesLedgerSource $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @param  list<int>  $journalEntryIds
     * @return array<int, SourceReference>
     */
    public function forEntryIds(array $journalEntryIds): array
    {
        if ($journalEntryIds === []) {
            return [];
        }

        $resolved = [];

        foreach ($this->resolvers as $resolver) {
            foreach ($resolver->forEntryIds($journalEntryIds) as $entryId => $reference) {
                $resolved[$entryId] ??= $reference;
            }
        }

        return $resolved;
    }

    /** @return list<ResolvesLedgerSource> */
    public function resolvers(): array
    {
        return $this->resolvers;
    }
}
