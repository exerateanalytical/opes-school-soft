<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain;

use Dompdf\Adapter\CPDF;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;

/**
 * The dompdf implementation of PdfRenderer - the engine 00-core 16 gate 11
 * settled on for the LAN constraint (pure PHP, no headless browser on a
 * school PC). docs/plans/phase-12-13.md 13.2.
 *
 * Determinism (the whole reason this class is more than three lines):
 * dompdf's CPDF adapter stamps CreationDate/ModDate with the wall clock and
 * derives the PDF trailer /ID from microtime() + mt_rand(). Both are
 * overwritten from the caller's PdfStamp AFTER layout and BEFORE output, so
 * a reprint of an issued document reproduces the issued bytes exactly and
 * the 4.5 content-hash comparison can be a strict equality.
 *
 * Remote assets and embedded PHP are disabled: documents are rendered from
 * local Blade templates and the bundled DejaVu fonts (full French diacritic
 * coverage), and a template able to reach the network or run code would be
 * an injection surface, not a feature.
 */
final class DompdfRenderer implements PdfRenderer
{
    public function render(
        string $html,
        PaperSize $paper,
        Orientation $orientation,
        PdfStamp $stamp,
        ?string $pageFooterText = null,
    ): string {
        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsFontSubsettingEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper($paper->dompdfSize(), $orientation->value);
        $dompdf->render();

        $canvas = $dompdf->getCanvas();

        // 4.7 document_footer: "page n of m - every page". The page counter
        // has to be drawn by the engine because only the engine knows m; the
        // text (and its language) comes from the caller's lang file, with
        // {PAGE_NUM}/{PAGE_COUNT} substituted natively per page.
        if ($pageFooterText !== null && $pageFooterText !== '') {
            $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');

            if ($font !== null) {
                $width = $dompdf->getFontMetrics()->getTextWidth($pageFooterText, $font, 7.0);
                $canvas->page_text(
                    ($canvas->get_width() - $width) / 2.0,
                    $canvas->get_height() - 20.0,
                    $pageFooterText,
                    $font,
                    7.0,
                    [0.45, 0.45, 0.45],
                );
            }
        }

        if ($canvas instanceof CPDF) {
            $canvas->add_info('CreationDate', $stamp->pdfDate());
            $canvas->add_info('ModDate', $stamp->pdfDate());
            $canvas->get_cpdf()->fileIdentifier = $stamp->fileIdentifier();
        }

        $bytes = $dompdf->output();

        if ($bytes === '') {
            throw new RuntimeException('dompdf produced no output; the render is treated as failed and consumes nothing.');
        }

        return $bytes;
    }
}
