<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Livewire\Guardians;

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\GuardianCommunication;
use App\Modules\Guardians\Models\GuardianMeeting;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Guardian Profile, docs/specs/07-students.md 11.3.
 *
 * ── What ships and what does not ──────────────────────────────────────────
 *
 * Live: the guardian record (7.1), the Linked Students table (7.2) WITH the
 * Permissions column 11.3 explicitly adds to the mockup, Meetings and
 * Communication History (7.8). The last two have real tables that nothing
 * writes to yet, so they render a real empty state - which is the honest
 * answer, and 7.8 even says `queued` with no connectivity is the normal steady
 * state, so an empty log is not a fault.
 *
 * Inert: Address & Contact (its content is already on this page, and a tab
 * that duplicates a card is not a feature), Documents (GuardianDocument has no
 * table in Phase 2) and Payments (04-fees).
 *
 * ── Student columns come from the query builder ───────────────────────────
 *
 * StudentGuardian deliberately has NO relation to Student -
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from using
 * App\Modules\Students\Models, and that model's header records the sanctioned
 * alternative: join `students` by `student_id` in a query. So the linked-
 * students table gets its name / admission number / class / status from one
 * query-builder statement, and its authorization column from the links
 * themselves - the flags never leave the module that owns the rule.
 *
 * ── Read-only ─────────────────────────────────────────────────────────────
 *
 * 7.6 makes every authorization change a close-and-succeed Action with its own
 * permission and an immediate portal-session revocation. A pencil icon here
 * that did not do all of that would break the audit trail 7.6 exists to
 * protect, so there is none.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    private const TAB_LIST_LIMIT = 50;

    public Guardian $guardian;

    /** @var list<string> */
    public const LIVE_TABS = ['linked_students', 'meetings', 'communications'];

    /** @var list<string> */
    public const DISABLED_TABS = ['address', 'documents', 'payments'];

    #[Url]
    public string $tab = 'linked_students';

    public function mount(Guardian $guardian): void
    {
        // routes/web.php gates guardians.show on students.view: a guardian
        // record is read by whoever may read the student it belongs to. The
        // component repeats the check because a Livewire component is
        // independently addressable over the wire (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);

        $this->guardian = $guardian;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::LIVE_TABS, true) ? $tab : 'linked_students';
    }

    private function activeTab(): string
    {
        return in_array($this->tab, self::LIVE_TABS, true) ? $this->tab : 'linked_students';
    }

    /**
     * Every link this guardian holds, current or ended.
     *
     * Ended links are shown, not hidden: 7.2 has no hard delete, and an
     * operator investigating "why can this person still see the fees" needs to
     * see the row that answers it. The validity pill tells them apart.
     *
     * @return Collection<int, StudentGuardian>
     */
    private function links(): Collection
    {
        return $this->guardian->links()
            ->orderByDesc('is_primary')
            ->orderByDesc('valid_from')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * Student columns for the links on screen, keyed by student id. One query,
     * query builder only - see the class header.
     *
     * @param  list<int>  $studentIds
     * @return array<int, array{name: string, admission_no: string, status: string, class_name: string|null}>
     */
    private function studentRows(array $studentIds): array
    {
        if ($studentIds === []) {
            return [];
        }

        $rows = DB::table('students as s')
            ->whereIn('s.id', $studentIds)
            ->leftJoin('enrollments as enr', function ($join): void {
                $join->on('enr.student_id', '=', 's.id')
                    ->whereIn('enr.status', ['pending', 'active', 'suspended']);
            })
            ->leftJoin('enrollment_segments as seg', function ($join): void {
                $join->on('seg.enrollment_id', '=', 'enr.id')->whereNull('seg.ends_on');
            })
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->select([
                's.id as id',
                's.first_name as first_name',
                's.middle_name as middle_name',
                's.last_name as last_name',
                's.admission_no as admission_no',
                's.status as status',
                'cg.name as class_name',
            ])
            ->get();

        $byId = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, first_name: string, middle_name: string|null, last_name: string, admission_no: string, status: string, class_name: string|null} $row */
            $byId[(int) $row->id] = [
                'name' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))),
                'admission_no' => (string) $row->admission_no,
                'status' => (string) $row->status,
                'class_name' => is_string($row->class_name) ? $row->class_name : null,
            ];
        }

        return $byId;
    }

    /**
     * @return Collection<int, GuardianMeeting>
     */
    private function meetings(): Collection
    {
        return GuardianMeeting::query()
            ->where('guardian_id', '=', $this->guardian->id)
            ->orderByDesc('scheduled_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, GuardianCommunication>
     */
    private function communications(): Collection
    {
        return GuardianCommunication::query()
            ->where('guardian_id', '=', $this->guardian->id)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    public function render(): mixed
    {
        $tab = $this->activeTab();
        $links = $this->links();

        /** @var list<int> $studentIds */
        $studentIds = $links->pluck('student_id')->unique()->values()->all();

        return view('livewire.guardians.show', [
            'tab' => $tab,
            // The left rail shows a Linked Students preview on every tab, so
            // the links are always resolved - they are also the cheapest query
            // on the page and are capped.
            'links' => $links,
            'studentRows' => $this->studentRows($studentIds),
            'meetings' => $tab === 'meetings' ? $this->meetings() : new Collection(),
            'communications' => $tab === 'communications' ? $this->communications() : new Collection(),
        ]);
    }
}
