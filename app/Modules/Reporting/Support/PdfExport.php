<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Support;

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

        return $pdf->download($filename);
    }
}
