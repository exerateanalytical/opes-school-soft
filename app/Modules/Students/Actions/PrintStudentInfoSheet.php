<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Modules\Students\Models\Student;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/10-documents.md §7.2 - the Student Information Sheet / Fiche de
 * renseignements. LIVE working sheet, no series, guardian verification
 * signature.
 *
 * The §9.5 encrypted fields (religion, blood group, genotype, national ID)
 * are decrypted ONLY inside this render, through the Students module's OWN
 * model casts - they enter the render payload and the printed sheet, and
 * nothing else: the DocumentPrintLog row RenderDocument writes is the §7.2
 * audit record of the print (subject label carries the FIELD LIST, never a
 * value), and because STU-INFO is a live template the payload is never
 * persisted onto issued_documents.
 */
final class PrintStudentInfoSheet
{
    /**
     * Printed on the audit label so the log records WHICH sensitive fields
     * the sheet carried (§7.2: "the field list recorded"), without ever
     * recording a value.
     */
    private const SENSITIVE_FIELDS = 'religion,blood_group,genotype,national_id';

    public function __construct(
        private readonly RenderDocument $render,
        private readonly StudentDocumentReads $reads,
    ) {}

    public function handle(int $studentId, ?string $language = null, bool $preview = false): RenderedDocument
    {
        Gate::authorize(Permission::StudentsView->value);

        /** @var Student|null $student */
        $student = Student::query()->find($studentId);

        if ($student === null) {
            throw new DomainException("Student {$studentId} does not exist.");
        }

        $classGroup = '';
        $section = '';
        $yearName = '';

        try {
            $enrollment = $this->reads->latestEnrollment($studentId);
            $classGroup = $this->reads->lastClassGroupName($enrollment->id);
            $section = $enrollment->section_name;
            $yearName = $enrollment->year_name;
        } catch (DomainException) {
            // A prospective student's sheet still prints; the enrollment
            // strip simply stays empty.
        }

        // Medical information (8.2): the non-encrypted summary rows only -
        // exactly the width the profile screen already exposes to
        // students.view holders.
        $medicalRows = [];

        $records = DB::table('student_medical_records')
            ->where('student_id', $studentId)
            ->orderByDesc('is_emergency_relevant')
            ->limit(12)
            ->get(['condition_type', 'summary', 'severity', 'is_emergency_relevant']);

        foreach ($records as $record) {
            /** @var object{condition_type: string, summary: string, severity: string, is_emergency_relevant: int|bool} $record */
            $medicalRows[] = [
                'condition_type' => $record->condition_type,
                'summary' => $record->summary,
                'severity' => $record->severity,
                'is_emergency_relevant' => (bool) $record->is_emergency_relevant,
            ];
        }

        // Emergency contact: the emergency-flagged guardian link first, the
        // open primary link second. DB::table on the Guardians-owned tables -
        // ModuleBoundaryTest forbids their Models here.
        /** @var object{first_name: string, last_name: string, relationship: string, phone: string}|null $emergency */
        $emergency = DB::table('student_guardians as sg')
            ->join('guardians as g', 'g.id', '=', 'sg.guardian_id')
            ->where('sg.student_id', $studentId)
            ->whereNull('sg.valid_to')
            ->orderByDesc('sg.is_emergency_contact')
            ->orderByDesc('sg.is_primary')
            ->first(['g.first_name', 'g.last_name', 'sg.relationship', 'g.phone']);

        $payload = [
            'sheet' => [
                'full_name' => $student->fullName(),
                'matricule' => $student->matricule,
                'admission_no' => $student->admission_no,
                'date_of_birth' => $student->date_of_birth->format('d/m/Y'),
                'place_of_birth' => (string) ($student->place_of_birth ?? ''),
                'gender' => $student->gender->value,
                'nationality' => $student->nationality,
                'class_group' => $classGroup,
                'section' => $section,
                'academic_year' => $yearName,
                'address' => trim(implode(', ', array_filter([
                    $student->address_line, $student->city, $student->region,
                ]))),
                'phone' => (string) ($student->phone ?? ''),
                'email' => (string) ($student->email ?? ''),
                // The encrypted four, decrypted here and only here (§7.2).
                'religion' => (string) ($student->religion ?? ''),
                'blood_group' => (string) ($student->blood_group ?? ''),
                'genotype' => (string) ($student->genotype ?? ''),
                'national_id' => (string) ($student->national_id_number ?? ''),
                'medical' => $medicalRows,
                'emergency_contact_name' => $emergency === null
                    ? ''
                    : trim($emergency->first_name.' '.$emergency->last_name),
                'emergency_contact_relationship' => $emergency->relationship ?? '',
                'emergency_contact_phone' => $emergency->phone ?? '',
                'as_of' => Carbon::now()->format('d/m/Y'),
            ],
        ];

        return $this->render->issueOrPreview(
            $preview,
            templateCode: 'STU-INFO',
            subjectType: 'Student',
            subjectId: $studentId,
            subjectLabel: 'Student information sheet for '.$student->fullName()
                .' (fields: '.self::SENSITIVE_FIELDS.')',
            language: $language,
            data: $payload,
        );
    }
}
