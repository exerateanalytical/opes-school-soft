<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §7.7 - the School Leaving Certificate /
 * Certificat de fin de scolarité. Distinct from §7.6 on purpose: a transfer
 * implies moving to a named school mid-programme; LEAVING records the end of
 * the student's time at the school. Same TC-style clearance gate, own LC
 * series, snapshot-backed under the receipt pattern.
 */
final class PrintLeavingCertificate
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $studentId,
        ?string $overrideReason = null,
        ?string $language = null,
    ): RenderedDocument {
        Gate::authorize(Permission::StudentsView->value);

        $student = $this->reads->student($studentId);
        $enrollment = $this->reads->latestEnrollment($studentId);

        $isReprint = $this->reads->alreadyIssued('LEAVING-CERT', 'Enrollment', $enrollment->id, $enrollment->id);

        if (! $isReprint && ! (bool) $enrollment->financial_clearance) {
            if ($overrideReason === null || trim($overrideReason) === '') {
                throw new DomainException(
                    'Financial clearance has not been recorded for this enrollment (04-fees WithdrawalSettlement); '
                        .'a School Leaving Certificate cannot be issued without it. The principal may override with a recorded reason (10-documents §7.7).'
                );
            }

            Gate::authorize(Permission::DocumentsOverrideGate->value);

            $this->audit->handle(
                AuditAction::GateOverridden,
                'Students',
                'Enrollment',
                $enrollment->id,
                null,
                ['document' => 'LEAVING-CERT', 'reason' => trim($overrideReason)],
            );
        }

        $conduct = $this->reads->disciplineSummary($studentId);
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
                'admitted_on' => Carbon::parse($student->first_admission_date ?? $enrollment->enrolled_on)->format('d/m/Y'),
                'left_on' => Carbon::parse($enrollment->left_on ?? $student->left_on ?? Carbon::now()->toDateString())->format('d/m/Y'),
                'conduct_is_clear' => $conduct['open_cases'] === 0,
                'conduct_total_cases' => $conduct['total_cases'],
            ],
        ];

        return $this->render->handle(
            templateCode: 'LEAVING-CERT',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'School leaving certificate for '.$fullName,
            snapshotId: $enrollment->id,
            language: $language,
            data: $payload,
        );
    }
}
