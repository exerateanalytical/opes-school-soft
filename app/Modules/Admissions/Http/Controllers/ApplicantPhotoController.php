<?php

declare(strict_types=1);

namespace App\Modules\Admissions\Http\Controllers;

use App\Modules\Admissions\Models\AdmissionApplication;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An applicant's photograph.
 *
 * `admission_applications.photo_path` is a PRIVATE-disk path (SetApplicantPhoto)
 * because it is a photograph of a CHILD, so it has no URL of its own; this is
 * the policy-checked way back to the bytes, and the wizard's thumbnail points
 * here.
 *
 * Gated in routes/web.php on `admissions.manage` - the SAME right the wizard
 * screen and SetApplicantPhoto already require, so the endpoint can be reached
 * by exactly the people who could already see the photo on the page. There is
 * no `admissions.view`; inventing one would be a gate no role grants.
 *
 * `private, no-store` because a photograph of a minor must not sit in a shared
 * proxy or a CDN edge.
 */
final class ApplicantPhotoController
{
    public function __invoke(AdmissionApplication $application): StreamedResponse
    {
        $path = $application->photo_path;

        if ($path === null || $path === '') {
            abort(404);
        }

        $disk = Storage::disk((string) config('filesystems.default'));

        if (! $disk->exists($path)) {
            // The row names a file the disk does not have. 404 rather than a
            // 500: the thumbnail falls back to the empty placeholder either way.
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
