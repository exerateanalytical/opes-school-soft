<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.8 - `GeneratedDocumentPath` semantics: the
 * storage filename is derived from the serial (or a UUID for live
 * documents), SANITISED, because serials contain `/` by design
 * ('HA/2026/RCPT/000123') and student names contain accents and slashes.
 */
final class DocumentFileName
{
    public static function sanitize(string $name): string
    {
        $clean = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
        $clean = trim($clean, '-.');

        return $clean === '' ? 'document' : $clean;
    }
}
