<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Append-only, hash-chained audit trail (docs/specs/00-core.md 14).
 *
 * The guards below stop the application from mutating history. They do not
 * stop someone with direct database access - that is what the hash chain is
 * for, and why VerifyAuditChain runs nightly.
 *
 * @property int $id
 * @property string $action
 * @property string $module
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property int|null $actor_id
 * @property string $actor_name_at_time
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string $prev_hash
 * @property string $row_hash
 * @property Carbon|null $created_at
 */
class AuditLog extends Model
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new RuntimeException('AuditLog is append-only; entries cannot be updated.');
        });

        static::deleting(static function (): never {
            throw new RuntimeException('AuditLog is append-only; entries cannot be deleted.');
        });
    }

    /**
     * The canonical serialisation the hash covers.
     *
     * Field order is fixed and must never change, or every historical row
     * fails verification. Adding a field means a new chain segment, not a
     * silent reordering.
     *
     * @return array<string, mixed>
     */
    public function hashPayload(): array
    {
        return [
            'action' => $this->action,
            'module' => $this->module,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'actor_id' => $this->actor_id,
            'actor_name_at_time' => $this->actor_name_at_time,
            'before' => self::canonicalise($this->before),
            'after' => self::canonicalise($this->after),
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
            'prev_hash' => $this->prev_hash,
        ];
    }

    public function computeRowHash(): string
    {
        return hash('sha256', json_encode($this->hashPayload(), JSON_THROW_ON_ERROR));
    }

    /**
     * Reduce a decoded JSON payload to one deterministic shape.
     *
     * The stored string cannot be hashed directly: MySQL normalises JSON
     * columns on write (key order, whitespace), so the bytes handed to the
     * driver are not the bytes read back, and every row would fail
     * verification the moment it left memory. Sorting keys recursively gives
     * a representation that is identical whoever encoded it.
     *
     * @param  array<array-key, mixed>|null  $value
     * @return array<array-key, mixed>|null
     */
    private static function canonicalise(?array $value): ?array
    {
        if ($value === null) {
            return null;
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalise($item);
            }
        }

        return $value;
    }
}
