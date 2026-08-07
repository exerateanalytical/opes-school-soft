<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;

final class AuditChainResult
{
    public function __construct(
        public readonly int $checked,
        public readonly ?int $firstBrokenId,
        public readonly ?string $reason,
    ) {
    }

    public function isIntact(): bool
    {
        return $this->firstBrokenId === null;
    }
}

/**
 * Walk the chain and confirm every row still hashes to what it claims, and
 * that each row's prev_hash matches its predecessor's row_hash.
 *
 * Catches both tampering (payload edited) and excision (row deleted).
 */
final class VerifyAuditChain
{
    public function handle(): AuditChainResult
    {
        $expectedPrevious = AuditLog::GENESIS_HASH;
        $checked = 0;
        $broken = null;
        $reason = null;

        AuditLog::query()
            ->orderBy('id')
            ->chunk(500, function (Collection $entries) use (&$expectedPrevious, &$checked, &$broken, &$reason): bool {
                /** @var AuditLog $entry */
                foreach ($entries as $entry) {
                    $checked++;

                    if ($entry->prev_hash !== $expectedPrevious) {
                        $broken = $entry->id;
                        $reason = 'prev_hash does not match the previous row (row deleted or reordered)';

                        return false;
                    }

                    if ($entry->computeRowHash() !== $entry->row_hash) {
                        $broken = $entry->id;
                        $reason = 'row_hash does not match the payload (entry tampered with)';

                        return false;
                    }

                    $expectedPrevious = $entry->row_hash;
                }

                return true;
            });

        return new AuditChainResult($checked, $broken, $reason);
    }
}
