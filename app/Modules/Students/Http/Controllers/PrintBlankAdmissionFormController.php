<?php

declare(strict_types=1);

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Students\Actions\PrintAdmissionForm;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /students/documents/admission-form/print - the §7.1 COUNTER COPY: a
 * blank, unnumbered Admission Form for walk-in families, or pre-filled from
 * an AdmissionApplication via `?application=`. Separate from the
 * per-student route because a blank form has no student to key on.
 */
final class PrintBlankAdmissionFormController
{
    public function __invoke(Request $request, PrintAdmissionForm $print): Response
    {
        $application = $request->query('application');
        $lang = $request->query('lang');

        try {
            $rendered = $print->handle(
                is_numeric($application) ? (int) $application : null,
                null,
                is_string($lang) ? $lang : null,
            );
        } catch (DomainException $exception) {
            return response($exception->getMessage(), 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="admission-form.pdf"');
    }
}
