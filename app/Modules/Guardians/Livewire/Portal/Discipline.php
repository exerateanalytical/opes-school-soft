<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Portal;

use App\Modules\Guardians\Actions\AcknowledgeSanctionAsGuardian;
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
 * Row 21 (acknowledge a sanction) IS now writable here, which it was not in
 * P12-P2. The obstacle then was real - `Welfare\Actions\AcknowledgeSanction`
 * gates on the staff `discipline.manage` permission, which the `guardian` role
 * holds under no circumstance - and the fix was not to fork it. That Action's
 * own docblock had always promised "a portal-scoped wrapper... so the
 * timestamp has exactly one writer", so the staff gate moved OUT of the writer
 * into its `handle()`, and `Guardians\Actions\AcknowledgeSanctionAsGuardian`
 * calls `handleAuthorized()` after checking row 21 and the ownership conjunct.
 * One writer of `acknowledged_at`, two explicit authorization paths.
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

    /**
     * Row 21. Everything that matters - the row-21 check, the "this sanction
     * belongs to this child AND its case is visible to guardians" conjunct,
     * the single write of `acknowledged_at`, the audit entry and the refusal
     * of a second acknowledgement - happens inside the Action. A repeat is
     * refused rather than silently rewritten, because WHEN a parent signed is
     * evidentiary.
     */
    public function acknowledge(int $sanctionId): void
    {
        app(AcknowledgeSanctionAsGuardian::class)->handle(
            $this->studentId,
            $sanctionId,
            auth()->user()?->toAuditActor(),
        );

        session()->flash('portal-status', __('opes.guardian_portal.ack_done'));
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

        // `id` is selected now because row 21's acknowledge button needs it.
        $sanctions = $caseIds === [] ? collect() : DB::table('discipline_sanctions')
            ->whereIn('discipline_case_id', $caseIds)
            ->orderByDesc('starts_on')
            ->get(['id', 'discipline_case_id', 'type', 'starts_on', 'ends_on', 'acknowledged_at', 'notes']);

        return view('livewire.guardians.portal.discipline', [
            'studentId' => $this->studentId,
            'childName' => $this->childName,
            'cases' => $cases,
            'sanctionsByCase' => $sanctions->groupBy('discipline_case_id'),
            'canAcknowledge' => app(GuardianPortalPolicy::class)
                ->allows(GuardianCapability::R21AcknowledgeSanction, $this->studentId),
        ]);
    }
}
