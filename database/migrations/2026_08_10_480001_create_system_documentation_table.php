<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/02-accounting.md §14.4 - "documentation du système comptable".
 * AUDCIF requires an entity using computerised accounting to hold a
 * description of its accounting system and procedures.
 *
 * A hand-written document drifts from the software within one release, so
 * this is GENERATED from live configuration - the chart, journals, every
 * active posting rule with its version/condition/lines/effective dates,
 * analytic axes, sequence formats, period-locking configuration, the
 * year-end checklist template, accounting roles, and the software/schema
 * version. It cannot drift because nobody hand-writes it.
 *
 * Same immutable-register shape as `statutory_books` (§14.1): a
 * regeneration inserts a new row pointing at the one it supersedes, never
 * overwrites. A separate table rather than a `statutory_books` row,
 * because this is not one of the four AUDCIF Art. 19 books - it documents
 * the SYSTEM, not the ledger's movements.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_documentation_snapshots', function (Blueprint $table): void {
            $table->id();

            $table->dateTime('generated_at');
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();

            $table->string('software_version', 40);
            // A migration filename ("2026_08_10_480001_create_..."), not a
            // short version tag - runs well past 40 characters.
            $table->string('schema_version', 150);

            $table->string('file_path', 500);
            $table->char('sha256', 64);

            $table->foreignId('supersedes_id')->nullable()
                ->constrained('system_documentation_snapshots')->restrictOnDelete();

            $table->timestamps();

            $table->index('generated_at', 'ix_system_doc_generated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_documentation_snapshots');
    }
};
