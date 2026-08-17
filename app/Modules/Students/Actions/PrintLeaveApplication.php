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
 * docs/specs/10-documents.md §11.3 - the Leave Application / Demande de congé
 * (STUDENT variant - see the seed migration's header for the deferred staff
 * variant).
 *
 * A BLANK-FORM live document (§4.2), exactly like PrintAdmissionForm: no
 * series is allocated, the form is either pre-filled from a Student's
 * current identity (name, class) or rendered fully blank for hand-filling.
 * There is no persisted "leave application" row to read from - no
 * StudentLeave model exists in this codebase - so `reason`, `from_date` and
 * `to_date` are always caller-supplied free text, never derived.
 */
final class PrintLeaveApplication
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(
        ?int $studentId = null,
        string $reason = '',
        ?string $fromDate = null,
        ?string $toDate = null,
        ?string $language = null,
        bool $preview = false,
    ): RenderedDocument {
        Gate::authorize(Permission::StudentsView->value);

        if ($studentId !== null) {
            [$form, $subjectId, $label] = $this->fromStudent($studentId, $reason, $fromDate, $toDate);
        } else {
            [$form, $subjectId, $label] = [$this->blank($reason, $fromDate, $toDate), 0, 'Blank leave application'];
        }

        return $this->render->issueOrPreview(
            $preview,
            templateCode: 'LEAVE-APP',
            subjectType: 'Student',
            subjectId: $subjectId,
            subjectLabel: $label,
            language: $language,
            data: ['form' => $form],
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: int, 2: string}
     */
    private function fromStudent(int $studentId, string $reason, ?string $fromDate, ?string $toDate): array
    {
        $student = $this->reads->student($studentId);
        $classGroup = '';

        try {
            $enrollment = $this->reads->latestEnrollment($studentId, requireLive: true);
            $classGroup = $this->reads->lastClassGroupName($enrollment->id);
        } catch (DomainException) {
            // A student with no live enrollment still gets a pre-filled form.
        }

        $form = [
            'student_name' => $this->reads->fullName($student),
            'class_group' => $classGroup,
            'reason' => $reason,
            'from' => $fromDate === null ? '' : Carbon::parse($fromDate)->format('d/m/Y'),
            'to' => $toDate === null ? '' : Carbon::parse($toDate)->format('d/m/Y'),
            'date_requested' => Carbon::now()->format('d/m/Y'),
        ];

        return [$form, $studentId, 'Leave application for '.$this->reads->fullName($student)];
    }

    /**
     * @return array<string, string>
     */
    private function blank(string $reason, ?string $fromDate, ?string $toDate): array
    {
        return [
            'student_name' => '',
            'class_group' => '',
            'reason' => $reason,
            'from' => $fromDate === null ? '' : Carbon::parse($fromDate)->format('d/m/Y'),
            'to' => $toDate === null ? '' : Carbon::parse($toDate)->format('d/m/Y'),
            'date_requested' => Carbon::now()->format('d/m/Y'),
        ];
    }
}
