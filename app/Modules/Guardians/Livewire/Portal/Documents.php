<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/documents` - 07-students.md 7.5 rows 22-23.
 *
 * School-issued documents (row 22) come from Reporting's `issued_documents`
 * (10-documents §4.4) keyed on `subject_type` = the Students module's own
 * FQCN string (never imported here - ModuleBoundaryTest - the string is the
 * same closed-vocabulary convention `Guardians\Domain\PortalSubjectType`
 * already uses for its own cross-module morph target). Guardian-supplied
 * documents (row 23) come from `student_documents` (07-students §8.1).
 *
 * Byte download is out of this build's scope: the Wave A document catalogue
 * (10-documents §13.3, D3/D4/D5) has not registered templates yet, so there
 * is nothing for RenderDocument to render for a student subject. This screen
 * lists what exists; see remaining_issues in the P12-P2 build report.
 *
 * Row 24 (upload) and row 25 (delete - nobody) are not built here: an
 * upload is a WRITE into the Students module's `student_documents` table,
 * and 00-core 6.2 requires that cross a Students\Actions door that does not
 * exist yet. See remaining_issues.
 */
#[Layout('layouts.portal')]
final class Documents extends Component
{
    /**
     * The FQCN Students\Models\Student would resolve to, held as a string
     * so this class never imports it (ModuleBoundaryTest).
     */
    private const STUDENT_SUBJECT_TYPE = 'App\\Modules\\Students\\Models\\Student';

    public int $studentId;

    public string $childName = '';

    public function mount(int $student): void
    {
        $policy = app(GuardianPortalPolicy::class);

        if (! $policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $student)
            && ! $policy->allows(GuardianCapability::R23ViewGuardianSuppliedDocuments, $student)) {
            $policy->authorize(GuardianCapability::R22ViewSchoolIssuedDocuments, $student);
        }

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $policy = app(GuardianPortalPolicy::class);
        $canSchoolIssued = $policy->allows(GuardianCapability::R22ViewSchoolIssuedDocuments, $this->studentId);
        $canGuardianSupplied = $policy->allows(GuardianCapability::R23ViewGuardianSuppliedDocuments, $this->studentId);

        $schoolIssued = collect();
        $guardianSupplied = collect();

        if ($canSchoolIssued && Schema::hasTable('issued_documents')) {
            $schoolIssued = DB::table('issued_documents')
                ->where('subject_type', self::STUDENT_SUBJECT_TYPE)
                ->where('subject_id', $this->studentId)
                ->where('status', 'valid')
                ->orderByDesc('issued_at')
                ->get(['id', 'document_template_id', 'serial', 'issued_at', 'language']);
        }

        if ($canGuardianSupplied && Schema::hasTable('student_documents')) {
            $guardianSupplied = DB::table('student_documents')
                ->where('student_id', $this->studentId)
                ->where('is_archived', false)
                ->orderByDesc('created_at')
                ->get(['id', 'title', 'issued_on', 'expires_on', 'verification_status', 'created_at']);
        }

        return view('livewire.guardians.portal.documents', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'canSchoolIssued' => $canSchoolIssued,
            'canGuardianSupplied' => $canGuardianSupplied,
            'schoolIssued' => $schoolIssued,
            'guardianSupplied' => $guardianSupplied,
        ]);
    }
}
