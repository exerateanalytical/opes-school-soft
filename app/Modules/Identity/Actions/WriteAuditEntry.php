<?php

declare(strict_types=1);

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Models\AuditChainAnchor;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request;

/**
 * The ONLY writer of audit rows.
 *
 * Serialised on the tail of the chain: two concurrent writes reading the same
 * predecessor would produce two rows claiming the same prev_hash, which is a
 * fork, and a forked chain cannot be verified.
 */
final class WriteAuditEntry
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function handle(
        AuditAction $action,
        string $module,
        ?string $auditableType = null,
        ?int $auditableId = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        return DB::transaction(function () use (
            $action, $module, $auditableType, $auditableId, $before, $after, $actor
        ): AuditLog {
            $previous = AuditLog::query()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $entry = new AuditLog([
                'action' => $action->value,
                'module' => $module,
                'auditable_type' => $auditableType,
                'auditable_id' => $auditableId,
                'actor_id' => $actor?->getKey(),
                // `->` not `?->`: inside ?? the null case is already handled,
                // and PHPStan level 8 rejects the redundant nullsafe.
                'actor_name_at_time' => $actor->name ?? 'system',
                'before' => $before,
                'after' => $after,
                'ip' => Request::ip(),
                'user_agent' => substr((string) Request::userAgent(), 0, 255),
                'prev_hash' => $previous->row_hash ?? AuditLog::GENESIS_HASH,
            ]);

            $entry->created_at = now();
            $entry->row_hash = $entry->computeRowHash();
            $entry->save();

            // Advance the anchor in the SAME transaction. Without this, deleting
            // the newest rows leaves a chain that still verifies from genesis -
            // confirmed empirically before the anchor existed. The anchor makes
            // tail truncation evident by forcing an attacker to alter two places
            // consistently, and gives the backup job a value to export off-box.
            AuditChainAnchor::query()->updateOrCreate(
                ['id' => AuditChainAnchor::SINGLETON_ID],
                [
                    'last_row_hash' => $entry->row_hash,
                    'entry_count' => DB::table('audit_logs')->count(),
                    'last_entry_id' => (int) $entry->getKey(),
                    'updated_at' => now(),
                ],
            );

            return $entry;
        });
    }
}
