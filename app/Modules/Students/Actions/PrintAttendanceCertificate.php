<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §7.11 - the Attestation of Attendance /
 * Attestation de présence. Series BON, registrar, snapshot-backed under the
 * receipt pattern.
 *
 * Certifies attendance over a stated date range with the computed rate, on
 * the AttendanceRegister denominator (07-students C5): registers actually
 * taken. It REFUSES to render for a period with zero registers rather than
 * printing 100% - an attestation over no evidence is not an attestation.
 *
 * Snapshot identity: one IssuedDocument per (enrollment, range). The
 * snapshot id encodes the range as ymd(from).'.'ymd(to) digits - the same
 * range reprints the frozen original as a DUPLICATA; a different range is a
 * new certificate with its own serial, which is what a family asking for
 * "term 1" after "term 2" needs.
 */
final class PrintAttendanceCertificate
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(int $studentId, string $from, string $to, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::StudentsView->value);

        $fromDate = Carbon::parse($from)->startOfDay();
        $toDate = Carbon::parse($to)->startOfDay();

        if ($fromDate->greaterThan($toDate)) {
            throw new DomainException('The attendance range is inverted: the start date must not be after the end date.');
        }

        $student = $this->reads->student($studentId);
        $enrollment = $this->reads->latestEnrollment($studentId);

        $snapshotId = (int) ($fromDate->format('ymd').$toDate->format('ymd'));

        $isReprint = $this->reads->alreadyIssued('ATTEND-CERT', 'Enrollment', $enrollment->id, $snapshotId);

        $attendance = $this->reads->attendanceInRange(
            $enrollment->id,
            $fromDate->toDateString(),
            $toDate->toDateString(),
        );

        if (! $isReprint && $attendance['registers'] === 0) {
            throw new DomainException(
                'No attendance register was taken for this student between '
                    .$fromDate->format('d/m/Y').' and '.$toDate->format('d/m/Y')
                    .'; an attestation cannot be issued over an empty denominator (10-documents §7.11).'
            );
        }

        $fullName = $this->reads->fullName($student);

        $payload = [
            'school' => $this->render->captureSchoolChrome(includeStateHeader: true),
            'certificate' => [
                'identity' => $this->reads->identityBlock(
                    $student,
                    $enrollment,
                    $this->reads->lastClassGroupName($enrollment->id),
                ),
                'from' => $fromDate->format('d/m/Y'),
                'to' => $toDate->format('d/m/Y'),
                'registers' => $attendance['registers'],
                'present' => $attendance['present'],
                'absences' => $attendance['absences'],
                'rate_percent' => $attendance['rate_percent'],
            ],
        ];

        return $this->render->handle(
            templateCode: 'ATTEND-CERT',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'Attendance attestation for '.$fullName
                .' ('.$fromDate->format('d/m/Y').' - '.$toDate->format('d/m/Y').')',
            snapshotId: $snapshotId,
            language: $language,
            data: $payload,
        );
    }
}
