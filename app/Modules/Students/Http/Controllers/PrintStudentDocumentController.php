<?php

declare(strict_types=1);

namespace App\Modules\Students\Http\Controllers;

use App\Modules\Students\Actions\PrintAdmissionForm;
use App\Modules\Students\Actions\PrintAttendanceCertificate;
use App\Modules\Students\Actions\PrintBonafideCertificate;
use App\Modules\Students\Actions\PrintCharacterCertificate;
use App\Modules\Students\Actions\PrintLeavingCertificate;
use App\Modules\Students\Actions\PrintStudentInfoSheet;
use App\Modules\Students\Actions\PrintTestimonial;
use App\Modules\Students\Actions\PrintTransferCertificate;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET /students/{student}/documents/{document}/print - streams one of the
 * eight §7 front-desk documents for the Print buttons on the student
 * profile's Documents tab. Follows Fees\Http\Controllers\
 * PrintReceiptController's pattern: DomainException (a refusal the operator
 * can fix - clearance, discipline, empty denominator) -> 422 with the
 * message as plain text; the PDF streams INLINE so the operator previews
 * before printing.
 *
 * Query parameters: `lang` (en|fr, §4.6's explicit-request override),
 * `reason` (transfer), `override_reason` (the §19 documents.override_gate
 * path on transfer/leaving/character), `body` (testimonial), `from`/`to`
 * (attendance range).
 */
final class PrintStudentDocumentController
{
    public function __invoke(Request $request, int $student, string $document): Response
    {
        $lang = $request->query('lang');
        $lang = is_string($lang) ? $lang : null;

        $str = static function (Request $request, string $key): ?string {
            $value = $request->query($key);

            return is_string($value) && trim($value) !== '' ? trim($value) : null;
        };

        try {
            $rendered = match ($document) {
                'admission-form' => app(PrintAdmissionForm::class)->handle(null, $student, $lang),
                'info-sheet' => app(PrintStudentInfoSheet::class)->handle($student, $lang),
                'transfer-certificate' => app(PrintTransferCertificate::class)->handle(
                    $student,
                    $str($request, 'reason'),
                    $str($request, 'override_reason'),
                    $lang,
                ),
                'leaving-certificate' => app(PrintLeavingCertificate::class)->handle(
                    $student,
                    $str($request, 'override_reason'),
                    $lang,
                ),
                'character-certificate' => app(PrintCharacterCertificate::class)->handle(
                    $student,
                    $str($request, 'override_reason'),
                    $lang,
                ),
                'testimonial' => app(PrintTestimonial::class)->handle(
                    $student,
                    $str($request, 'body') ?? '',
                    $lang,
                ),
                'bonafide' => app(PrintBonafideCertificate::class)->handle($student, $lang),
                'attendance-certificate' => $this->attendance($request, $student, $str, $lang),
                default => throw new NotFoundHttpException(),
            };
        } catch (DomainException|ValidationException $exception) {
            $message = $exception instanceof ValidationException
                ? implode(' ', array_map(
                    static fn (array $messages): string => implode(' ', $messages),
                    $exception->errors(),
                ))
                : $exception->getMessage();

            return response($message, 422)->header('Content-Type', 'text/plain; charset=UTF-8');
        }

        return response($rendered->bytes, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$document.'-'.$student.'.pdf"');
    }

    /**
     * @param  callable(Request, string): ?string  $str
     */
    private function attendance(Request $request, int $student, callable $str, ?string $lang): RenderedDocument
    {
        $from = $str($request, 'from');
        $to = $str($request, 'to');

        if ($from === null || $to === null) {
            throw new DomainException('An attendance attestation needs the date range: supply both from and to dates.');
        }

        return app(PrintAttendanceCertificate::class)->handle($student, $from, $to, $lang);
    }
}
