<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Procurement\Actions\PrintPaymentVoucher;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /procurement/payments/{payment}/voucher - streams the Payment Voucher
 * PDF (phase-12-13 D3) for the Print button on the Supplier Payments screen.
 */
final class PrintPaymentVoucherController
{
    public function __invoke(Request $request, int $payment, PrintPaymentVoucher $print): Response
    {
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($payment, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="voucher-'.$payment.'.pdf"');
    }
}
