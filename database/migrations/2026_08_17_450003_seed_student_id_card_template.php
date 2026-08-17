<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §12.1 - ID-STU, Student ID Card / Carte
 * d'élève. CR80, landscape, double-sided (front+back in one Blade view,
 * split with a CSS page break - dompdf paginates the single render), series
 * `CARD` scoped per academic year, snapshot-backed (receipt pattern: no
 * SnapshotSourceMap entry - the owning Action would freeze the payload the
 * same way GATE-PASS's does).
 *
 * ⚠ Deviation D3 (§3, §2.2.1): the reference mockups carry a national coat
 * of arms and a ministry seal. Both are forbidden. This template's view
 * carries ONLY the school crest (school_header block) - there is no field
 * in the payload or the view for a State emblem, so the branding uploader
 * has no slot capable of holding one.
 *
 * Blood group: §12.1 gates it on `SchoolProfile.id_card_show_blood_group`.
 * That column does not exist anywhere in this codebase (grepped for it -
 * zero hits outside the spec). Per instruction, rather than inventing a new
 * setting the card OMITS blood group unconditionally; the view has no
 * blood-group field at all.
 *
 * QR: carries_qr = true, but RenderDocument's own pipeline still hard-codes
 * qr_token to null pending "D2 wires the OPES1 signing stack" (see its
 * issueOriginal() comment) - this migration/view does not touch that. The
 * qr_block partial already refuses to render anything until a token exists,
 * so the card's QR area is blank until D2 lands; the render test below
 * still asserts the rendered HTML carries no student PII, which holds
 * whether or not a token is present.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_series')->insert([
            'code' => 'CARD',
            'format' => '{school}/{year}/CARD/{serial:6}',
            'scope' => 'academic_year',
            'reset_policy' => 'per_academic_year',
            'padding' => 6,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('document_templates')->insert([
            'code' => 'ID-STU',
            'name' => 'Student ID Card',
            'name_fr' => "Carte d'élève",
            'module' => 'Students',
            'paper_size' => 'CR80',
            'orientation' => 'landscape',
            'duplex' => 'double_sided',
            'series_code' => 'CARD',
            'is_snapshot_backed' => true,
            'snapshot_source' => null,
            'carries_qr' => true,
            'carries_barcode' => true,
            'state_header' => 'none',
            'signature_roles' => json_encode(['registrar'], JSON_THROW_ON_ERROR),
            'min_phase' => 'v1',
            'bulk_printable' => true,
            'version' => 1,
            'is_active' => true,
            'blade_view' => 'documents.students.id-card',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->where('code', 'ID-STU')->delete();
        DB::table('document_series')->where('code', 'CARD')->delete();
    }
};
