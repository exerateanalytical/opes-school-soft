<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The asset label set, following the 310010 / 430001 seed migrations' shape.
 *
 * CR80 (85.60 x 53.98 mm, the ID-card blank) has been a PaperSize case since
 * the platform shipped and was used by NOTHING. A stick-on asset label is
 * exactly the artefact it was defined for, and it is the size the label
 * printers a school already owns are loaded with.
 *
 * LIVE, not snapshot-backed, and NO series: a label is a working artefact -
 * you print another when one peels off a projector - not a certificate.
 * Burning a serial per label would put a gap in a statutory counter every
 * time a store keeper reprints a sticker, and there is nothing about a
 * sticker that needs to be reproducible byte-for-byte years later.
 *
 * ASSET-LABEL-SHEET is the stock-take variant: N labels tiled on one A4, so
 * a school with an ordinary office printer and a sheet of blank labels can
 * do a whole store room in one pass.
 *
 * state_header = 'none' on both: the bilingual ministry block is for
 * statutory documents. On a 54 mm sticker it would consume the whole label
 * and say nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_templates')->insert([
            [
                'code' => 'ASSET-LABEL',
                'name' => 'Asset Label',
                'name_fr' => "Étiquette d'immobilisation",
                'module' => 'Assets',
                'paper_size' => 'CR80',
                'orientation' => 'landscape',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => false,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => true,
                // Nobody signs a sticker; a signature line on one is theatre.
                'signature_roles' => json_encode([], JSON_THROW_ON_ERROR),
                'state_header' => 'none',
                'min_phase' => 'v1',
                'bulk_printable' => false,
                'blade_view' => 'documents.assets.label',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'ASSET-LABEL-SHEET',
                'name' => 'Asset Label Sheet',
                'name_fr' => "Planche d'étiquettes d'immobilisation",
                'module' => 'Assets',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => null,
                'is_snapshot_backed' => false,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => true,
                'signature_roles' => json_encode([], JSON_THROW_ON_ERROR),
                'state_header' => 'none',
                'min_phase' => 'v1',
                'bulk_printable' => true,
                'blade_view' => 'documents.assets.label-sheet',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->whereIn('code', ['ASSET-LABEL', 'ASSET-LABEL-SHEET'])->delete();
    }
};
