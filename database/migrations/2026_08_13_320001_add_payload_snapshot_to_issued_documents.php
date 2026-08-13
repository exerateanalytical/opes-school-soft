<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * docs/specs/10-documents.md 4.2/4.4 promises "the immutable payload it
 * renders from... It NEVER re-queries live tables" - true only for
 * templates with a registered SnapshotSourceMap entry. "Receipt pattern"
 * templates (FEE-RECEIPT, FEE-RECEIPT-POS, FEE-INVOICE, WHT-CERT,
 * PAY-VOUCHER) had no registered source, so RenderDocument re-derived their
 * payload from live joins (students/suppliers/payment_allocations) on every
 * render, including reprints. A legitimate later correction (a misspelled
 * name fixed, a payment re-allocated) then permanently broke that
 * document's reprintability with a DocumentReproducibilityViolation crash
 * instead of reproducing history. This column freezes the payload actually
 * rendered at issue so a reprint reads it back instead of re-deriving it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->json('payload_snapshot')->nullable()->after('snapshot_id');
        });
    }

    public function down(): void
    {
        Schema::table('issued_documents', function (Blueprint $table): void {
            $table->dropColumn('payload_snapshot');
        });
    }
};
