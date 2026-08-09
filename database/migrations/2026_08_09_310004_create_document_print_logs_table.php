<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.4 / 00-core 14 - EVERY render, issued or
 * live, leaves a row here. Never cascade-deleted (00-core 10.5); for
 * accounting-bearing documents the 10-year AUDCIF retention applies and hard
 * delete is refused by the global observer.
 *
 * is_duplicate is DERIVED - count of prior successful prints of the same
 * IssuedDocument, computed inside the render transaction, never passed in by
 * the caller (4.5). true => the DUPLICATA watermark rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_print_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_template_id');
            $table->unsignedInteger('template_version');

            // Null for live documents - they have no issued row, only prints.
            $table->unsignedBigInteger('issued_document_id')->nullable();

            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            // Denormalised: "Report card for AZEMKEU Brice, Form 1A" must
            // survive the student being renamed or archived.
            $table->string('subject_label_at_time', 200);

            $table->unsignedInteger('snapshot_version')->nullable();

            $table->boolean('is_duplicate');
            $table->unsignedInteger('copy_no')->default(1);

            $table->enum('language', ['en', 'fr']);
            $table->enum('paper_size', ['A4', 'A5', 'A3', 'CR80', 'LETTER']);

            // FK added by 310005 - bulk_print_jobs does not exist yet at
            // this point in the run (pre-assigned filenames).
            $table->unsignedBigInteger('bulk_print_job_id')->nullable();

            $table->unsignedBigInteger('printed_by');
            $table->string('actor_name_at_time', 160);
            $table->dateTime('printed_at');

            // VARCHAR(45): fits a full IPv6 textual address.
            $table->string('ip', 45)->nullable();

            $table->timestamps();

            $table->index(
                ['subject_type', 'subject_id', 'printed_at'],
                'idx_print_logs_subject_printed',
            );
            $table->index('bulk_print_job_id', 'idx_print_logs_bulk_job');
            $table->index('issued_document_id', 'idx_print_logs_issued_document');

            $table->foreign('document_template_id', 'fk_print_logs_template')
                ->references('id')->on('document_templates')->restrictOnDelete();
            $table->foreign('issued_document_id', 'fk_print_logs_issued_document')
                ->references('id')->on('issued_documents')->restrictOnDelete();
            $table->foreign('printed_by', 'fk_print_logs_printed_by')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_print_logs');
    }
};
