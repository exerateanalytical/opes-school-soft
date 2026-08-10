<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Controllers;

use App\Modules\Payroll\Actions\PrintPayslip;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /payroll/payslips/{payrollItem}/print - streams the payslip
 * (docs/specs/10-documents.md §11.1) for the Print button on the payroll run
 * detail screen. Inline preview, mirroring
 * Fees\Http\Controllers\PrintInvoiceController.
 */
final class PrintPayslipController
{
    public function __invoke(Request $request, int $payrollItem, PrintPayslip $print): Response
    {
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($payrollItem, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="payslip-'.$payrollItem.'.pdf"');
    }
}
