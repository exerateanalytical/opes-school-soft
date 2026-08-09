<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Domain\GuardianCapability;
use App\Modules\Guardians\Policies\GuardianPortalPolicy;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `/portal/children/{student}/discipline` - 07-students.md 7.5 rows 19-21.
 *
 * The `visibility` narrowing IS the query, not an extra check bolted on:
 * `discipline_cases.visibility = 'guardian'` is the WHERE clause. A case
 * naming another minor is `internal` and never leaves that table for this
 * screen (row 20's "cases involving another named minor are internal and
 * invisible to every guardian").
 *
 * Row 21 (acknowledge a sanction) is READ-ONLY here: `AcknowledgeSanction`
 * (Welfare\Actions) gates on the staff `discipline.manage` permission, which
 * the `guardian` role holds under no circumstance (Identity\Domain\Role,
 * Phase 12), so this build shows the acknowledgement status without a write
 * path - see remaining_issues in the P12-P2 build report.
 */
#[Layout('layouts.portal')]
final class Discipline extends Component
{
    public int $studentId;

    public string $childName = '';

    public function mount(int $student): void
    {
        app(GuardianPortalPolicy::class)->authorize(GuardianCapability::R19ViewDisciplineList, $student);

        $row = DB::table('students')->where('id', $student)->first(['first_name', 'last_name']);

        if ($row === null) {
            throw new NotFoundHttpException();
        }

        $this->studentId = $student;
        $this->childName = trim($row->first_name.' '.$row->last_name);
    }

    public function render(): mixed
    {
        $cases = DB::table('discipline_cases as c')
            ->join('discipline_categories as cat', 'cat.id', '=', 'c.discipline_category_id')
            ->where('c.student_id', $this->studentId)
            ->where('c.visibility', 'guardian')
            ->orderByDesc('c.occurred_on')
            ->get([
                'c.id', 'c.occurred_on', 'c.status', 'c.description', 'c.resolution_note',
                'c.is_positive', 'cat.name as category_name', 'cat.name_fr as category_name_fr',
            ]);

        $caseIds = $cases->pluck('id')->all();

        $sanctions = $caseIds === [] ? collect() : DB::table('discipline_sanctions')
            ->whereIn('discipline_case_id', $caseIds)
            ->orderByDesc('starts_on')
            ->get(['discipline_case_id', 'type', 'starts_on', 'ends_on', 'acknowledged_at', 'notes']);

        return view('livewire.guardians.portal.discipline', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'cases' => $cases,
            'sanctionsByCase' => $sanctions->groupBy('discipline_case_id'),
        ]);
    }
}
