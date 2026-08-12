<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Controllers;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildDocuments;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `/portal/children/{student}/documents/{kind}/{document}/download` -
 * 07-students.md 7.5 rows 22 and 23.
 *
 * A controller rather than a Livewire component because this returns BYTES.
 * Livewire renders HTML over the wire; a file download has to be an ordinary
 * HTTP response.
 *
 * Only row 23 yields bytes. A school-issued document (row 22) has no file to
 * send - the only path to a signed PDF is RenderDocument, gated on the staff
 * permission `documents.print` - so that kind redirects back to the documents
 * screen, which shows the verification code the school actually honours.
 * ChildDocuments' docblock is the long version.
 *
 * `kind` is part of the path rather than inferred from the id, so the
 * capability checked is never chosen by the data: the two shelves are separate
 * id spaces, and a `supplied` id must not be reachable through the row-22
 * grant.
 */
final class PortalDocumentController
{
    public function __construct(
        private readonly GuardianPortalPolicy $policy,
        private readonly ChildDocuments $documents,
    ) {
    }

    public function download(int $student, string $kind, int $document): StreamedResponse|RedirectResponse
    {
        // Row 32 first: an unlinked child yields "no such thing", never a hint.
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }

        if ($kind === 'school') {
            $this->policy->authorize(GuardianCapability::R22ViewSchoolIssuedDocuments, $student);

            // Nothing to stream. The list screen carries the verification code,
            // which is the artefact the school hands over.
            return redirect()
                ->route('portal.children.documents', $student)
                ->with('portal-status', __('opes.guardian_portal.documents_download_note'));
        }

        if ($kind !== 'supplied') {
            abort(404);
        }

        $this->policy->authorize(GuardianCapability::R23ViewGuardianSuppliedDocuments, $student);

        $file = $this->documents->suppliedFile($student, $document);

        if ($file === null) {
            abort(404);
        }

        $disk = Storage::disk((string) config('filesystems.default'));

        if (! $disk->exists($file->file_path)) {
            // The row exists but its bytes do not - a storage problem, not an
            // authorization one. Say so rather than 404, which would imply the
            // document was never there.
            return redirect()
                ->route('portal.children.documents', $student)
                ->with('portal-status', __('opes.guardian_portal.documents_missing_file'));
        }

        // `private, no-store` per spec §4.2: a child's paperwork must not sit
        // in a shared proxy.
        return $disk->download($file->file_path, $file->title, [
            'Content-Type' => $file->mime,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
