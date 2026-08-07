<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Students;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Modules\Students\Models\StudentMedicalRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Student Profile, docs/specs/07-students.md 11.2.
 *
 * ── Which of the eight tabs are real ──────────────────────────────────────
 *
 * Four are live because their data exists in Phase 2:
 *   General   - students table (3.1)
 *   Guardians - student_guardians, rendered by a Guardians-owned nested
 *               component (see below)
 *   Documents - student_documents (8.1)
 *   Medical   - student_medical_records (8.2)
 *
 * The other seven mockup tabs - Overview, Academic Records, Attendance,
 * Examinations, Fees & Payments, Discipline, Activity Log - render DISABLED,
 * with the same aria-disabled + "arrives later" treatment the shell uses for
 * an unbuilt nav item. Their sources are 01-assessment, 04-fees, the register
 * (9) and Welfare, none of which exist yet. A tab that renders a plausible
 * empty grid is worse than one that says it is not here: the first is read as
 * "this child has no marks", the second as "this is not built".
 *
 * Activity Log is on that list even though `student_activity_logs` exists,
 * because 8.3 specifies a paginated viewer over a closed event taxonomy that
 * nothing writes to yet; an always-empty log presented as a feature would
 * claim a completeness the module does not have.
 *
 * ── Why guardians come through a nested component ─────────────────────────
 *
 * 7.3's validity predicate and the 7.5 matrix live on
 * Guardians\Models\StudentGuardian (isValid()/authorises()), and
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from using
 * App\Modules\Guardians\Models. Re-deriving the predicate here would be the
 * one thing 7.5 forbids outright ("Nothing else may make this decision"), so
 * the tab mounts <livewire:students.guardians-panel/>, which lives in the
 * Guardians module and calls those methods on their home ground.
 *
 * ── Bounded, not paginated ────────────────────────────────────────────────
 *
 * 00-core 6.2 rule 8 forbids an unbounded collection query in a view. Each tab
 * list is capped and reports the true total beside the cap, rather than
 * carrying three independent paginators into one screen.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    /** Cap per tab list. See the class header. */
    private const TAB_LIST_LIMIT = 50;

    public Student $student;

    /** One of the LIVE tab keys only; anything else falls back to `general`. */
    #[Url]
    public string $tab = 'general';

    /** @var list<string> */
    public const LIVE_TABS = ['general', 'guardians', 'documents', 'medical'];

    /**
     * The seven mockup tabs of 11.2 that Phase 2 cannot fill, in the mockup's
     * order. Rendered inert; never with invented content.
     *
     * @var list<string>
     */
    public const DISABLED_TABS = [
        'overview',
        'academic_records',
        'attendance',
        'examinations',
        'fees',
        'discipline',
        'activity_log',
    ];

    public function mount(Student $student): void
    {
        // Mirrors routes/web.php: the route already requires students.view,
        // and the component refuses on its own anyway (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);

        $this->student = $student;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, self::LIVE_TABS, true) ? $tab : 'general';
    }

    private function activeTab(): string
    {
        return in_array($this->tab, self::LIVE_TABS, true) ? $this->tab : 'general';
    }

    /**
     * "What class is this student in today" - the class group of the OPEN
     * segment of the live enrollment (5.2). Query builder, because
     * `class_groups` is an Academics table and this module may not use its
     * models; the Enrollment model's own header documents that this is the
     * sanctioned way round.
     */
    private function currentClassName(): ?string
    {
        $name = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->where('enr.student_id', '=', $this->student->id)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('cg.name');

        return is_string($name) ? $name : null;
    }

    /** Same reasoning as currentClassName(): `houses` belongs to Academics. */
    private function houseName(): ?string
    {
        if ($this->student->house_id === null) {
            return null;
        }

        $name = DB::table('houses')->where('id', '=', $this->student->house_id)->value('name');

        return is_string($name) ? $name : null;
    }

    /**
     * @return Collection<int, StudentDocument>
     */
    private function documents(): Collection
    {
        return $this->student->documents()
            ->notArchived()
            ->orderByDesc('created_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, StudentMedicalRecord>
     */
    private function medicalRecords(): Collection
    {
        return $this->student->medicalRecords()
            ->orderByDesc('recorded_at')
            ->limit(self::TAB_LIST_LIMIT)
            ->get();
    }

    public function render(): mixed
    {
        $tab = $this->activeTab();

        return view('livewire.students.show', [
            'tab' => $tab,
            'currentClassName' => $this->currentClassName(),
            'houseName' => $this->houseName(),
            'documents' => $tab === 'documents' ? $this->documents() : new Collection(),
            'documentsTotal' => $tab === 'documents'
                ? $this->student->documents()->notArchived()->count()
                : 0,
            'medicalRecords' => $tab === 'medical' ? $this->medicalRecords() : new Collection(),
            'medicalTotal' => $tab === 'medical' ? $this->student->medicalRecords()->count() : 0,
            'listLimit' => self::TAB_LIST_LIMIT,
        ]);
    }
}
