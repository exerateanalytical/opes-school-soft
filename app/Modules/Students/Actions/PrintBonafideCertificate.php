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
 * docs/specs/10-documents.md §7.10 - the Bonafide Student Certificate /
 * Attestation d'inscription. Series BON, registrar + stamp, snapshot-backed
 * under the receipt pattern. "The most frequently requested document in a
 * Cameroonian school office" - issuable in one click from the student
 * profile, which is exactly the button the Documents tab carries.
 *
 * Attests that the student IS registered - so the original issue requires a
 * LIVE enrollment (pending/active/suspended). A reprint of an
 * already-issued certificate reproduces the frozen payload even after the
 * student has since left (the attestation was true as at issue - §4.2).
 */
final class PrintBonafideCertificate
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(int $studentId, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::StudentsView->value);

        $student = $this->reads->student($studentId);

        try {
            $enrollment = $this->reads->latestEnrollment($studentId, requireLive: true);
        } catch (DomainException $e) {
            // No live enrollment: still reprint an existing certificate for
            // the latest terminal one, but never issue a fresh original.
            $enrollment = $this->reads->latestEnrollment($studentId);

            if (! $this->reads->alreadyIssued('BONAFIDE', 'Enrollment', $enrollment->id, $enrollment->id)) {
                throw $e;
            }
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
                'level' => $enrollment->level_name,
                'academic_year' => $enrollment->year_name,
                'enrolled_on' => Carbon::parse($enrollment->enrolled_on)->format('d/m/Y'),
            ],
        ];

        return $this->render->handle(
            templateCode: 'BONAFIDE',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'Bonafide certificate for '.$fullName,
            snapshotId: $enrollment->id,
            language: $language,
            data: $payload,
        );
    }
}
