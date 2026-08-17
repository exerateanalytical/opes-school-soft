<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Branding images (crest, logo, signatures, stamp, app logo, favicon) on the
 * `public` disk, stored under a CONTENT-HASHED filename.
 *
 * The hash is not a cache-busting nicety - it is the whole reason this class
 * exists. RenderDocument freezes the school chrome, INCLUDING these paths,
 * onto every issued document, and a reprint re-renders from that frozen
 * chrome and compares the SHA-256 of the resulting PDF against the hash
 * recorded at issue. If replacing the principal's signature reused the path
 * `branding/principal_signature.png`, then every certificate issued before
 * the replacement would re-render with different bytes and throw
 * DocumentReproducibilityViolation - permanently, for every document that
 * school ever issued, with no way back.
 *
 * Content-hashing makes that impossible: different bytes, different path. A
 * frozen path either still resolves to the bytes it resolved to at issue, or
 * resolves to nothing at all (see EmbeddedImage, which renders a missing file
 * as no image AT ALL rather than a broken-image box, deterministically).
 *
 * SVG is deliberately NOT allowed. An SVG is a script-capable document, these
 * files are served from the app's own origin, and dompdf's SVG support is
 * partial anyway - an uploaded SVG would be both a stored-XSS surface and an
 * unreliable render.
 */
final class StoredImage
{
    public const DISK = 'public';

    public const DIRECTORY = 'branding';

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    /** The longest edge an uploaded branding image may have, in pixels. */
    public const MAX_DIMENSION = 2000;

    /** The largest an uploaded branding image may be, in kilobytes. */
    public const MAX_KILOBYTES = 2048;

    /**
     * $directory and $disk default to the branding pair and every existing
     * caller keeps its behaviour. They exist because a second family of
     * images - guardian photographs - wants the SAME content-hashed naming and
     * the same never-reuse-a-path guarantee, but must not land in branding/ on
     * the public disk: a photograph of a person is served through a
     * policy-checked controller off the private default disk. Sharing the
     * mechanism and parameterising the destination is what keeps there from
     * being a second, subtly different image store.
     */
    public static function put(
        string $slot,
        UploadedFile $file,
        string $directory = self::DIRECTORY,
        string $disk = self::DISK,
    ): string {
        $extension = strtolower($file->getClientOriginalExtension());

        $contents = (string) file_get_contents((string) $file->getRealPath());

        return self::putContents($slot, $contents, $extension, $directory, $disk);
    }

    public static function putContents(
        string $slot,
        string $contents,
        string $extension,
        string $directory = self::DIRECTORY,
        string $disk = self::DISK,
    ): string {
        $extension = strtolower($extension);

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw new InvalidArgumentException(
                "[{$extension}] is not an allowed branding image type; "
                .'allowed: '.implode(', ', self::ALLOWED_EXTENSIONS).'.'
            );
        }

        // 16 hex characters of SHA-256: 64 bits of collision resistance over
        // a handful of files per install, and a filename that still fits in
        // the 255-character column comfortably.
        $digest = substr(hash('sha256', $contents), 0, 16);

        $path = $directory.'/'.Str::slug($slot).'-'.$digest.'.'.$extension;

        Storage::disk($disk)->put($path, $contents);

        return $path;
    }

    /**
     * Delete the image a slot USED to hold, now that it holds $keep.
     *
     * NOTE ON REPRODUCIBILITY. Deleting the previous file DOES change what a
     * document frozen against that path re-renders to - from "the old image"
     * to "no image". That is deliberate and is the SAFE direction of the
     * trade: the alternative (never deleting) leaves the disk accumulating
     * every image a school ever tried, and the unsafe direction (reusing the
     * path) makes the OLD documents silently re-render with the NEW image,
     * which is a forgery rather than a failure.
     *
     * A school that replaces a signature and then reprints an old
     * certificate gets an honest DocumentReproducibilityViolation rather than
     * a certificate carrying a signature that was not on the original. Where
     * an install needs the old artefacts to keep reprinting, the operator
     * keeps the old file: call sites may skip forget() and the frozen path
     * keeps resolving. BrandingReproducibilityTest asserts both halves.
     *
     * Two guards, both load-bearing:
     *   - never delete when the two paths are equal (re-uploading identical
     *     bytes yields the same path, and deleting it would erase the image
     *     that was just "saved");
     *   - never delete anything outside branding/, so a hand-edited path
     *     column can never turn a settings save into a delete of an issued
     *     PDF.
     */
    public static function forget(
        ?string $previous,
        ?string $keep,
        string $directory = self::DIRECTORY,
        string $disk = self::DISK,
    ): void {
        if ($previous === null || $previous === '' || $previous === $keep) {
            return;
        }

        if (! str_starts_with($previous, $directory.'/')) {
            return;
        }

        Storage::disk($disk)->delete($previous);
    }
}
