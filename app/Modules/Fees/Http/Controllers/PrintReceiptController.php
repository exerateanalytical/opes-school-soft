<?php

declare(strict_types=1);

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Actions\PrintReceipt;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /finance/payments/{payment}/receipt - streams the Fee Receipt PDF
 * (docs/specs/10-documents.md §10.1) for the Print button on the Cashier
 * and Invoices screens. `?variant=pos` selects the 80mm thermal template
 * (default the A5 original).
 */
final class PrintReceiptController
{
    public function __invoke(Request $request, int $payment, PrintReceipt $print): Response
    {
        $variant = $request->query('variant') === 'pos' ? PrintReceipt::VARIANT_POS : PrintReceipt::VARIANT_A5;
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($payment, $variant, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        // $rendered->serial is the PLATFORM's own series number, which is
        // NULL for these receipt-pattern templates by design (series_code =
        // NULL - see the 310010 seed migration); the filename falls back to
        // the route id, not a serial that was deliberately never allocated.
        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="receipt-'.$payment.'.pdf"');
    }
}
