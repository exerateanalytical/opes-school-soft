<?php

declare(strict_types=1);

namespace App\Modules\Library\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 06-assets-stores.md §10.4 - append-only (trigger-enforced); a renewal
 * is history, corrections are new rows.
 *
 * @property int $id
 * @property int $library_issue_id
 * @property string $renewed_on
 * @property string $previous_due_on
 * @property string $new_due_on
 * @property int $renewed_by
 */
final class LibraryRenewal extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'library_issue_id', 'renewed_on', 'previous_due_on', 'new_due_on', 'renewed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'library_issue_id' => 'integer',
            'renewed_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<LibraryIssue, $this>
     */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(LibraryIssue::class, 'library_issue_id');
    }
}
