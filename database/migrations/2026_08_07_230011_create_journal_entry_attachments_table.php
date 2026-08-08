<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §4.4 - pièces justificatives (AUDCIF Art. 17).
 *
 * NOT in this agent's pre-assigned migration list (only `create_letterings_
 * table` and `add_auxiliary_columns_to_journal_entry_lines` were
 * pre-assigned). It is added here anyway because this agent's task item 4
 * ("Attachments (L15)") explicitly specifies this table's columns, and
 * `app/Modules/Accounting/Models/JournalEntryAttachment.php` (already on
 * disk, built by D3) says outright in its docblock: "Model shell only ...
 * the journal_entry_attachments table is not among [D3's] two pre-assigned
 * migrations ... left for whichever later slice lands attachment
 * storage." That slice is this one - AttachDocument has nothing to write
 * to otherwise. Flagged in this agent's final report as a deliberate,
 * necessary exception to the stated file list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entry_attachments', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // §4.4: RESTRICT, matching every other accounting FK
            // (00-core §10.5) - an entry with attachments is never
            // deletable out from under them.
            $table->foreignId('journal_entry_id')->constrained('journal_entries')->restrictOnDelete();

            $table->string('document_type', 80);
            $table->string('file_path', 500);

            // sha256 hex digest, fixed width - CreateBackup's established
            // pattern (app/Modules/Operations/Actions/CreateBackup.php) for
            // "hash computed once, at write time, so tampering afterward is
            // detectable".
            $table->char('sha256', 64);

            $table->string('original_filename', 255);
            $table->unsignedBigInteger('byte_size');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('uploaded_at')->nullable();

            $table->boolean('is_generated')->default(false);

            $table->timestamps();

            $table->index('journal_entry_id', 'ix_jea_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entry_attachments');
    }
};
