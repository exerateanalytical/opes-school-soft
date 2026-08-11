<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use App\Modules\Guardians\Support\Portal\ChildAcademics;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/attendance` - 07-students.md 7.5 rows 11 and 12.
 *
 * Two scopes: row 11 is the period SUMMARY a parent sees on a dashboard, row 12
 * is every session with its justification state. A link holding only row 11
 * gets only the summary and is TOLD so - an empty session table would read as
 * "your child has never been marked", which is a very different and much more
 * alarming claim than "your school shares totals with you".
 *
 * NOTE, verified rather than assumed: GuardianScopeMatrix currently grants
 * rows 11 and 12 on the same condition (`hasCustody || receivesReports`), so
 * no link shape holds one without the other and the summary-only branch below
 * is unreachable today. It is kept because 7.5 defines them as separate rows -
 * the matrix may separate them in practice later, and the branch is cheap
 * insurance against a change that would otherwise silently start showing every
 * session to a summary-only guardian. The mobile API's `scope` field carries
 * the same distinction for the same reason.
 *
 * Reads through ChildAcademics, the same class the mobile API uses.
 */
#[Layout('layouts.portal')]
final class Attendance extends Component
{
    public int $studentId;

    public string $childName = '';

    public bool $canDetail = false;

    public function mount(int $student): void
    {
        $policy = app(GuardianPortalPolicy::class);

        $canSummary = $policy->allows(GuardianCapability::R11ViewAttendanceSummary, $student);
        $this->canDetail = $policy->allows(GuardianCapability::R12ViewAttendanceDetail, $student);

        if (! $canSummary && ! $this->canDetail) {
            // authorize() throws the framework's AuthorizationException, which
            // the portal renders as 403 - and row 32 has already been settled
            // by the tab strip's own screens, so this child is known to exist.
            $policy->authorize(GuardianCapability::R11ViewAttendanceSummary, $student);
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
        $reader = app(ChildAcademics::class);

        return view('livewire.guardians.portal.attendance', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'canDetail' => $this->canDetail,
            'summaries' => $reader->attendanceSummaries($this->studentId),
            'records' => $this->canDetail ? $reader->attendanceRecords($this->studentId) : collect(),
        ]);
    }
}
