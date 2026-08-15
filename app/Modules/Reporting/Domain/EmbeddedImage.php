<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\Storage;

/**
 * Turns a stored branding path into a base64 `data:` URI a document template
 * can put in an <img src>.
 *
 * DompdfRenderer sets setIsRemoteEnabled(false) (deliberately - a template
 * able to reach the network is an injection surface, not a feature), so
 * `<img src="/storage/crest.png">` and every http URL resolve to NOTHING in a
 * rendered PDF. The image has to travel inside the HTML.
 *
 * DETERMINISM. Everything here is a pure function of the bytes on disk: the
 * same file always produces the same URI, and a missing file always produces
 * null. That is what lets an issued document's reprint reproduce its hash -
 * see StoredImage for why the PATH itself is content-hashed.
 *
 * SCOPE. Only paths inside StoredImage::DIRECTORY resolve. These paths come
 * from operator-editable text columns, and without this guard a
 * hand-typed `../../.env` would be base64-inlined into a printed PDF.
 */
final class EmbeddedImage
{
    /** @var array<string, string> */
    private const MIME = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    public static function dataUri(?string $relativePath): ?string
    {
        if ($relativePath === null || $relativePath === '') {
            return null;
        }

        if (! str_starts_with($relativePath, StoredImage::DIRECTORY.'/')) {
            return null;
        }

        if (str_contains($relativePath, '..')) {
            return null;
        }

        $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
        $mime = self::MIME[$extension] ?? null;

        if ($mime === null) {
            return null;
        }

        $disk = Storage::disk(StoredImage::DISK);

        if (! $disk->exists($relativePath)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) $disk->get($relativePath));
    }

    /**
     * Resolve a whole `branding` chrome block's paths to data URIs, keyed by
     * the SAME names the blades read, so a template asks for
     * `$school['branding']['crest_uri']` and never has to know about disks.
     *
     * The original `*_path` keys are left in place untouched: they are what
     * was FROZEN at issue and what a later audit reads back.
     *
     * @param  array<string, mixed>  $branding
     * @return array<string, mixed>
     */
    public static function resolveBranding(array $branding): array
    {
        foreach ([
            'crest_path' => 'crest_uri',
            'logo_path' => 'logo_uri',
            'principal_signature_path' => 'principal_signature_uri',
            'registrar_signature_path' => 'registrar_signature_uri',
            'school_stamp_path' => 'school_stamp_uri',
            'watermark_image_path' => 'watermark_image_uri',
        ] as $pathKey => $uriKey) {
            /** @var mixed $value */
            $value = $branding[$pathKey] ?? null;

            $branding[$uriKey] = is_string($value) ? self::dataUri($value) : null;
        }

        return $branding;
    }
}
