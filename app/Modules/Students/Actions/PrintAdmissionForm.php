<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §7.1 - the Admission Form / Fiche d'admission.
 *
 * A BLANK-FORM live document (§4.2): school branding plus static field
 * labels, optionally pre-filled from an AdmissionApplication or from an
 * existing Student. It never allocates a series number here - "blank copies
 * unnumbered"; the ADM serial belongs to the future Admission Register
 * entry, not to a counter copy (see the 430001 seed migration's header).
 *
 * Sections follow the mockup exactly: A Student Information (full name, DOB,
 * gender, class applying for, previous school) and B Parent/Guardian
 * Information (father, mother, occupation, phone, email), extended with
 * matricule (if allocated), nationality, place of birth and the application
 * reference.
 */
final class PrintAdmissionForm
{
    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(?int $applicationId = null, ?int $studentId = null, ?string $language = null, bool $preview = false): RenderedDocument
    {
        Gate::authorize(Permission::StudentsView->value);

        if ($applicationId !== null) {
            [$form, $subjectType, $subjectId, $label] = $this->fromApplication($applicationId);
        } elseif ($studentId !== null) {
            [$form, $subjectType, $subjectId, $label] = $this->fromStudent($studentId);
        } else {
            // The counter copy: every field renders as a labelled blank line.
            [$form, $subjectType, $subjectId, $label] = [$this->blank(), 'AdmissionForm', 0, 'Blank admission form'];
        }

        return $this->render->issueOrPreview(
            $preview,
            templateCode: 'ADM-FORM',
            subjectType: $subjectType,
            subjectId: $subjectId,
            subjectLabel: $label,
            language: $language,
            data: ['form' => $form],
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: int, 3: string}
     */
    private function fromApplication(int $applicationId): array
    {
        /** @var object{id: int, application_no: string, first_name: string|null, middle_name: string|null, last_name: string|null, date_of_birth: string|null, gender: string|null, place_of_birth: string|null, nationality: string|null, previous_school_name: string|null, last_class_completed: string|null, admission_date: string|null, class_level_id: int|null, converted_student_id: int|null}|null $application */
        $application = DB::table('admission_applications')->where('id', $applicationId)->first([
            'id', 'application_no', 'first_name', 'middle_name', 'last_name',
            'date_of_birth', 'gender', 'place_of_birth', 'nationality',
            'previous_school_name', 'last_class_completed', 'admission_date',
            'class_level_id', 'converted_student_id',
        ]);

        if ($application === null) {
            throw new DomainException("Admission application {$applicationId} does not exist.");
        }

        $classApplied = $application->class_level_id === null
            ? ''
            : (string) DB::table('class_levels')->where('id', $application->class_level_id)->value('name');

        $matricule = $application->converted_student_id === null
            ? ''
            : (string) DB::table('students')->where('id', $application->converted_student_id)->value('matricule');

        $guardians = DB::table('admission_application_guardians')
            ->where('admission_application_id', $applicationId)
            ->orderBy('position')
            ->get(['first_name', 'last_name', 'relationship', 'occupation', 'phone', 'email']);

        $guardianRows = [];

        foreach ($guardians as $guardian) {
            /** @var object{first_name: string, last_name: string, relationship: string, occupation: string|null, phone: string, email: string|null} $guardian */
            $guardianRows[] = [
                'name' => trim($guardian->first_name.' '.$guardian->last_name),
                'relationship' => $guardian->relationship,
                'occupation' => (string) ($guardian->occupation ?? ''),
                'phone' => $guardian->phone,
                'email' => (string) ($guardian->email ?? ''),
            ];
        }

        $studentName = trim(implode(' ', array_filter([
            $application->first_name, $application->middle_name, $application->last_name,
        ])));

        $form = [
            'reference' => $application->application_no,
            'matricule' => $matricule,
            'full_name' => $studentName,
            'date_of_birth' => $application->date_of_birth === null ? '' : Carbon::parse($application->date_of_birth)->format('d/m/Y'),
            'gender' => (string) ($application->gender ?? ''),
            'place_of_birth' => (string) ($application->place_of_birth ?? ''),
            'nationality' => (string) ($application->nationality ?? ''),
            'class_applying_for' => $classApplied,
            'previous_school' => (string) ($application->previous_school_name ?? ''),
            'guardians' => $guardianRows,
        ];

        return [$form, 'AdmissionApplication', $applicationId, 'Admission form '.$application->application_no.($studentName === '' ? '' : ' for '.$studentName)];
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: int, 3: string}
     */
    private function fromStudent(int $studentId): array
    {
        $student = $this->reads->student($studentId);

        $classApplied = '';
        $previousSchool = '';

        try {
            $enrollment = $this->reads->latestEnrollment($studentId);
            $classApplied = $enrollment->level_name;
            $previousSchool = (string) ($enrollment->previous_school_name ?? '');
        } catch (DomainException) {
            // A not-yet-enrolled student still gets a pre-filled form.
        }

        $guardians = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', $studentId)
            ->whereNull('sg.valid_to')
            ->orderByDesc('sg.is_primary')
            ->limit(3)
            ->get(['g.first_name', 'g.last_name', 'sg.relationship', 'g.occupation', 'g.phone', 'g.email']);

        $guardianRows = [];

        foreach ($guardians as $guardian) {
            /** @var object{first_name: string, last_name: string, relationship: string, occupation: string|null, phone: string, email: string|null} $guardian */
            $guardianRows[] = [
                'name' => trim($guardian->first_name.' '.$guardian->last_name),
                'relationship' => $guardian->relationship,
                'occupation' => (string) ($guardian->occupation ?? ''),
                'phone' => $guardian->phone,
                'email' => (string) ($guardian->email ?? ''),
            ];
        }

        $form = [
            'reference' => $student->admission_no,
            'matricule' => $student->matricule,
            'full_name' => $this->reads->fullName($student),
            'date_of_birth' => Carbon::parse($student->date_of_birth)->format('d/m/Y'),
            'gender' => $student->gender,
            'place_of_birth' => (string) ($student->place_of_birth ?? ''),
            'nationality' => $student->nationality,
            'class_applying_for' => $classApplied,
            'previous_school' => $previousSchool,
            'guardians' => $guardianRows,
        ];

        return [$form, 'Student', $studentId, 'Admission form for '.$this->reads->fullName($student)];
    }

    /**
     * @return array<string, mixed>
     */
    private function blank(): array
    {
        return [
            'reference' => '',
            'matricule' => '',
            'full_name' => '',
            'date_of_birth' => '',
            'gender' => '',
            'place_of_birth' => '',
            'nationality' => '',
            'class_applying_for' => '',
            'previous_school' => '',
            'guardians' => [],
        ];
    }
}
