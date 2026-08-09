<?php

declare(strict_types=1);

namespace App\Modules\Fees\Http\Controllers;

use App\Modules\Fees\Actions\PrintStatement;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /finance/statement/{student}/print - streams the Student Account
 * Statement PDF (docs/specs/10-documents.md §10.3), LIVE - `?as_of=` picks
 * the date, defaulting to today.
 */
final class PrintStatementController
{
    public function __invoke(Request $request, int $student, PrintStatement $print): Response
    {
        $lang = $request->query('lang');
        $asOf = $request->query('as_of');

        try {
            $rendered = $print->handle(
                $student,
                is_string($asOf) ? $asOf : null,
                is_string($lang) ? $lang : null,
            );
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="statement-'.$student.'.pdf"');
    }
}
