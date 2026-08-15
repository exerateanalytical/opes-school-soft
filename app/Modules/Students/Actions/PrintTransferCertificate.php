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
 * docs/specs/10-documents.md §7.6 - the Transfer Certificate / Certificat de
 * transfert. Snapshot-backed (receipt pattern - the enrollment row set IS
 * the immutable subject; the payload assembled here is frozen onto
 * issued_documents.payload_snapshot at issue), series TC, principal + stamp.
 *
 * The §7.6 clearance gate: issue is BLOCKED while
 * enrollments.financial_clearance is false (set only by the
 * WithdrawalSettlement Action, 04-fees), unless the caller holds
 * documents.override_gate AND supplies a reason. The override is printed
 * nowhere - the payload carries no trace of it - and logged everywhere: a
 * gate_overridden audit_logs row naming the enrollment and the reason.
 */
final class PrintTransferCertificate
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $studentId,
        ?string $reason = null,
        ?string $overrideReason = null,
        ?string $language = null,
    ): RenderedDocument {
        Gate::authorize(Permission::StudentsView->value);

        $student = $this->reads->student($studentId);
        $enrollment = $this->reads->latestEnrollment($studentId);

        $isReprint = $this->reads->alreadyIssued('TRANSFER-CERT', 'Enrollment', $enrollment->id, $enrollment->id);

        if (! $isReprint && ! (bool) $enrollment->financial_clearance) {
            $this->overrideOrRefuse(
                $enrollment->id,
                $overrideReason,
                'Financial clearance has not been recorded for this enrollment (04-fees WithdrawalSettlement); '
                    .'a Transfer Certificate cannot be issued without it. The principal may override with a recorded reason (10-documents §7.6).',
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
                'departed_on' => Carbon::parse($enrollment->left_on ?? $student->left_on ?? Carbon::now()->toDateString())->format('d/m/Y'),
                'reason' => (string) ($reason ?? ''),
                'conduct_is_clear' => $conduct['open_cases'] === 0,
                'conduct_total_cases' => $conduct['total_cases'],
            ],
        ];

        return $this->render->handle(
            templateCode: 'TRANSFER-CERT',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'Transfer certificate for '.$fullName,
            snapshotId: $enrollment->id,
            language: $language,
            data: $payload,
        );
    }

    private function overrideOrRefuse(int $enrollmentId, ?string $overrideReason, string $refusal): void
    {
        if ($overrideReason === null || trim($overrideReason) === '') {
            throw new DomainException($refusal);
        }

        Gate::authorize(Permission::DocumentsOverrideGate->value);

        $this->audit->handle(
            AuditAction::GateOverridden,
            'Students',
            'Enrollment',
            $enrollmentId,
            null,
            ['document' => 'TRANSFER-CERT', 'reason' => trim($overrideReason)],
        );
    }
}
