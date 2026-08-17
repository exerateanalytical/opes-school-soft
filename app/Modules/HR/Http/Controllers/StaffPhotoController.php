<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A staff member's photograph.
 *
 * `staff_members.photo_path` is a PRIVATE-disk path (SetStaffPhoto), so it has
 * no URL of its own; this is the policy-checked way to read it. Without it the
 * staff directory could only ever draw initials.
 *
 * Gated in routes/web.php on `staff.view` - HrPermission::VIEW, the right the
 * register itself answers to. Whoever may read the dossier may see the face on
 * it. A photo-specific permission string would be a gate no role grants, which
 * is a control that silently refuses everyone.
 *
 * The row is read with DB::table rather than the StaffMember model only where
 * this controller is the HR module's own - it is, so the model would be fine;
 * the query builder is used anyway because a single column is all this needs.
 *
 * `private, no-store` for the same reason the guardian and portal endpoints use
 * it: a photograph of an identified person must not sit in a shared proxy or a
 * CDN edge.
 */
final class StaffPhotoController
{
    public function __invoke(int $staffMember): StreamedResponse
    {
        $path = DB::table('staff_members')->where('id', $staffMember)->value('photo_path');

        if (! is_string($path) || $path === '') {
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
