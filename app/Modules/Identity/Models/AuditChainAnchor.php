<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The expected head of the audit chain (docs/specs/00-core.md 14).
 *
 * Exactly one row, id = 1, maintained inside WriteAuditEntry's transaction.
 *
 * Why it exists: a hash chain anchored only at its genesis detects tampering
 * and mid-chain deletion, but NOT truncation of the tail — remove the newest
 * rows and what remains is still a valid chain. Verified empirically before
 * this was added: deleting the two most recent entries left the chain
 * reporting "intact".
 *
 * @property string $last_row_hash
 * @property int $entry_count
 * @property int $last_entry_id
 */
class AuditChainAnchor extends Model
{
    public const SINGLETON_ID = 1;

    public $timestamps = false;

    protected $table = 'audit_chain_anchors';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_count' => 'integer',
            'last_entry_id' => 'integer',
            'updated_at' => 'datetime',
        ];
    }
}
