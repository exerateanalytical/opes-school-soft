<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use App\Support\Retention\Immutable10Year;

/**
 * docs/specs/02-accounting.md §4.4 - pièces justificatives (AUDCIF Art. 17).
 *
 * Model shell only. This agent's ownership (see the phase-4 task brief) is
 * this class, not its migration: the `journal_entry_attachments` table is
 * not among the two pre-assigned migrations
 * (`create_journal_entries_table`, `create_journal_entry_lines_table`), and
 * L15 (every posted entry carries ≥1 attachment or an explicit
 * no_attachment_reason) is not in this agent's invariant list - both are
 * left for whichever later slice lands attachment storage. Declared now so
 * `JournalEntry` relations and future Actions can type against it without a
 * cross-agent file-ownership collision.
 *
 * @property int $id
 * @property int $journal_entry_id
 * @property string $document_type
 * @property string $file_path
 * @property string $sha256
 * @property string $original_filename
 * @property int $byte_size
 * @property int|null $uploaded_by
 * @property Carbon|null $uploaded_at
 * @property bool $is_generated
 */
final class JournalEntryAttachment extends Model
{
    use Immutable10Year;
    /** @var list<string> */
    protected $fillable = [
        'journal_entry_id',
        'document_type',
        'file_path',
        'sha256',
        'original_filename',
        'byte_size',
        'uploaded_by',
        'uploaded_at',
        'is_generated',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'uploaded_at' => 'datetime',
            'is_generated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
