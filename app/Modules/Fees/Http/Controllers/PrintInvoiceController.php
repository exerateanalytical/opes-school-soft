<?php

declare(strict_types=1);

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Actions\PrintInvoice;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /finance/invoices/{invoice}/print - streams the Fee Invoice PDF
 * (docs/specs/10-documents.md §10.2) for the Print button on the Invoices
 * list.
 */
final class PrintInvoiceController
{
    public function __invoke(Request $request, int $invoice, PrintInvoice $print): Response
    {
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($invoice, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        // $rendered->serial is the PLATFORM's own series number, which is
        // NULL for these receipt-pattern templates by design (series_code =
        // NULL - see the 310010 seed migration); the filename falls back to
        // the route id, not a serial that was deliberately never allocated.
        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="invoice-'.$invoice.'.pdf"');
    }
}
