<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 18.2 - a queued bulk print run.
 *
 * Per-subject transactional: each document is its own transaction (series
 * allocation + IssuedDocument + print log), so one failure marks that
 * subject failed and the job `partial` - it never rolls back the successes
 * and never burns the batch. Re-running a partial job in `unprinted` mode
 * picks up exactly the failures.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bulk_print_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_template_id');
            $table->unsignedInteger('template_version');

            $table->unsignedBigInteger('academic_year_id');
            $table->unsignedBigInteger('class_group_id')->nullable();
            $table->unsignedBigInteger('assessment_period_id')->nullable();

            $table->enum('mode', ['all', 'unprinted', 'selected']);

            // Subject ids, mode=selected only.
            $table->json('subject_ids')->nullable();

            $table->enum('language', ['en', 'fr']);
            $table->enum('paper_size', ['A4', 'A5', 'A3', 'CR80', 'LETTER']);
            $table->unsignedSmallInteger('copies')->default(1);
            $table->boolean('collate')->default(true);
            $table->enum('duplex', ['none', 'double_sided'])->default('none');

            $table->enum('status', ['queued', 'running', 'completed', 'failed', 'partial'])
                ->default('queued');

            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('succeeded')->default(0);
            $table->unsignedInteger('failed')->default(0);

            // The one merged PDF; per-subject files live beside it where
            // requested.
            $table->string('output_path', 255)->nullable();

            $table->unsignedBigInteger('requested_by');
            $table->dateTime('requested_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'requested_at'], 'idx_bulk_print_jobs_status');

            $table->foreign('document_template_id', 'fk_bulk_print_jobs_template')
                ->references('id')->on('document_templates')->restrictOnDelete();
            $table->foreign('academic_year_id', 'fk_bulk_print_jobs_year')
                ->references('id')->on('academic_years')->restrictOnDelete();
            $table->foreign('class_group_id', 'fk_bulk_print_jobs_class_group')
                ->references('id')->on('class_groups')->restrictOnDelete();
            $table->foreign('assessment_period_id', 'fk_bulk_print_jobs_period')
                ->references('id')->on('assessment_periods')->restrictOnDelete();
            $table->foreign('requested_by', 'fk_bulk_print_jobs_requested_by')
                ->references('id')->on('users')->restrictOnDelete();
        });

        // The print log's job FK, deferred from 310004 because this table
        // did not exist yet (pre-assigned filenames). RESTRICT - "who printed
        // the whole class's cards on 12 April" must stay one query forever.
        Schema::table('document_print_logs', function (Blueprint $table): void {
            $table->foreign('bulk_print_job_id', 'fk_print_logs_bulk_job')
                ->references('id')->on('bulk_print_jobs')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_print_logs', function (Blueprint $table): void {
            $table->dropForeign('fk_print_logs_bulk_job');
        });

        Schema::dropIfExists('bulk_print_jobs');
    }
};
