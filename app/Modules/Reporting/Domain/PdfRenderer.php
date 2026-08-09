<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

/**
 * docs/specs/10-documents.md 4.8 / 00-core 16 gate 11 - the PDF engine
 * behind every document, as an interface so the engine is swappable without
 * touching the pipeline.
 *
 * Takes HTML, not a view name, on purpose: rendering the Blade template is
 * the Action's job (it owns the data contract), and keeping the renderer
 * ignorant of Blade keeps this namespace framework-agnostic and the engine
 * trivially replaceable by anything that eats HTML.
 *
 * Implementations MUST be deterministic: the same HTML, paper, orientation
 * and stamp must produce byte-identical output, because 4.5 hashes the bytes
 * at issue and compares them on every reprint. Engine-injected timestamps and
 * random file identifiers are exactly what PdfStamp exists to pin down.
 */
interface PdfRenderer
{
    public function render(
        string $html,
        PaperSize $paper,
        Orientation $orientation,
        PdfStamp $stamp,
        ?string $pageFooterText = null,
    ): string;
}
