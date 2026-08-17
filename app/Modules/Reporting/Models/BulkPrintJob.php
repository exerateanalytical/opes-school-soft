<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * docs/specs/10-documents.md §18.2 - a queued bulk print run.
 *
 * The table shipped in migration 2026_08_09_310005 with no model; this is
 * that model, mapped onto the existing columns exactly as migrated (no new
 * columns, no new migration).
 *
 * `output_path` holds the RELATIVE storage path of the one merged PDF this
 * run produced (§18.2's merge step). `manifest_path` holds the RELATIVE
 * storage path of the small JSON index of the per-subject files
 * RenderDocument wrote, which the Bulk Prints screen still reads to link to
 * each individual (hashed, print-logged) document.
 *
 * @property int $id
 * @property int $document_template_id
 * @property int $template_version
 * @property int $academic_year_id
 * @property int|null $class_group_id
 * @property int|null $assessment_period_id
 * @property string $mode
 * @property array<int, int>|null $subject_ids
 * @property string $language
 * @property string $paper_size
 * @property int $copies
 * @property bool $collate
 * @property string $duplex
 * @property string $status
 * @property int $total
 * @property int $succeeded
 * @property int $failed
 * @property string|null $output_path
 * @property string|null $manifest_path
 * @property int $requested_by
 * @property Carbon $requested_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
final class BulkPrintJob extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'document_template_id', 'template_version',
        'academic_year_id', 'class_group_id', 'assessment_period_id',
        'mode', 'subject_ids', 'language', 'paper_size',
        'copies', 'collate', 'duplex',
        'status', 'total', 'succeeded', 'failed', 'output_path', 'manifest_path',
        'requested_by', 'requested_at', 'started_at', 'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_ids' => 'array',
            'collate' => 'boolean',
            'copies' => 'integer',
            'total' => 'integer',
            'succeeded' => 'integer',
            'failed' => 'integer',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'document_template_id');
    }

    /**
     * A job is re-runnable only where there is something left to pick up:
     * §18.2's resumability rule, "re-running a `partial` job in `unprinted`
     * mode picks up exactly the failures".
     */
    public function isRetryable(): bool
    {
        return in_array($this->status, ['failed', 'partial'], true);
    }
}
