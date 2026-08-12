<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Controllers;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Photographs for the portal - a child's, and the guardian's own.
 *
 * This is a NEW place a child's image can be fetched from, so it is worth
 * being explicit about what guards it:
 *
 *   a child's photo   requires matrix row 1 for THAT child. Row 1 is the
 *                     floor every valid link carries, which is right: a parent
 *                     who may know their child exists may see their face. A
 *                     guardian with no link gets 404, never 403, because
 *                     row 32 makes the child's existence itself a guarded
 *                     fact.
 *   the own photo     requires only an active portal principal. It is theirs.
 *
 * There is no id-guessing hole: the child's id is checked against the caller's
 * own links before anything is read, so an unlinked id is indistinguishable
 * from a nonexistent one.
 *
 * `private, no-store` because a school photograph of a minor must not sit in a
 * shared proxy or a CDN edge. That costs a round trip per page and is the
 * right trade.
 */
final class PortalPhotoController
{
    public function __construct(private readonly GuardianPortalPolicy $policy)
    {
    }

    /** A child's photograph - row 1. */
    public function child(int $student): StreamedResponse
    {
        if (! $this->policy->allows(GuardianCapability::R01ViewChildIdentity, $student)) {
            abort(404);
        }

        $path = DB::table('students')->where('id', $student)->value('photo_path');

        return $this->stream(is_string($path) ? $path : null);
    }

    /** The signed-in guardian's own photograph. */
    public function self(): StreamedResponse
    {
        $context = PortalContext::current();

        if ($context === null) {
            abort(403);
        }

        $path = $context->guardian->photo_path;

        return $this->stream(is_string($path) ? $path : null);
    }

    private function stream(?string $path): StreamedResponse
    {
        if ($path === null || $path === '') {
            abort(404);
        }

        $disk = Storage::disk((string) config('filesystems.default'));

        if (! $disk->exists($path)) {
            // The row names a file the disk does not have. 404 rather than a
            // 500: a missing photo is not an error worth an alert, and the
            // avatar falls back to initials either way.
            abort(404);
        }

        return $disk->response($path, null, [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
