<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\Timetable;

use App\Modules\Academics\Actions\AssignTimetableSlot;
use App\Modules\Academics\Actions\RemoveTimetableSlot;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Room;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Timetable Management — 09-ui §8.6, mirroring the 'school timetable.png'
 * mockup: week grid (named periods × Monday–Saturday), Class / Teacher /
 * Room / Exam tabs, class-details side panel, subject legend.
 *
 * Generate Timetable is a real button that opens a "not available in this
 * version" notice — auto-generation is explicitly out of v1 (constraint
 * solving with no defined objective function) and 00-core forbids silent
 * no-ops.
 *
 * Teacher names come from DB::table('staff_members') — StaffMember is an HR
 * model, and the exam list from DB::table('exams') — Assessment-owned; the
 * boundary test forbids importing either Model here.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** @var list<string> */
    public const TABS = ['class', 'teacher', 'room', 'exam'];

    #[Url]
    public string $tab = 'class';

    #[Url]
    public string $classGroupId = '';

    #[Url]
    public string $staffMemberId = '';

    #[Url]
    public string $roomId = '';

    public bool $showGenerateNotice = false;

    // ── Assign form ─────────────────────────────────────────────────────
    public bool $showAssignForm = false;

    public string $assignDay = '';

    public string $assignPeriodId = '';

    public string $assignSubjectId = '';

    public string $assignStaffId = '';

    public string $assignRoomId = '';

    public function mount(): void
    {
        Gate::authorize(Permission::TimetableView->value);

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'class';
        }
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
            $this->tab = $tab;
            $this->showAssignForm = false;
        }
    }

    public function generate(): void
    {
        // 09-ui §8.6: the button exists, the feature does not — say so.
        $this->showGenerateNotice = true;
    }

    public function dismissGenerateNotice(): void
    {
        $this->showGenerateNotice = false;
    }

    public function startAssign(): void
    {
        Gate::authorize(Permission::TimetableManage->value);

        $this->reset(['assignDay', 'assignPeriodId', 'assignSubjectId', 'assignStaffId', 'assignRoomId']);
        $this->resetErrorBag();
        $this->showAssignForm = true;
    }

    public function cancelAssign(): void
    {
        $this->reset(['showAssignForm', 'assignDay', 'assignPeriodId', 'assignSubjectId', 'assignStaffId', 'assignRoomId']);
        $this->resetErrorBag();
    }

    public function assign(AssignTimetableSlot $assignSlot): void
    {
        Gate::authorize(Permission::TimetableManage->value);

        $validated = $this->validate([
            'classGroupId' => ['required', 'integer', 'exists:class_groups,id'],
            'assignDay' => ['required', 'integer', 'min:1', 'max:6'],
            'assignPeriodId' => ['required', 'integer', 'exists:timetable_periods,id'],
            'assignSubjectId' => ['required', 'integer', 'exists:subjects,id'],
            'assignStaffId' => ['required', 'integer'],
            'assignRoomId' => $this->assignRoomId === '' ? ['nullable'] : ['integer', 'exists:rooms,id'],
        ]);

        try {
            $assignSlot->handle(
                classGroupId: (int) $validated['classGroupId'],
                dayOfWeek: (int) $validated['assignDay'],
                timetablePeriodId: (int) $validated['assignPeriodId'],
                subjectId: (int) $validated['assignSubjectId'],
                staffMemberId: (int) $validated['assignStaffId'],
                roomId: $this->assignRoomId === '' ? null : (int) $this->assignRoomId,
            );
        } catch (DomainException $exception) {
            // slot_taken / teacher_busy / room_double_booked, rejected by the
            // DB constraint and translated by the Action.
            $this->addError('assign', $exception->getMessage());

            return;
        }

        session()->flash('status', __('timetable.assigned'));
        $this->cancelAssign();
    }

    public function removeSlot(int $slotId, RemoveTimetableSlot $removeSlot): void
    {
        Gate::authorize(Permission::TimetableManage->value);

        $removeSlot->handle($slotId);

        session()->flash('status', __('timetable.removed'));
    }

    private function currentYear(): ?AcademicYear
    {
        return AcademicYear::query()->where('is_current', true)->first();
    }

    /**
     * Bell schedule for the selected class group's section, break rows
     * included — the grid's row set.
     *
     * @return Collection<int, TimetablePeriod>
     */
    private function periodsFor(ClassGroup $classGroup): Collection
    {
        $level = ClassLevel::query()->find($classGroup->class_level_id);

        if ($level === null) {
            return new Collection();
        }

        return TimetablePeriod::query()
            ->where('school_section_id', $level->school_section_id)
            ->orderBy('sequence')
            ->get();
    }

    /**
     * @param  Collection<int, TimetableSlot>  $slots
     * @return array<string, TimetableSlot> keyed "periodId-day"
     */
    private function cellMap(Collection $slots): array
    {
        $cells = [];

        foreach ($slots as $slot) {
            $cells[$slot->timetable_period_id.'-'.$slot->day_of_week] = $slot;
        }

        return $cells;
    }

    /**
     * @return array<int, string> staff id => display name
     */
    private function teacherNames(): array
    {
        $names = [];

        foreach (DB::table('staff_members')->orderBy('last_name')->get() as $row) {
            $names[(int) $row->id] = trim((string) $row->first_name.' '.(string) $row->last_name);
        }

        return $names;
    }

    /**
     * The Exam tab: Phase 3 sittings, read cross-module (Assessment owns
     * `exams`) through the query builder.
     *
     * @return array<int, \stdClass>
     */
    private function examSittings(AcademicYear $year): array
    {
        return DB::table('exams')
            ->join('assessment_periods', 'assessment_periods.id', '=', 'exams.assessment_period_id')
            ->join('class_groups', 'class_groups.id', '=', 'exams.class_group_id')
            ->join('subject_allocations', 'subject_allocations.id', '=', 'exams.subject_allocation_id')
            ->join('subjects', 'subjects.id', '=', 'subject_allocations.subject_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'exams.room_id')
            ->where('assessment_periods.academic_year_id', $year->getKey())
            ->whereNot('exams.status', 'cancelled')
            ->orderBy('exams.scheduled_on')
            ->orderBy('exams.starts_at')
            ->limit(100)
            ->get([
                'exams.id',
                'exams.scheduled_on',
                'exams.starts_at',
                'exams.duration_minutes',
                'exams.status',
                'class_groups.name as class_group_name',
                'subjects.name as subject_name',
                'rooms.name as room_name',
            ])
            ->values()
            ->all();
    }

    public function render(): mixed
    {
        $year = $this->currentYear();
        $canManage = Gate::allows(Permission::TimetableManage->value);

        $classGroups = $year === null
            ? new Collection()
            : ClassGroup::query()
                ->where('academic_year_id', $year->getKey())
                ->orderBy('name')
                ->get();

        $classGroup = null;
        $periods = new Collection();
        $cells = [];
        $slots = new Collection();

        if ($year !== null && $this->tab === 'class' && $classGroups->isNotEmpty()) {
            $classGroup = $this->classGroupId === ''
                ? $classGroups->first()
                : $classGroups->firstWhere('id', (int) $this->classGroupId);
            $classGroup ??= $classGroups->first();

            $periods = $this->periodsFor($classGroup);
            $slots = TimetableSlot::query()
                ->with(['subject', 'room'])
                ->where('class_group_id', $classGroup->getKey())
                ->get();
            $cells = $this->cellMap($slots);
        }

        if ($year !== null && $this->tab === 'teacher' && $this->staffMemberId !== '') {
            $slots = TimetableSlot::query()
                ->with(['subject', 'room', 'classGroup', 'period'])
                ->where('staff_member_id', (int) $this->staffMemberId)
                ->where('academic_year_id', $year->getKey())
                ->get()
                ->sortBy(fn (TimetableSlot $slot): string => (string) $slot->period?->starts_at)
                ->values();
            $periods = $slots->map(fn (TimetableSlot $slot): ?TimetablePeriod => $slot->period)
                ->filter()
                ->unique('id')
                ->sortBy('starts_at')
                ->values();
            $cells = $this->cellMap($slots);
        }

        if ($year !== null && $this->tab === 'room' && $this->roomId !== '') {
            $slots = TimetableSlot::query()
                ->with(['subject', 'classGroup', 'period'])
                ->where('room_id', (int) $this->roomId)
                ->where('academic_year_id', $year->getKey())
                ->get();
            $periods = $slots->map(fn (TimetableSlot $slot): ?TimetablePeriod => $slot->period)
                ->filter()
                ->unique('id')
                ->sortBy('starts_at')
                ->values();
            $cells = $this->cellMap($slots);
        }

        // The side panel's roster size: enrollment_segments is a Students
        // table, read via the query builder.
        $rosterCount = $classGroup === null ? null : DB::table('enrollment_segments')
            ->where('class_group_id', $classGroup->getKey())
            ->whereNull('ends_on')
            ->count();

        return view('livewire.academics.timetable.index', [
            'currentYear' => $year,
            'canManage' => $canManage,
            'classGroups' => $classGroups,
            'classGroup' => $classGroup,
            'periods' => $periods,
            'cells' => $cells,
            'gridSlots' => $slots,
            'teacherNames' => $this->teacherNames(),
            'roomOptions' => Room::query()->orderBy('name')->get(),
            'subjectOptions' => Subject::query()->where('is_active', true)->orderBy('name')->get(),
            'examSittings' => $year === null || $this->tab !== 'exam' ? [] : $this->examSittings($year),
            'rosterCount' => $rosterCount,
        ]);
    }
}
