<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/specs/10-documents.md §11.3 - LEAVE-APP, Leave Application / Demande
 * de congé.
 *
 * No series (references StaffLeave/StudentLeave), applicant + hr_officer +
 * principal signatures, LIVE (re-renders current data, like ADM-FORM /
 * STU-INFO - see the 430001 seed migration's header for that pattern).
 *
 * SCOPE: only the STUDENT variant is seeded here, per §11.3's own text -
 * "the mockup is the student form" (Student Name, Class, Reason for Leave,
 * From/To, Parent/Guardian Signature). The spec's staff variant (leave type,
 * balance before/after, cover arrangements) needs `HR\Models\LeaveRequest`
 * fields this template does not carry and is deliberately deferred; there is
 * also no `StudentLeave` model anywhere in the codebase to back a persisted
 * student leave workflow; this is a BLANK-FORM live document exactly like
 * ADM-FORM, pre-filled from a Student's identity where one is given.
 *
 * signature_roles: 'guardian' stands in for §11.3's "applicant" on the
 * student variant (the guardian applies on the student's behalf), plus
 * hr_officer and principal as named.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('document_templates')->insert([
            'code' => 'LEAVE-APP',
            'name' => 'Leave Application',
            'name_fr' => 'Demande de congé',
            'module' => 'Students',
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'duplex' => 'none',
            'series_code' => null,
            'is_snapshot_backed' => false,
            'snapshot_source' => null,
            'carries_qr' => false,
            'carries_barcode' => false,
            'state_header' => 'optional',
            'signature_roles' => json_encode(['guardian', 'hr_officer', 'principal'], JSON_THROW_ON_ERROR),
            'min_phase' => 'v1',
            'bulk_printable' => false,
            'blade_view' => 'documents.students.leave-application',
            'version' => 1,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('document_templates')->where('code', 'LEAVE-APP')->delete();
    }
};
