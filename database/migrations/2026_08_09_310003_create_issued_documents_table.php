<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.4 - one row per document ISSUED
 * (snapshot-backed templates only; live documents leave only a print log).
 *
 * The composite UNIQUE at the bottom is the idempotency backstop: one
 * template x one subject x one snapshot is ONE issued document, and every
 * later render of it is a duplicate (4.5), never a second original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_documents', function (Blueprint $table): void {
            $table->bigIncrements('id');

            $table->unsignedBigInteger('document_template_id');

            // The template version RENDERED WITH, pinned at issue so a
            // reprint years later reproduces the artefact byte for byte.
            $table->unsignedInteger('template_version');

            // Nullable pair: statutory books (JRNL-BOOK et al.) are
            // snapshot-backed but carry no series (15). The rendered serial
            // string is UNIQUE globally whatever its series' counter scope
            // (4.3) - MySQL ignores NULLs in unique indexes, so any number
            // of series-less documents coexist.
            $table->string('series_code', 16)->collation('utf8mb4_0900_as_cs')->nullable();
            $table->string('serial', 64)->collation('utf8mb4_0900_as_cs')->nullable()->unique();

            // Student, Enrollment, Payment, PayrollItem...
            $table->string('subject_type', 60);
            $table->unsignedBigInteger('subject_id');

            // The immutable payload it renders from - e.g.
            // ReportCardSnapshot. It NEVER re-queries live tables (4.2).
            $table->string('snapshot_type', 60);
            $table->unsignedBigInteger('snapshot_id');

            $table->enum('language', ['en', 'fr']);

            // SHA-256 of the rendered PDF bytes. Recomputed and COMPARED on
            // every reprint; a mismatch is DocumentReproducibilityViolation,
            // never a silent print (4.5).
            $table->char('content_hash', 64);

            // The OPES1.<payload>.<sig> verification token (17). Null where
            // the template does not carry QR.
            $table->text('qr_token')->nullable();

            $table->unsignedBigInteger('issued_by');
            $table->dateTime('issued_at');

            // Denormalised on purpose: the certificate must forever say who
            // issued it even if the user is later renamed (00-core 10.5).
            $table->string('issued_by_name_at_time', 160);

            $table->enum('status', ['valid', 'revoked', 'superseded'])->default('valid');

            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->string('revoked_reason', 255)->nullable();

            // Set when an amendment reissues: the old card verifies as
            // SUPERSEDED and names the superseding serial (17.2).
            $table->unsignedBigInteger('superseded_by_document_id')->nullable();

            $table->timestamps();

            $table->unique(
                ['document_template_id', 'subject_type', 'subject_id', 'snapshot_id'],
                'uq_issued_documents_template_subject_snapshot',
            );
            $table->index(['subject_type', 'subject_id'], 'idx_issued_documents_subject');
            $table->index('status', 'idx_issued_documents_status');

            $table->foreign('document_template_id', 'fk_issued_documents_template')
                ->references('id')->on('document_templates')->restrictOnDelete();
            $table->foreign('series_code', 'fk_issued_documents_series')
                ->references('code')->on('document_series')
                ->restrictOnDelete()->restrictOnUpdate();
            $table->foreign('issued_by', 'fk_issued_documents_issued_by')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by', 'fk_issued_documents_revoked_by')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('superseded_by_document_id', 'fk_issued_documents_superseded_by')
                ->references('id')->on('issued_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_documents');
    }
};
