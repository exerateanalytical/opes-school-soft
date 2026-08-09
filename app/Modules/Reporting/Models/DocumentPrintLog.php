<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * docs/specs/10-documents.md 4.4 / 00-core 14 - EVERY render leaves a row
 * here, issued or live, and the row never changes and is never deleted:
 * "who printed the whole class's cards on 12 April" must stay answerable
 * for ten years on accounting-bearing documents.
 *
 * `is_duplicate` is DERIVED inside the render transaction - the count of
 * prior successful prints of the same IssuedDocument - never passed in by a
 * caller (4.5).
 *
 * @property int $id
 * @property int $document_template_id
 * @property int $template_version
 * @property int|null $issued_document_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $subject_label_at_time
 * @property int|null $snapshot_version
 * @property bool $is_duplicate
 * @property int $copy_no
 * @property string $language
 * @property string $paper_size
 * @property int|null $bulk_print_job_id
 * @property int $printed_by
 * @property string $actor_name_at_time
 * @property Carbon $printed_at
 * @property string|null $ip
 */
final class DocumentPrintLog extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'document_template_id', 'template_version', 'issued_document_id',
        'subject_type', 'subject_id', 'subject_label_at_time',
        'snapshot_version', 'is_duplicate', 'copy_no',
        'language', 'paper_size', 'bulk_print_job_id',
        'printed_by', 'actor_name_at_time', 'printed_at', 'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'subject_id' => 'integer',
            'snapshot_version' => 'integer',
            'is_duplicate' => 'boolean',
            'copy_no' => 'integer',
            'printed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (DocumentPrintLog $log): void {
            throw new RuntimeException(
                'A document print log row is immutable (00-core 14); a further print is a further row.'
            );
        });

        static::deleting(function (DocumentPrintLog $log): void {
            throw new RuntimeException(
                'A document print log row cannot be deleted (00-core 10.5).'
            );
        });
    }

    /**
     * @return BelongsTo<IssuedDocument, $this>
     */
    public function issuedDocument(): BelongsTo
    {
        return $this->belongsTo(IssuedDocument::class, 'issued_document_id');
    }
}
