<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §6.1 (report card) and §11.1 (payslip) -
 * registers the two templates the rendering platform was built for but never
 * had a row for. Follows the 310010 seed migration's shape exactly.
 *
 * WHY these two DO carry a series_code, unlike the money templates 310010
 * seeded: the "receipt pattern" 310010 documents applies only where the
 * OWNING module already allocated the printed number (Fees' receipt_no,
 * Tax's attestation_no). Neither a bulletin nor a payslip has such a number -
 * `report_card_snapshots` and `payroll_items` carry ids, generations and
 * months, none of which is a document number a parent or an inspector can
 * quote. So RenderDocument allocates one here, from the sequence, inside the
 * render transaction (00-core §12), and there is no second counter to drift
 * against.
 *
 * RPT-CARD is snapshot-backed against a REGISTERED source
 * (Reporting\Domain\SnapshotSourceMap already maps `ReportCardSnapshot` ->
 * report_card_snapshots.payload/generation), so its payload is the published
 * snapshot itself and 01-assessment §13's re-render guarantee is the document
 * platform's byte-identity guarantee unchanged. It is deliberately NOT a live
 * template: a bulletin re-derived from `marks` months after publication is
 * exactly what 01-assessment T13 forbids.
 *
 * PAYSLIP is snapshot-backed with NO registered source - the receipt pattern:
 * the immutable "snapshot" is the approved run's `payroll_items` /
 * `payroll_lines` row set, and `Payroll\Actions\PrintPayslip` assembles the
 * payload from those rows. The content-hash comparison on reprint is what
 * holds that Action to determinism.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_series')->insert([
            [
                // 6.1: the bulletin is an academic-year artefact, and the
                // numbering resets with the year the school reports on, not
                // with the fiscal year the bursar reports on.
                'code' => 'RPT',
                'format' => '{school}/{year}/RPT/{serial:6}',
                'scope' => 'academic_year',
                'reset_policy' => 'per_academic_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                // 11.1: a payslip belongs to a PAYROLL MONTH; the scope
                // discriminator is '2026-10', which is also what {month}
                // renders (Reporting\Domain\SeriesFormatter).
                'code' => 'PSLIP',
                'format' => '{school}/{month}/PSLIP/{serial:6}',
                'scope' => 'payroll_month',
                'reset_policy' => 'per_payroll_month',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        DB::table('document_templates')->insert([
            [
                'code' => 'RPT-CARD',
                'name' => 'Report Card',
                'name_fr' => 'Bulletin de notes',
                'module' => 'Assessment',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => 'RPT',
                'is_snapshot_backed' => true,
                'snapshot_source' => 'ReportCardSnapshot',
                'carries_qr' => true,
                'carries_barcode' => false,
                // 2.1: the bulletin is the one school document a Cameroonian
                // reader expects under the bilingual state letterhead.
                'state_header' => 'default_on',
                'signature_roles' => json_encode(['class_master', 'principal'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => true,
                'blade_view' => 'documents.assessment.report-card',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'PAYSLIP',
                'name' => 'Payslip',
                'name_fr' => 'Bulletin de paie',
                'module' => 'Payroll',
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'duplex' => 'none',
                'series_code' => 'PSLIP',
                'is_snapshot_backed' => true,
                'snapshot_source' => null,
                'carries_qr' => false,
                'carries_barcode' => false,
                'state_header' => 'none',
                'signature_roles' => json_encode(['payroll_officer', 'staff'], JSON_THROW_ON_ERROR),
                'min_phase' => 'v1',
                'bulk_printable' => true,
                'blade_view' => 'documents.payroll.payslip',
                'version' => 1,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->whereIn('code', ['RPT-CARD', 'PAYSLIP'])->delete();
        DB::table('document_series')->whereIn('code', ['RPT', 'PSLIP'])->delete();
    }
};
