<?php

declare(strict_types=1);

namespace App\Modules\Tax\Http\Controllers;

use App\Modules\Tax\Actions\PrintWithholdingAttestation;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /tax/withholding-attestations/{attestation}/print - streams the
 * attestation de retenue à la source PDF (03-tax-procurement §6.6,
 * 10-documents §15's WHT-CERT).
 */
final class PrintWithholdingAttestationController
{
    public function __invoke(Request $request, int $attestation, PrintWithholdingAttestation $print): Response
    {
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle($attestation, is_string($lang) ? $lang : null);
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="attestation-'.$attestation.'.pdf"');
    }
}
