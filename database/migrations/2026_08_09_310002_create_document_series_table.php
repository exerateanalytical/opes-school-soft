<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.3 - document series: the FORMAT and SCOPE of
 * every human-facing document number.
 *
 * Deliberately NO next_value column, although the spec sketch shows one: the
 * counter lives in the `sequences` table and is advanced only by
 * SequenceAllocator inside the render transaction (00-core 12) - the fully
 * scoped sequence key is `document.{code}.{scope discriminator}`, e.g.
 * `document.RCPT.2026` for a fiscal-year series. A second counter column
 * here would be a second numbering path, which is the exact bug the
 * allocator exists to make unavailable. All document series are
 * gaps-permitted (atomicity only) - unlike JournalEntry.piece_no.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 'RCPT', 'TRANS', 'COM'... Case-sensitive identifier (00-core 4).
            $table->string('code', 16)->collation('utf8mb4_0900_as_cs')->unique();

            // e.g. '{school}/{year}/{code}/{serial:6}'. {school} is
            // SchoolProfile short_code; {year} is the academic OR fiscal year
            // per the scope - a format using {year} with scope=global fails
            // validation in the Action (4.3).
            $table->string('format', 64);

            // The spec's entity sketch lists four scopes but its own series
            // catalogue needs two more: VIS resets per DAY and PAY per
            // PAYROLL MONTH (4.3 table). The enum holds the catalogue, not
            // the sketch.
            $table->enum('scope', [
                'global', 'academic_year', 'fiscal_year', 'section', 'day', 'payroll_month',
            ]);

            $table->enum('reset_policy', [
                'never', 'per_academic_year', 'per_fiscal_year', 'per_day', 'per_payroll_month',
            ]);

            $table->unsignedTinyInteger('padding')->default(6);

            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });

        // The template registry's series FK, deferred from 310001 because
        // this table did not exist yet (pre-assigned filenames). RESTRICT: a
        // series referenced by a template is not deletable.
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->foreign('series_code', 'fk_document_templates_series')
                ->references('code')->on('document_series')
                ->restrictOnDelete()->restrictOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table): void {
            $table->dropForeign('fk_document_templates_series');
        });

        Schema::dropIfExists('document_series');
    }
};
