<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use RuntimeException;

/**
 * docs/specs/10-documents.md 4.5 - a reprint of a snapshot-backed document
 * re-rendered to DIFFERENT bytes than the issue. Either the template version
 * pin or the snapshot has been violated; the print is refused, never
 * silently produced, because the paper in the parent's hand and the paper
 * about to leave the office would disagree.
 */
final class DocumentReproducibilityViolation extends RuntimeException
{
    public static function forSerial(?string $serial, string $storedHash, string $recomputedHash): self
    {
        return new self(sprintf(
            'Reprint of issued document %s produced content hash %s where %s was recorded at issue. '
            .'The template version pin or the snapshot has been violated; the print is refused '
            .'(10-documents 4.5).',
            $serial ?? '(no serial)',
            substr($recomputedHash, 0, 12).'…',
            substr($storedHash, 0, 12).'…',
        ));
    }
}
