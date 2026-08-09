<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.1 - the paper sizes the template registry may
 * declare. Mirrors the `document_templates.paper_size` MySQL enum exactly.
 *
 * CR80 (the ID-card blank, 85.60 x 53.98 mm) is not a named size any PDF
 * engine knows, so it carries its own point box; 12.1 requires EXACT physical
 * sizing because a card printed 3 % small no longer fits a badge holder.
 */
enum PaperSize: string
{
    case A4 = 'A4';
    case A5 = 'A5';
    case A3 = 'A3';
    case CR80 = 'CR80';
    case Letter = 'LETTER';

    /**
     * The size in the form dompdf's `setPaper()` accepts: a named size where
     * one exists, a points box for CR80.
     *
     * 85.60 mm x 53.98 mm at 72 pt/in: mm * 72 / 25.4.
     *
     * @return string|array{0: float, 1: float, 2: float, 3: float}
     */
    public function dompdfSize(): string|array
    {
        return match ($this) {
            self::A4 => 'a4',
            self::A5 => 'a5',
            self::A3 => 'a3',
            self::Letter => 'letter',
            self::CR80 => [0.0, 0.0, 242.65, 153.01],
        };
    }
}
