<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Api;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Slice D - documents (docs/specs/2026-08-11-guardian-mobile-api-v1.md §4 rows
 * 10 and 11; 07-students.md 7.5 rows 22 and 23).
 *
 * Two shelves, two grants, and they are genuinely different things: row 22 is
 * what the SCHOOL issued about the child (a report card, an attestation - the
 * school's word, signed and serialised), row 23 is what a GUARDIAN supplied (a
 * birth certificate, a vaccination card - the family's paperwork). A link may
 * hold either, both or neither.
 *
 * Only row 23 yields bytes. ChildDocuments' docblock explains why at length:
 * the only path to a signed PDF is RenderDocument, which gates on the staff
 * permission `documents.print`, and duplicating it to dodge that gate is
 * exactly what the build plan's "never duplicate" rule forbids. A school-issued
 * document is therefore returned as a verification descriptor - the serial and
 * QR token that resolve at the public verify page, which is the artefact the
 * school actually hands over.
 */
final class DocumentsController
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ChildDocuments $documents,
    ) {
    }

    /** `GET /v1/me/children/{student}/documents` - rows 22 and 23. */
    public function index(int $student): JsonResponse
    {
        $this->requireChild($student);

        $canSchool = $this->policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $student);
        $canSupplied = $this->policy->allows(GuardianCapability::R23ViewGuardianSuppliedDocuments, $student);

        if (! $canSchool && ! $canSupplied) {
            abort(403);
        }

        $schoolIssued = $canSchool
            ? $this->documents->schoolIssued($student)
                ->map(fn (object $row): array => [
                    'id' => (int) $row->id,
                    'serial' => $row->serial === null ? null : (string) $row->serial,
                    'issued_at' => (string) $row->issued_at,
                    'language' => (string) $row->language,
                    'verification_code' => $row->serial === null ? null : (string) $row->serial,
                    'verify_url' => $this->verifyUrl($row->serial),
                    // Honest about the boundary rather than offering a link
                    // that would 501: the app renders a QR, not a download
                    // button, for this shelf.
                    'has_bytes' => false,
                ])->values()->all()
            : [];

        $guardianSupplied = $canSupplied
            ? $this->documents->guardianSupplied($student)
                ->map(static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'title' => (string) $row->title,
                    'issued_on' => $row->issued_on,
                    'expires_on' => $row->expires_on,
                    'verification_status' => (string) $row->verification_status,
                    'mime' => (string) $row->mime,
                    'size_bytes' => (int) $row->size_bytes,
                    'has_bytes' => true,
                ])->values()->all()
            : [];

        return response()->json([
            'data' => [
                'can_view_school_issued' => $canSchool,
                'can_view_guardian_supplied' => $canSupplied,
                'school_issued' => $schoolIssued,
                'guardian_supplied' => $guardianSupplied,
            ],
        ]);
    }

    /**
     * `GET /v1/me/children/{student}/documents/{kind}/{document}/download`
     * where `kind` is `school` or `supplied`.
     *
     * One route, two shelves, because the two ids live in different tables and
     * a single id space would let a `supplied` id be probed through the row-22
     * grant. The kind is part of the path, so the capability checked is never
     * inferred from the data.
     *
     * `Cache-Control: private, no-store` per spec §4.2: a child's paperwork
     * must not sit in a shared proxy.
     */
    public function download(int $student, string $kind, int $document): StreamedResponse|JsonResponse
    {
        $this->requireChild($student);

        if ($kind === 'school') {
            return $this->downloadSchoolIssued($student, $document);
        }

        if ($kind !== 'supplied') {
            abort(404);
        }

        if (! $this->policy->allows(GuardianCapability::R23ViewGuardianSuppliedDocuments, $student)) {
            abort(403);
        }

        $file = $this->documents->suppliedFile($student, $document);

        if ($file === null) {
            abort(404);
        }

        $disk = Storage::disk(config('filesystems.default'));

        if (! $disk->exists($file->file_path)) {
            // The row exists but its bytes do not - a storage problem, not an
            // authorization one, and the parent should be told so rather than
            // shown a 404 that implies the document was never there.
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'This document is no longer available. Please contact the school office.',
                    'details' => [],
                ],
            ], 404);
        }

        return $disk->download($file->file_path, $file->title, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Row 22 has no bytes to give. It gives the verification descriptor
     * instead, with a 200 - this is a successful answer to "what do I have",
     * not a failure to produce a file.
     */
    private function downloadSchoolIssued(int $student, int $document): JsonResponse
    {
        if (! $this->policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $student)) {
            abort(403);
        }

        $row = $this->documents->issuedDocument($student, $document);

        if ($row === null) {
            abort(404);
        }

        return response()->json([
            'data' => [
                'id' => $row->id,
                'serial' => $row->serial,
                'verification_code' => $row->serial,
                'verify_url' => $this->verifyUrl($row->serial),
                'qr_token' => $row->qr_token,
                'issued_at' => $row->issued_at,
                'language' => $row->language,
                'has_bytes' => false,
                'message' => 'Present this code at the school office, or scan it on the verification page.',
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    private function verifyUrl(?string $serial): ?string
    {
        return $serial === null ? null : url('/documents/verify?serial='.rawurlencode($serial));
    }

    /** Row 32: no valid link, no child - and no confirmation that one exists. */
    private function requireChild(int $student): void
    {
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }
    }
}
