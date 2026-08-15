<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Modules\SchoolProfile\Actions\ReadSetting;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §7.8 - the Character Certificate / Certificat
 * de bonne conduite. Series CHAR, discipline_master + principal + stamp,
 * snapshot-backed under the receipt pattern.
 *
 * The §7.8 discipline gate: issue is BLOCKED while an OPEN DisciplineCase
 * whose category severity meets the configured threshold exists for the
 * student - "certifying good conduct over an open exclusion is exactly the
 * failure that destroys a school's credibility". Overridable only by a
 * documents.override_gate holder with a recorded reason, logged to
 * audit_logs and printed nowhere.
 */
final class PrintCharacterCertificate
{
    /**
     * Categories carry severity 1-5 (26xxx discipline migrations); 3 is the
     * first "grave" tier. Adjustable per school through the settings
     * registry without a deploy.
     */
    private const SEVERITY_SETTING = 'discipline.character_certificate_block_severity';

    private const DEFAULT_BLOCK_SEVERITY = 3;

    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
        private readonly WriteAuditEntry $audit,
        private readonly ReadSetting $settings,
    ) {}

    public function handle(
        int $studentId,
        ?string $overrideReason = null,
        ?string $language = null,
        bool $preview = false,
    ): RenderedDocument {
        Gate::authorize(Permission::StudentsView->value);

        $student = $this->reads->student($studentId);
        $enrollment = $this->reads->latestEnrollment($studentId);
        $conduct = $this->reads->disciplineSummary($studentId);

        $isReprint = $this->reads->alreadyIssued('CHAR-CERT', 'Enrollment', $enrollment->id, $enrollment->id);

        $threshold = (int) $this->settings->handle(self::SEVERITY_SETTING, self::DEFAULT_BLOCK_SEVERITY);

        if (! $isReprint && $conduct['open_cases'] > 0 && $conduct['max_open_severity'] >= $threshold) {
            if ($overrideReason === null || trim($overrideReason) === '') {
                throw new DomainException(
                    "An open discipline case at severity {$conduct['max_open_severity']} exists for this student; "
                        .'a Character Certificate cannot certify good conduct over it. '
                        .'The principal may override with a recorded reason (10-documents §7.8).'
                );
            }

            Gate::authorize(Permission::DocumentsOverrideGate->value);

            $this->audit->handle(
                AuditAction::GateOverridden,
                'Students',
                'Enrollment',
                $enrollment->id,
                null,
                ['document' => 'CHAR-CERT', 'reason' => trim($overrideReason)],
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
                'known_since' => Carbon::parse($student->first_admission_date ?? $enrollment->enrolled_on)->format('d/m/Y'),
                'conduct_is_clear' => $conduct['open_cases'] === 0,
                'conduct_total_cases' => $conduct['total_cases'],
                'issued_on_date' => Carbon::now()->format('d/m/Y'),
            ],
        ];

        return $this->render->issueOrPreview(
            $preview,
            templateCode: 'CHAR-CERT',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'Character certificate for '.$fullName,
            snapshotId: $enrollment->id,
            language: $language,
            data: $payload,
        );
    }
}
