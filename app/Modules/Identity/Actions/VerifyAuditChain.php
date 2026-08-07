<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\AuditChainAnchor;
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

        if ($broken !== null) {
            return new AuditChainResult($checked, $broken, $reason);
        }

        return $this->verifyAgainstAnchor($checked, $expectedPrevious);
    }

    /**
     * Compare the walked tail against the recorded anchor.
     *
     * A genesis-anchored chain alone cannot detect TRUNCATION: delete the newest
     * rows and the remainder is still a valid chain, so verification reports
     * "intact". That was confirmed empirically. Since the newest entries are
     * exactly the ones recording an intruder's actions, this is the deletion
     * that matters most, and the anchor is what makes it evident.
     */
    private function verifyAgainstAnchor(int $checked, string $walkedHead): AuditChainResult
    {
        $anchor = AuditChainAnchor::query()->find(AuditChainAnchor::SINGLETON_ID);

        if ($anchor === null) {
            // No anchor and no entries is a legitimately empty log.
            if ($checked === 0) {
                return new AuditChainResult(0, null, null);
            }

            return new AuditChainResult(
                $checked,
                0,
                'the chain anchor is missing, so the head of the chain cannot be trusted',
            );
        }

        if ($anchor->entry_count !== $checked) {
            return new AuditChainResult(
                $checked,
                $anchor->last_entry_id,
                sprintf(
                    'the anchor expects %d entries but %d are present (entries were deleted from the end)',
                    $anchor->entry_count,
                    $checked,
                ),
            );
        }

        if ($checked > 0 && $anchor->last_row_hash !== $walkedHead) {
            return new AuditChainResult(
                $checked,
                $anchor->last_entry_id,
                'the head of the chain does not match the anchor (the most recent entry was altered or replaced)',
            );
        }

        return new AuditChainResult($checked, null, null);
    }
}
