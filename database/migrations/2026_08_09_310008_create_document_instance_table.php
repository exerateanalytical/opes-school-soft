<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 17.1 - the QR token's `i` field: "instance UUID
 * (which school issued it)". Nothing in the schema identified an
 * installation until now (licensing fingerprints the MACHINE, which changes
 * on a hardware swap - a document must verify forever), so the document
 * platform mints one durable UUID per instance, lazily on first signing via
 * Reporting\Actions\ResolveInstanceUuid, never in a migration (a seeded
 * value would be identical across every school restoring the same dump).
 *
 * Singleton by CHECK constraint, same pattern as school_document_profiles
 * (310007): the only legal id is 1, so a second row is a database error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_instance', function (Blueprint $table): void {
            // NOT auto-increment: MySQL refuses a CHECK on an auto-increment
            // column. The row is always written with an explicit id = 1.
            $table->unsignedBigInteger('id')->primary();

            $table->char('uuid', 36)->unique();

            $table->timestamps();
        });

        DB::statement(
            'ALTER TABLE document_instance '
            .'ADD CONSTRAINT chk_document_instance_singleton CHECK (id = 1)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_instance');
    }
};
