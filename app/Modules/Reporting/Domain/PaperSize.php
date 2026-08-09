<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.1 - the paper sizes the template registry may
 * declare. Mirrors the `document_templates.paper_size` MySQL enum exactly
 * (widened additively by the 310009 migration to add POS80).
 *
 * CR80 (the ID-card blank, 85.60 x 53.98 mm) is not a named size any PDF
 * engine knows, so it carries its own point box; 12.1 requires EXACT physical
 * sizing because a card printed 3 % small no longer fits a badge holder.
 *
 * POS80 (phase-12-13 D3, 10-documents 10.1's "80 mm thermal variant" of the
 * Fee Receipt) is the same idea: no named dompdf size exists for a thermal
 * roll. dompdf has no concept of an unbounded continuous roll, so this pins
 * a generous fixed length (297 mm, an A4-height's worth of roll) rather than
 * the true "as long as the content needs" a real thermal driver gives - a
 * documented v1 approximation, not a physical spec.
 */
enum PaperSize: string
{
    case A4 = 'A4';
    case A5 = 'A5';
    case A3 = 'A3';
    case CR80 = 'CR80';
    case Letter = 'LETTER';
    case Pos80 = 'POS80';

    /**
     * The size in the form dompdf's `setPaper()` accepts: a named size where
     * one exists, a points box for CR80/POS80.
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
            // 80 mm wide, 297 mm (v1-fixed) long: 80 * 72 / 25.4, 297 * 72 / 25.4.
            self::Pos80 => [0.0, 0.0, 226.77, 841.89],
        };
    }
}
