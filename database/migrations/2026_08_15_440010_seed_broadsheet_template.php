<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §6.3 - the class broadsheet: every student down
 * the page, every subject across it, one row per child.
 *
 * A3 LANDSCAPE, which the spec states explicitly ("A3 landscape variant for
 * wide per-sequence francophone bulletins", §6.3) and which A4 genuinely
 * cannot do: a francophone secondary class carries 12-16 subjects plus
 * coefficients, and on A4 those columns fall below the width at which a
 * printed figure can be read.
 *
 * A3 has been a PaperSize case since the platform shipped and was used by
 * NOTHING; this is the document it was defined for.
 *
 * Registered LIVE rather than snapshot-backed. §6.3 says the broadsheet is
 * snapshot-backed once the period is published and live before it, but the
 * snapshot half needs an Assessment Action that assembles a broadsheet
 * payload from a published ReportCardSnapshot set, and that does not exist.
 * Registering it live is the honest half: the working sheet a class master
 * reprints while marks change, carrying the "Generated on ... by ..." footer
 * that says exactly that (§4.2). The archival record of a period's marks is
 * the report card snapshot (§6.1), which IS snapshot-backed and already
 * registered. Flipping this row to snapshot-backed is a one-line migration
 * the day that Action lands.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_templates')->insert([
            'code' => 'BROADSHEET',
            'name' => 'Class Broadsheet',
            'name_fr' => 'Tableau de notes de la classe',
            'module' => 'Assessment',
            'paper_size' => 'A3',
            'orientation' => 'landscape',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => false,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            'signature_roles' => json_encode(['class_master', 'principal'], JSON_THROW_ON_ERROR),
            'state_header' => 'optional',
            'min_phase' => 'v1',
            'bulk_printable' => true,
            'blade_view' => 'documents.assessment.broadsheet',
            'version' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->where('code', 'BROADSHEET')->delete();
    }
};
