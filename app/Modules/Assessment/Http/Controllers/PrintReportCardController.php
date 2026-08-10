<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Http\Controllers;

use App\Modules\Assessment\Actions\PrintReportCard;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /assessment/report-cards/{enrollment}/{period}/print - streams the
 * bulletin (docs/specs/10-documents.md §6.1) for the Print button on the
 * Assessment Results list.
 *
 * INLINE, not an attachment: mirrors Fees\Http\Controllers\PrintInvoiceController.
 * The established behaviour here is a preview in a new tab - a report card is
 * checked on screen before it is printed for a guardian, and a forced download
 * turns that check into a folder full of orphaned PDFs.
 */
final class PrintReportCardController
{
    public function __invoke(Request $request, int $enrollment, int $period, PrintReportCard $print): Response
    {
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($enrollment, $period, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="report-card-'.$enrollment.'-'.$period.'.pdf"');
    }
}
