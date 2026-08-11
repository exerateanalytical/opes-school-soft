<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two document shelves a guardian may reach - 07-students.md 7.5 rows 22
 * and 23 - read once for both doors (the /portal Livewire screen and the
 * mobile API), as docs/plans/2026-08-11-guardian-mobile-build.md §1 requires.
 *
 * Row 22 is what the SCHOOL issued about the child: Reporting's
 * `issued_documents` (10-documents §4.4), keyed on `subject_type` = the
 * Students module's own FQCN held here as a STRING, never imported
 * (tests/Architecture/ModuleBoundaryTest.php). Row 23 is what a GUARDIAN
 * supplied: `student_documents` (07-students §8.1).
 *
 * WHY NO BYTES FOR ROW 22.
 * 10-documents §4.8 makes RenderDocument "THE only path to a PDF", and its
 * first statement is `Gate::authorize(Permission::DocumentsPrint)` - a STAFF
 * permission the guardian role must never hold, for the same reason
 * ChildFeeStatement does not call Fees\Actions\StudentStatement. Forking that
 * Action to skip its gate would put a second, weaker path to a signed
 * document in the product, which §1 of the build plan forbids outright.
 *
 * So a school-issued document is returned as a VERIFICATION DESCRIPTOR, not a
 * file: the serial the parent can quote and the QR token that resolves at the
 * public /documents/verify page. That is the artefact the school actually
 * hands out, and it is verifiable without a byte ever leaving this API. A
 * guardian-facing render path is a Reporting-module decision (a template
 * registered for a student subject plus a guardian-scoped print permission),
 * not something this surface may improvise.
 *
 * Guardian-supplied documents DO have bytes - the guardian uploaded them - and
 * those stream, gated by row 23.
 */
final class ChildDocuments
{
    /**
     * The FQCN App\Modules\Students\Models\Student would resolve to, held as a
     * string so this class never imports another module's model.
     */
    public const STUDENT_SUBJECT_TYPE = 'App\\Modules\\Students\\Models\\Student';

    /**
     * Row 22 - documents the school issued about this child, valid ones only.
     * A revoked or superseded document is not "an older copy" to a parent, it
     * is a document that no longer says what it says.
     *
     * @return Collection<int, \stdClass>
     */
    public function schoolIssued(int $studentId): Collection
    {
        if (! Schema::hasTable('issued_documents')) {
            return collect();
        }

        return DB::table('issued_documents')
            ->where('subject_type', self::STUDENT_SUBJECT_TYPE)
            ->where('subject_id', $studentId)
            ->where('status', 'valid')
            ->orderByDesc('issued_at')
            ->get(['id', 'document_template_id', 'serial', 'issued_at', 'language', 'qr_token']);
    }

    /**
     * Row 23 - documents a guardian supplied for this child.
     *
     * @return Collection<int, \stdClass>
     */
    public function guardianSupplied(int $studentId): Collection
    {
        if (! Schema::hasTable('student_documents')) {
            return collect();
        }

        return DB::table('student_documents')
            ->where('student_id', $studentId)
            ->where('is_archived', false)
            ->orderByDesc('created_at')
            ->get([
                'id', 'title', 'issued_on', 'expires_on', 'verification_status',
                'created_at', 'mime', 'size_bytes',
            ]);
    }

    /**
     * One guardian-supplied document, keyed to its child so an id from another
     * student's shelf cannot be fetched by guessing. Null is the caller's
     * signal to answer 404, never 403 - row 32 again.
     */
    public function suppliedFile(int $studentId, int $documentId): ?\stdClass
    {
        if (! Schema::hasTable('student_documents')) {
            return null;
        }

        $row = DB::table('student_documents')
            ->where('id', $documentId)
            ->where('student_id', $studentId)
            ->where('is_archived', false)
            ->first(['id', 'title', 'file_path', 'mime', 'size_bytes']);

        return $row === null ? null : (object) [
            'id' => (int) $row->id,
            'title' => (string) $row->title,
            'file_path' => (string) $row->file_path,
            'mime' => (string) $row->mime,
            'size_bytes' => (int) $row->size_bytes,
        ];
    }

    /**
     * One school-issued document, keyed to its child for the same reason.
     */
    public function issuedDocument(int $studentId, int $documentId): ?\stdClass
    {
        if (! Schema::hasTable('issued_documents')) {
            return null;
        }

        $row = DB::table('issued_documents')
            ->where('id', $documentId)
            ->where('subject_type', self::STUDENT_SUBJECT_TYPE)
            ->where('subject_id', $studentId)
            ->where('status', 'valid')
            ->first(['id', 'serial', 'issued_at', 'language', 'qr_token']);

        return $row === null ? null : (object) [
            'id' => (int) $row->id,
            'serial' => $row->serial === null ? null : (string) $row->serial,
            'issued_at' => (string) $row->issued_at,
            'language' => (string) $row->language,
            'qr_token' => $row->qr_token === null ? null : (string) $row->qr_token,
        ];
    }
}
