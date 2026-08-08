<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Actions;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryAttachment;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * docs/specs/02-accounting.md §4.4 - pièces justificatives (AUDCIF Art. 17).
 *
 * The sha256 is computed on the STORED copy, at write time, using
 * `hash_file('sha256', ...)` - the same pattern
 * app/Modules/Operations/Actions/CreateBackup.php uses for the backup
 * manifest: "a fingerprint the restore drill re-asserts against the
 * restored copy", here a fingerprint any later reader can re-assert
 * against the file on disk to prove it has not been altered since upload.
 *
 * ---
 * L15 CONTRACT FOR D3's `PostJournalEntry` / THE INTEGRATOR
 * (docs/specs/02-accounting.md §4.3 L15, out of this agent's scope):
 *
 *   "Every posted entry carries >= 1 attachment OR an explicit
 *    no_attachment_reason, configurable per journal."
 *
 * This agent does not own `Journal` or `JournalEntry`'s migrations/models,
 * so it cannot add the `Journal.requires_attachment` column or a
 * `JournalEntry.no_attachment_reason` column that L15's check needs.
 * What THIS class exposes for that check:
 *
 *     AttachDocument::hasAttachment(int $journalEntryId): bool
 *
 * `PostJournalEntry` (or whichever Action ends up enforcing L15) should,
 * immediately before flipping an entry to `posted`, do the equivalent of:
 *
 *     if ($journal->requires_attachment
 *         && ! AttachDocument::hasAttachment($entry->id)
 *         && trim((string) $entry->no_attachment_reason) === '') {
 *         throw new DomainException('L15: this journal requires an attachment or an explicit reason.');
 *     }
 *
 * which needs two columns this agent does not own: `journals.
 * requires_attachment` (BOOLEAN, default true) and `journal_entries.
 * no_attachment_reason` (VARCHAR NULL). Neither exists on disk yet.
 * ---
 */
final class AttachDocument
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $journalEntryId,
        string $documentType,
        string $sourcePath,
        string $originalFilename,
        Actor $actor,
        bool $isGenerated = false,
    ): JournalEntryAttachment {
        Gate::authorize(Permission::LedgerPost->value);

        if (! is_file($sourcePath)) {
            throw new DomainException(sprintf('Source file [%s] does not exist.', $sourcePath));
        }

        return DB::transaction(function () use (
            $journalEntryId, $documentType, $sourcePath, $originalFilename, $actor, $isGenerated
        ): JournalEntryAttachment {
            /** @var JournalEntry $entry */
            $entry = JournalEntry::query()->whereKey($journalEntryId)->lockForUpdate()->firstOrFail();

            $directory = storage_path('app'.DIRECTORY_SEPARATOR.'accounting'.DIRECTORY_SEPARATOR.'attachments');
            File::ensureDirectoryExists($directory);

            $extension = pathinfo($originalFilename, PATHINFO_EXTENSION);
            $storedName = sprintf(
                '%d-%s-%s%s',
                $entry->getKey(),
                now()->format('YmdHis'),
                Str::random(12),
                $extension !== '' ? '.'.$extension : '',
            );
            $destination = $directory.DIRECTORY_SEPARATOR.$storedName;

            File::copy($sourcePath, $destination);

            // Computed on the STORED copy, after the copy completes - the
            // hash describes the file this Action is responsible for, not
            // the caller's transient upload, which may be a tmp file the
            // caller deletes right after this call returns.
            $sha256 = (string) hash_file('sha256', $destination);
            $byteSize = (int) File::size($destination);

            $attachment = JournalEntryAttachment::query()->create([
                'journal_entry_id' => $entry->getKey(),
                'document_type' => $documentType,
                'file_path' => $destination,
                'sha256' => $sha256,
                'original_filename' => $originalFilename,
                'byte_size' => $byteSize,
                'uploaded_by' => $actor->id,
                'uploaded_at' => now(),
                'is_generated' => $isGenerated,
            ]);

            $entry->increment('attachment_count');

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Accounting',
                auditableType: JournalEntryAttachment::class,
                auditableId: (int) $attachment->getKey(),
                after: [
                    'journal_entry_id' => $entry->getKey(),
                    'document_type' => $documentType,
                    'sha256' => $sha256,
                    'byte_size' => $byteSize,
                ],
                actor: $actor,
            );

            return $attachment->refresh();
        });
    }

    /** L15 contract helper - see class docblock. */
    public static function hasAttachment(int $journalEntryId): bool
    {
        return JournalEntryAttachment::query()->where('journal_entry_id', $journalEntryId)->exists();
    }
}
