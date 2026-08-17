<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Controllers;

use App\Modules\Guardians\Models\Guardian;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A guardian's photograph, for STAFF.
 *
 * The portal has its own endpoint (PortalPhotoController) because it answers
 * to the guardian scope matrix; this one answers to the staff permission set,
 * and reads the same private-disk path. It exists at all because a
 * `photo_path` on the private default disk has no URL of its own - without a
 * policy-checked controller the Guardian Profile could only ever draw
 * initials, which is precisely where this screen was before.
 *
 * Gated in routes/web.php on `students.view`, matching guardians.show: whoever
 * may read the guardian record may see the face on it. Staff releasing a child
 * at the gate are the reason the face matters.
 *
 * `private, no-store` for the same reason the portal endpoint uses it - a
 * photograph of an identified person must not sit in a shared proxy or a CDN
 * edge.
 */
final class GuardianPhotoController
{
    public function __invoke(Guardian $guardian): StreamedResponse
    {
        $path = $guardian->photo_path;

        if ($path === null || $path === '') {
            abort(404);
        }

        $disk = Storage::disk((string) config('filesystems.default'));

        if (! $disk->exists($path)) {
            // The row names a file the disk does not have. 404 rather than a
            // 500: the avatar falls back to initials either way.
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
