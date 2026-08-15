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
 * docs/specs/10-documents.md §7.9 - the Testimonial / Attestation de
 * scolarité et de conduite. Series CHAR (shared with §7.8), principal,
 * snapshot-backed under the receipt pattern.
 *
 * "Narrative reference combining attendance, conduct and academic standing.
 * Authored text with structured facts appended" - the principal's authored
 * body is a REQUIRED input, and the structured facts (years attended,
 * attendance rate over the latest enrollment where registers exist, conduct
 * summary, class and status) are computed here and frozen with it.
 */
final class PrintTestimonial
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(int $studentId, string $body, ?string $language = null): RenderedDocument
    {
        Gate::authorize(Permission::StudentsView->value);

        $isAuthored = trim($body) !== '';

        $student = $this->reads->student($studentId);
        $enrollment = $this->reads->latestEnrollment($studentId);

        // On a reprint the frozen payload wins anyway (payload_snapshot);
        // requiring the operator to re-type the authored text to obtain a
        // duplicate would be a rule §4.5 does not state.
        if (! $isAuthored && ! $this->reads->alreadyIssued('TESTIMONIAL', 'Enrollment', $enrollment->id, $enrollment->id)) {
            throw new DomainException(
                'A testimonial is an authored reference (10-documents §7.9); provide the narrative text to issue it.'
            );
        }

        $conduct = $this->reads->disciplineSummary($studentId);

        // The facts window: the enrollment period. The upper bound for a
        // still-enrolled student is the ACADEMIC YEAR end, not "today" -
        // registers cannot exist beyond the year, so the wider bound is
        // harmless, while "today" would clip an enrollment whose year runs
        // ahead of the calendar and conjure an empty denominator.
        $rangeFrom = (string) $enrollment->enrolled_on;
        $rangeTo = (string) ($enrollment->left_on ?? max($enrollment->year_ends_on, Carbon::now()->toDateString()));
        $attendance = $this->reads->attendanceInRange($enrollment->id, $rangeFrom, $rangeTo);

        $fullName = $this->reads->fullName($student);

        $payload = [
            'school' => $this->render->captureSchoolChrome(includeStateHeader: true),
            'testimonial' => [
                'identity' => $this->reads->identityBlock(
                    $student,
                    $enrollment,
                    $this->reads->lastClassGroupName($enrollment->id),
                ),
                'body' => trim($body),
                'attended_from' => Carbon::parse($student->first_admission_date ?? $enrollment->enrolled_on)->format('d/m/Y'),
                // Displayed 'to' stays honest: departure date, or today
                // for a still-enrolled student - never the computation's
                // year-end upper bound.
                'attended_to' => Carbon::parse((string) ($enrollment->left_on ?? Carbon::now()->toDateString()))->format('d/m/Y'),
                'level' => $enrollment->level_name,
                'enrollment_status' => $enrollment->status,
                // Facts print only where the underlying registers exist -
                // never a 100% conjured from an empty denominator (§7.11's
                // rule, applied to §7.9's appended facts).
                'attendance_rate' => $attendance['registers'] > 0 ? $attendance['rate_percent'] : null,
                'attendance_registers' => $attendance['registers'],
                'conduct_is_clear' => $conduct['open_cases'] === 0,
                'conduct_total_cases' => $conduct['total_cases'],
            ],
        ];

        return $this->render->handle(
            templateCode: 'TESTIMONIAL',
            subjectType: 'Enrollment',
            subjectId: $enrollment->id,
            subjectLabel: 'Testimonial for '.$fullName,
            snapshotId: $enrollment->id,
            language: $language,
            data: $payload,
        );
    }
}
