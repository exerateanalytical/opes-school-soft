<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §7 - registers the front-desk student document
 * set (ADM-FORM, STU-INFO, TRANSFER-CERT, LEAVING-CERT, CHAR-CERT,
 * TESTIMONIAL, BONAFIDE, ATTEND-CERT) and the §4.3 series they draw on
 * (ADM, TC, LC, CHAR, BON). Follows the 310010/330001 seed migrations' shape.
 *
 * Series formats: §4.3's table shows every example with a year segment, but
 * §4.3's own validation rule is explicit that "a template using {year} with
 * scope = global fails validation" - so the three GLOBAL-counter series
 * (TC, LC, CHAR) carry formats WITHOUT {year}, while the academic-year
 * series (ADM, BON) keep it. The rule wins over the illustration.
 *
 * Snapshot policy (§4.2): the six certificates are snapshot-backed under the
 * RECEIPT PATTERN (snapshot_source = NULL, no SnapshotSourceMap entry): the
 * owning Action assembles the payload from the enrollment/attendance/
 * discipline rows, RenderDocument freezes it onto
 * issued_documents.payload_snapshot at issue, and the content-hash compare
 * on reprint holds the Action to determinism. ADM-FORM and STU-INFO are
 * LIVE working sheets - reprinting after a data change is expected and the
 * "Generated on" footer says so.
 *
 * ADM-FORM carries series_code = NULL even though §7.1 names the ADM series:
 * the series number belongs to a SUBMITTED admission (the future Admission
 * Register entry), and RenderDocument only allocates serials on the
 * snapshot-backed path - a blank/live counter copy is unnumbered by design
 * ("blank copies unnumbered"). The ADM series row is still seeded so the
 * register can adopt it without a further migration, exactly like RCPT/INV
 * in the 310010 seed.
 *
 * §2.2 compliance: no template here carries a ministry seal slot, a
 * forbidden signature role (the §2.3 allow-list is enforced by
 * DocumentTemplate on save and the roles below are all allow-listed), a
 * national-credential field, or a security-features legend. The state
 * header is the TEXT-ONLY §2.1 block.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_series')->insert([
            [
                'code' => 'ADM',
                'format' => '{school}/{year}/ADM/{serial:6}',
                'scope' => 'academic_year',
                'reset_policy' => 'per_academic_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'TC',
                'format' => '{school}/TC/{serial:6}',
                'scope' => 'global',
                'reset_policy' => 'never',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'code' => 'LC',
                'format' => '{school}/LC/{serial:6}',
                'scope' => 'global',
                'reset_policy' => 'never',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                // §7.8 + §7.9 share this counter: a Character Certificate and
                // a Testimonial are the same "conduct attestation" register.
                'code' => 'CHAR',
                'format' => '{school}/CHAR/{serial:6}',
                'scope' => 'global',
                'reset_policy' => 'never',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                // §7.10 + §7.11 share BON, per the §4.3 table.
                'code' => 'BON',
                'format' => '{school}/{year}/BON/{serial:6}',
                'scope' => 'academic_year',
                'reset_policy' => 'per_academic_year',
                'padding' => 6,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $template = static fn (array $overrides): array => array_merge([
            'module' => 'Students',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => true,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            // §2.1: real Cameroonian school certificates sit under the
            // bilingual state letterhead (text only, never an emblem).
            'state_header' => 'default_on',
            'min_phase' => 'v1',
            'bulk_printable' => false,
            'version' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        DB::table('document_templates')->insert([
            $template([
                'code' => 'ADM-FORM',
                'name' => 'Admission Form',
                'name_fr' => "Fiche d'admission",
                'is_snapshot_backed' => false,
                'state_header' => 'optional',
                'signature_roles' => json_encode(['guardian', 'registrar'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.admission-form',
            ]),
            $template([
                'code' => 'STU-INFO',
                'name' => 'Student Information Sheet',
                'name_fr' => 'Fiche de renseignements',
                'is_snapshot_backed' => false,
                'state_header' => 'optional',
                // §7.2: the guardian signs to VERIFY the printed data.
                'signature_roles' => json_encode(['guardian'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.info-sheet',
            ]),
            $template([
                'code' => 'TRANSFER-CERT',
                'name' => 'Transfer Certificate',
                'name_fr' => 'Certificat de transfert',
                'series_code' => 'TC',
                'signature_roles' => json_encode(['principal'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.transfer-certificate',
            ]),
            $template([
                'code' => 'LEAVING-CERT',
                'name' => 'School Leaving Certificate',
                'name_fr' => 'Certificat de fin de scolarité',
                'series_code' => 'LC',
                'signature_roles' => json_encode(['principal'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.leaving-certificate',
            ]),
            $template([
                'code' => 'CHAR-CERT',
                'name' => 'Character Certificate',
                'name_fr' => 'Certificat de bonne conduite',
                'series_code' => 'CHAR',
                'signature_roles' => json_encode(['discipline_master', 'principal'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.character-certificate',
            ]),
            $template([
                'code' => 'TESTIMONIAL',
                'name' => 'Testimonial',
                'name_fr' => 'Attestation de scolarité et de conduite',
                'series_code' => 'CHAR',
                'signature_roles' => json_encode(['principal'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.testimonial',
            ]),
            $template([
                'code' => 'BONAFIDE',
                'name' => 'Bonafide Student Certificate',
                'name_fr' => "Attestation d'inscription",
                'series_code' => 'BON',
                'signature_roles' => json_encode(['registrar'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.bonafide',
            ]),
            $template([
                'code' => 'ATTEND-CERT',
                'name' => 'Attestation of Attendance',
                'name_fr' => 'Attestation de présence',
                'series_code' => 'BON',
                'signature_roles' => json_encode(['registrar'], JSON_THROW_ON_ERROR),
                'blade_view' => 'documents.students.attendance-certificate',
            ]),
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->whereIn('code', [
            'ADM-FORM', 'STU-INFO', 'TRANSFER-CERT', 'LEAVING-CERT',
            'CHAR-CERT', 'TESTIMONIAL', 'BONAFIDE', 'ATTEND-CERT',
        ])->delete();

        DB::table('document_series')->whereIn('code', ['ADM', 'TC', 'LC', 'CHAR', 'BON'])->delete();
    }
};
