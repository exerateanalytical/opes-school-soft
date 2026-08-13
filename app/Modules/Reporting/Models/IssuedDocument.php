<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/10-documents.md 4.4 - one row per document ISSUED
 * (snapshot-backed templates only). The first successful render is the
 * original; every later render of the same row is a DUPLICATA (4.5).
 *
 * Append-only except the lifecycle columns: status may move valid->revoked
 * or valid->superseded (with their actor/reason/pointer columns), and
 * nothing else ever changes - the serial, hash, subject and snapshot pins
 * ARE the document's identity, and an edited identity is a forged document.
 *
 * @property int $id
 * @property int $document_template_id
 * @property int $template_version
 * @property string|null $series_code
 * @property string|null $serial
 * @property string $subject_type
 * @property int $subject_id
 * @property string $snapshot_type
 * @property int $snapshot_id
 * @property array<string, mixed>|null $payload_snapshot
 * @property string $language
 * @property string $content_hash
 * @property string|null $qr_token
 * @property int $issued_by
 * @property Carbon $issued_at
 * @property string $issued_by_name_at_time
 * @property string $status
 * @property int|null $revoked_by
 * @property Carbon|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $superseded_by_document_id
 */
final class IssuedDocument extends Model
{
    public const STATUS_VALID = 'valid';

    public const STATUS_REVOKED = 'revoked';

    public const STATUS_SUPERSEDED = 'superseded';

    /** @var list<string> */
    protected $fillable = [
        'document_template_id', 'template_version',
        'series_code', 'serial',
        'subject_type', 'subject_id',
        'snapshot_type', 'snapshot_id', 'payload_snapshot',
        'language', 'content_hash', 'qr_token',
        'issued_by', 'issued_at', 'issued_by_name_at_time',
        'status', 'revoked_by', 'revoked_at', 'revoked_reason',
        'superseded_by_document_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'subject_id' => 'integer',
            'snapshot_id' => 'integer',
            'payload_snapshot' => 'array',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (IssuedDocument $document): void {
            $mutable = [
                'status', 'revoked_by', 'revoked_at', 'revoked_reason',
                'superseded_by_document_id', 'updated_at',
            ];

            foreach (array_keys($document->getDirty()) as $column) {
                if (! in_array($column, $mutable, true)) {
                    throw new RuntimeException(sprintf(
                        'IssuedDocument %s is append-only outside its lifecycle columns; [%s] cannot change. '
                        .'A corrected document is a new issue that supersedes this one (10-documents 4.4).',
                        (string) ($document->getOriginal('serial') ?? $document->getKey()),
                        $column,
                    ));
                }
            }

            if ($document->isDirty('status')
                && (string) $document->getOriginal('status') === self::STATUS_REVOKED) {
                throw new RuntimeException(
                    'A revoked document cannot be un-revoked; issue a new document instead (10-documents 17.2).'
                );
            }
        });

        static::deleting(function (IssuedDocument $document): void {
            throw new RuntimeException(
                'An issued document record cannot be deleted (00-core 10.5); revoke it instead.'
            );
        });
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED;
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /**
     * @return HasMany<DocumentPrintLog, $this>
     */
    public function printLogs(): HasMany
    {
        return $this->hasMany(DocumentPrintLog::class, 'issued_document_id');
    }
}
