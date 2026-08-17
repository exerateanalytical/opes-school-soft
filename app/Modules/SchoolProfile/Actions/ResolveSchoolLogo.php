<?php

declare(strict_types=1);

namespace App\Modules\SchoolProfile\Actions;

use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * THE canonical school logo, for every surface that shows one: the app-shell
 * sidebar, the sign-in page, the guardian portal header, and the letterhead
 * of newly issued documents.
 *
 * Before this class there were two independent logo stores and three
 * independent marks. A school uploaded its logo on /settings/branding, saw it
 * appear in the sidebar, and then found the built-in OPES crest still on the
 * sign-in page its parents actually look at and a different logo on its
 * report cards. One upload, one logo, everywhere.
 *
 * PRECEDENCE - `branding.app_logo_path` FIRST, then the document profile's
 * `logo_path`. Deliberately that way round, and not the reverse:
 *
 *   - /settings/branding is the screen the platform presents as "your logo",
 *     and its copy now says it is used platform-wide. If the document logo
 *     won, uploading there would visibly fail to change documents for exactly
 *     the schools that had configured both - a setting that silently does
 *     nothing is worse than no setting.
 *   - The document `logo_path` predates the branding screen. Ranking it
 *     second keeps it as the fallback for installs that only ever configured
 *     School Identity: they see no change at all, because the first source is
 *     empty for them.
 *   - So the only schools whose documents change are those that later upload
 *     a platform logo, which is the change they asked for.
 *
 * THE CREST IS NOT PART OF THIS. `crest_path` stays its own field. On a
 * Cameroon school document the crest and the logo are two different marks
 * printed in two different places, and collapsing them would be a content
 * error, not a simplification.
 *
 * NOTHING HERE TOUCHES AN ISSUED DOCUMENT. RenderDocument freezes the chrome
 * onto every document at issue and reprints from the frozen copy; this
 * resolver is reached only from the LIVE read, so changing the logo can never
 * alter what an already-issued document reprints to.
 */
final class ResolveSchoolLogo
{
    public const SETTING_KEY = 'branding.app_logo_path';

    /**
     * The canonical logo as a relative path on the `public` disk, or null when
     * the school has uploaded nothing - in which case every caller falls back
     * to the built-in mark it already draws.
     */
    public function handle(): ?string
    {
        return $this->normalise($this->fromSetting())
            ?? $this->normalise($this->fromDocumentProfile());
    }

    /** The canonical logo as a browser-facing URL, or null. */
    public function url(): ?string
    {
        $path = $this->handle();

        return $path === null ? null : Storage::disk(StoredImage::DISK)->url($path);
    }

    private function fromSetting(): ?string
    {
        try {
            $value = app(ReadSetting::class)->handle(self::SETTING_KEY, '');
        } catch (Throwable) {
            // The shell renders on installs whose settings table does not
            // exist yet (a fresh clone, a migration in flight). A missing
            // logo must never be a 500 on the sign-in page.
            return null;
        }

        return is_string($value) ? $value : null;
    }

    private function fromDocumentProfile(): ?string
    {
        try {
            $profile = DB::table('school_document_profiles')->where('id', 1)->first();
        } catch (Throwable) {
            return null;
        }

        $path = $profile->logo_path ?? null;

        return is_string($path) ? $path : null;
    }

    /**
     * Only ever a relative path under branding/ on the `public` disk - the
     * uploader's own contract. Both sources are operator-editable text, and
     * without this guard a hand-typed value could become an arbitrary
     * <img src>. Mirrors EmbeddedImage's guard for the document side.
     */
    private function normalise(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (! str_starts_with($path, StoredImage::DIRECTORY.'/') || str_contains($path, '..')) {
            return null;
        }

        return $path;
    }
}
