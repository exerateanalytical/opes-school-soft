<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

use App\Modules\Reporting\Domain\DocumentFileName;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared PDF export helper for every report screen. Every report's PDF
 * shares one Blade shell (resources/views/reports/pdf-shell.blade.php) so
 * headers, page numbers, and print margins look the same everywhere;
 * callers supply just the title and a table's headers/rows.
 */
final class PdfExport
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<mixed>>  $rows
     */
    public static function download(
        string $title,
        array $headers,
        iterable $rows,
        string $filename,
        string $orientation = 'portrait',
    ): Response {
        $pdf = Pdf::loadView('reports.pdf-shell', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'generatedAt' => now()->format('Y-m-d H:i'),
        ])->setPaper('a4', $orientation);

        // Sanitised HERE, not at each of the call sites. Every house
        // identifier carries '/' BY DESIGN - AST/000001, LM/2026/00001,
        // BC/2026/000001, HA/2026/RCPT/000123 - and Symfony's HeaderUtils
        // refuses to build a Content-Disposition header containing one, so
        // the export button 500s for every record. Two callers remembered
        // to str_replace it by hand and nine did not; a helper that leaves
        // this to the caller is a helper that will be got wrong again.
        // DocumentFileName::sanitize() is the codebase's existing answer,
        // and its own docblock names this exact hazard.
        $safeName = DocumentFileName::sanitize($filename);

        // A STREAMED download, not DomPDF's own ->download(). Every one of
        // these 32 call sites is reached from a wire:click, and Livewire's
        // SupportFileDownloads only recognises a return value that is a
        // StreamedResponse or a BinaryFileResponse
        // (SupportFileDownloads::valueIsntAFileResponse). DomPDF hands back a
        // plain Illuminate\Http\Response, which Livewire therefore treats as
        // ordinary data and tries to JSON-encode - and PDF bytes are not
        // valid UTF-8, so the request dies with "Malformed UTF-8 characters".
        // That second failure sat hidden behind the filename crash above:
        // sanitising alone got the export as far as a different 500.
        return response()->streamDownload(
            static function () use ($pdf): void {
                echo $pdf->output();
            },
            $safeName,
            ['Content-Type' => 'application/pdf'],
        );
    }
}
