<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.2/4.5.
 *
 * `payload_snapshot` (2026_08_13_320001) froze the PAYLOAD, but only for
 * receipt-pattern templates - those with no registered SnapshotSourceMap
 * entry. For a REGISTERED source (today: RPT-CARD -> report_card_snapshots)
 * the payload is immutable by construction, so it is deliberately left NULL.
 *
 * That leaves the other two render inputs unfrozen for exactly those
 * templates: the school CHROME (letterhead, crest, ministry headers, fiscal
 * line) and the SUBJECT LABEL (the pupil's name and the period's name, which
 * PrintReportCard re-derives live). Both are rendered into the hashed bytes,
 * so renaming an assessment period or a student made every report card
 * issued under the old name permanently unprintable - a 422 with no way back.
 * Confirmed on two live documents.
 *
 * This column freezes that envelope at issue and the reprint path reads it
 * back, exactly as payload_snapshot already does for the payload. Nullable
 * because documents issued before it existed have none; those are backfilled
 * on their next successful reprint, and recovered by
 * `php artisan opes:documents:repair-envelope` where they are already stuck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->json('render_envelope')->nullable()->after('payload_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn('render_envelope');
        });
    }
};
