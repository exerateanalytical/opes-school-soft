<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Examinations;

use App\Modules\Assessment\Actions\AssignInvigilators;
use App\Modules\Assessment\Actions\GenerateSeating;
use App\Modules\Assessment\Actions\ScheduleExam;
use App\Modules\Assessment\Models\Exam;
use App\Modules\Assessment\Models\ExamInvigilator;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Exam scheduling at /examinations, read-only view of the ScheduleExam /
 * AssignInvigilators / GenerateSeating backend (docs/specs/01-assessment.md
 * 16.1). Mirrors Welfare\Transport\Index's structure: KPI strip, filter bar
 * with #[Url] properties, and a tabbed table (Exams, Invigilators, Seating)
 * built from DB::table() query builder calls only — never another module's
 * Eloquent models across the join (ModuleBoundaryTest).
 *
 * There is no exam-specific permission case yet, so this screen gates on the
 * existing `assessment.configure` case, which is the closest read-access
 * gate available without touching the central Permission enum.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: exams | invigilators | seating. */
    #[Url]
    public string $tab = 'exams';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Schedule Exam form ──────────────────────────────────────────────
    public bool $showScheduleForm = false;

    public string $formExamTypeId = '';

    public string $formAssessmentPeriodId = '';

    public string $formSubjectAllocationId = '';

    public string $formClassGroupId = '';

    public string $formScheduledOn = '';

    public string $formStartsAt = '';

    public string $formDurationMinutes = '';

    public string $formMaxScore = '';

    public string $formRoomId = '';

    // ── Assign Invigilator form ─────────────────────────────────────────
    public bool $showInvigilatorForm = false;

    public string $invExamId = '';

    public string $invStaffId = '';

    public string $invRole = ExamInvigilator::ROLE_ASSISTANT;

    public function mount(): void
    {
        Gate::authorize(Permission::AssessmentConfigure);
    }

    public function toggleScheduleForm(): void
    {
        Gate::authorize(Permission::AcademicsManage);

        $this->showScheduleForm = ! $this->showScheduleForm;
    }

    public function toggleInvigilatorForm(): void
    {
        Gate::authorize(Permission::AcademicsManage);

        $this->showInvigilatorForm = ! $this->showInvigilatorForm;
    }

    public function saveSchedule(ScheduleExam $scheduleExam): void
    {
        Gate::authorize(Permission::AcademicsManage);

        $this->validate([
            'formExamTypeId' => ['required', 'integer', 'min:1'],
            'formAssessmentPeriodId' => ['required', 'integer', 'min:1'],
            'formSubjectAllocationId' => ['required', 'integer', 'min:1'],
            'formClassGroupId' => ['required', 'integer', 'min:1'],
            'formScheduledOn' => ['required', 'date'],
            'formStartsAt' => ['required'],
            'formDurationMinutes' => ['required', 'integer', 'min:1'],
            'formMaxScore' => ['required', 'numeric', 'min:0.01'],
            'formRoomId' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $scheduleExam->handle(
                examTypeId: (int) $this->formExamTypeId,
                assessmentPeriodId: (int) $this->formAssessmentPeriodId,
                subjectAllocationId: (int) $this->formSubjectAllocationId,
                classGroupId: (int) $this->formClassGroupId,
                scheduledOn: $this->formScheduledOn,
                startsAt: $this->formStartsAt,
                durationMinutes: (int) $this->formDurationMinutes,
                maxScore: $this->formMaxScore,
                roomId: $this->formRoomId === '' ? null : (int) $this->formRoomId,
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        }

        $this->reset([
            'showScheduleForm', 'formExamTypeId', 'formAssessmentPeriodId',
            'formSubjectAllocationId', 'formClassGroupId', 'formScheduledOn',
            'formStartsAt', 'formDurationMinutes', 'formMaxScore', 'formRoomId',
        ]);
        $this->tab = 'exams';
        $this->resetPage();
        session()->flash('status', 'Exam scheduled.');
    }

    public function saveInvigilator(AssignInvigilators $assignInvigilators): void
    {
        Gate::authorize(Permission::AcademicsManage);

        $this->validate([
            'invExamId' => ['required', 'integer', 'min:1'],
            'invStaffId' => ['required', 'integer', 'min:1'],
            'invRole' => ['required', 'in:'.implode(',', ExamInvigilator::ROLES)],
        ]);

        try {
            $assignInvigilators->handle(
                (int) $this->invExamId,
                [['staff_id' => (int) $this->invStaffId, 'role' => $this->invRole]],
            );
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field === 'assignments' ? 'invStaffId' : $field, (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        }

        $this->reset(['showInvigilatorForm', 'invExamId', 'invStaffId']);
        $this->invRole = ExamInvigilator::ROLE_ASSISTANT;
        $this->tab = 'invigilators';
        $this->resetPage();
        session()->flash('status', 'Invigilator assigned.');
    }

    /**
     * Completes the exam-setup trio (Schedule Exam, Assign Invigilator,
     * Generate Seating) on the "exams" tab: seats every candidate of a
     * live sitting into its room, gated by the Action's own
     * `academics.manage` check (docs/specs/01-assessment.md 16.1).
     */
    public function generateSeating(int $examId): void
    {
        Gate::authorize(Permission::AcademicsManage);

        try {
            $seatings = app(GenerateSeating::class)->handle($examId);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();
            session()->flash('error', is_string($first) ? $first : 'Seating could not be generated.');

            return;
        } catch (DomainException $e) {
            session()->flash('error', $e->getMessage());

            return;
        }

        $this->tab = 'seating';
        $this->resetPage();
        session()->flash('status', 'Seating plan generated: '.count($seatings).' candidate(s) seated.');
    }

    /**
     * Scheduled exams available to assign an invigilator to (live statuses only).
     *
     * @return list<array{id: int, name: string}>
     */
    private function examOptions(): array
    {
        $options = [];

        $rows = DB::table('exams as ex')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->whereIn('ex.status', Exam::LIVE_STATUSES)
            ->orderByDesc('ex.scheduled_on')
            ->get(['ex.id', 'ex.scheduled_on', 'sub.name as subject_name', 'cg.name as class_group_name']);

        foreach ($rows as $row) {
            /** @var object{id: int|string, scheduled_on: string, subject_name: string, class_group_name: string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'name' => $row->scheduled_on.' - '.$row->subject_name.' ('.$row->class_group_name.')',
            ];
        }

        return $options;
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['exams', 'invigilators', 'seating'], true)
            ? $tab
            : 'exams';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['status', 'search']);
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'invigilators' => $this->invigilatorRows(),
            'seating' => $this->seatingRows(),
            default => $this->examRows(),
        };
    }

    /**
     * The "Exams" tab: one row per scheduled sitting - subject, class,
     * date, time, room, status.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function examRows(): LengthAwarePaginator
    {
        return DB::table('exams as ex')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ex.room_id')
            ->when($this->status !== '', fn ($q) => $q->where('ex.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sub.name', 'like', '%'.$this->search.'%')
                        ->orWhere('cg.name', 'like', '%'.$this->search.'%')
                        ->orWhere('r.name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('ex.scheduled_on')
            ->orderBy('ex.starts_at')
            ->select([
                'ex.id', 'ex.scheduled_on', 'ex.starts_at', 'ex.duration_minutes',
                'ex.status', 'ex.max_score', 'ex.room_id',
                'sub.name as subject_name', 'cg.name as class_group_name',
                'r.name as room_name', 'r.code as room_code',
            ])
            ->selectRaw('exists(select 1 from exam_seatings as es where es.exam_id = ex.id) as has_seating')
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The "Invigilators" tab: one row per staff assignment on a paper.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function invigilatorRows(): LengthAwarePaginator
    {
        return DB::table('exam_invigilators as ei')
            ->join('exams as ex', 'ex.id', '=', 'ei.exam_id')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->join('staff_members as sm', 'sm.id', '=', 'ei.staff_id')
            ->when($this->status !== '', fn ($q) => $q->where('ei.role', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sm.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sm.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('sub.name', 'like', '%'.$this->search.'%');
                });
            })
            ->orderByDesc('ex.scheduled_on')
            ->orderBy('ex.starts_at')
            ->select([
                'ei.id', 'ei.role',
                'ex.scheduled_on', 'ex.starts_at',
                'sub.name as subject_name', 'cg.name as class_group_name',
                'sm.first_name', 'sm.last_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The "Seating" tab: seating is per-student, so this summarises by
     * exam + room - seats filled vs. room capacity - rather than listing
     * one row per candidate.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function seatingRows(): LengthAwarePaginator
    {
        return DB::table('exam_seatings as es')
            ->join('exams as ex', 'ex.id', '=', 'es.exam_id')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->join('rooms as r', 'r.id', '=', 'es.room_id')
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('sub.name', 'like', '%'.$this->search.'%')
                        ->orWhere('cg.name', 'like', '%'.$this->search.'%')
                        ->orWhere('r.name', 'like', '%'.$this->search.'%');
                });
            })
            ->groupBy('ex.id', 'ex.scheduled_on', 'sub.name', 'cg.name', 'r.id', 'r.name', 'r.code', 'r.capacity')
            ->orderByDesc('ex.scheduled_on')
            ->select([
                'ex.id', 'ex.scheduled_on',
                'sub.name as subject_name', 'cg.name as class_group_name',
                'r.id as room_id', 'r.name as room_name', 'r.code as room_code', 'r.capacity as room_capacity',
                DB::raw('COUNT(*) as seats_filled'),
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The KPI strip: dataset-wide numbers, never filter-dependent.
     *
     * @return array{total: int, this_week: int, unfilled_invigilators: int, seating_pending: int}
     */
    /**
     * The next exams to sit, for the rail.
     *
     * "Upcoming" means SCHEDULED FOR TODAY OR LATER and still live - a
     * cancelled exam on next Tuesday is not something to prepare for, and an
     * exam that was sat last week is not upcoming however recently it was
     * entered.
     *
     * `days_left` is computed here rather than in Blade so the view has no
     * date arithmetic in it, and it is a whole number of days from the
     * business date - an exam scheduled for today reads 0, not "-1" from a
     * timezone-naive subtraction.
     *
     * @return list<array{subject: string, class_group: string, scheduled_on: string, days_left: int}>
     */
    private function upcomingExams(): array
    {
        $today = Carbon::today();

        return DB::table('exams as ex')
            ->leftJoin('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->leftJoin('subjects as su', 'su.id', '=', 'sa.subject_id')
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->whereIn('ex.status', Exam::LIVE_STATUSES)
            ->whereDate('ex.scheduled_on', '>=', $today->toDateString())
            ->orderBy('ex.scheduled_on')
            ->orderBy('ex.starts_at')
            ->limit(5)
            ->get([
                'su.name as subject',
                'cg.name as class_group',
                'ex.scheduled_on as scheduled_on',
            ])
            ->map(static fn (object $row): array => [
                'subject' => (string) ($row->subject ?? ''),
                'class_group' => (string) ($row->class_group ?? ''),
                'scheduled_on' => (string) $row->scheduled_on,
                'days_left' => (int) $today->diffInDays(Carbon::parse($row->scheduled_on), false),
            ])
            ->all();
    }

    private function kpis(): array
    {
        $weekStart = Carbon::today()->startOfWeek()->toDateString();
        $weekEnd = Carbon::today()->endOfWeek()->toDateString();

        $total = (int) DB::table('exams')->count();

        $thisWeek = (int) DB::table('exams')
            ->whereBetween('scheduled_on', [$weekStart, $weekEnd])
            ->count();

        // "Unfilled" = live exams with no chief invigilator assigned yet.
        $unfilledInvigilators = (int) DB::table('exams as ex')
            ->whereIn('ex.status', Exam::LIVE_STATUSES)
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('exam_invigilators as ei')
                    ->whereColumn('ei.exam_id', 'ex.id')
                    ->where('ei.role', 'chief');
            })
            ->count();

        // Seating not yet generated: live exams with a room but zero seat rows.
        $seatingPending = (int) DB::table('exams as ex')
            ->whereIn('ex.status', Exam::LIVE_STATUSES)
            ->whereNotNull('ex.room_id')
            ->whereNotExists(function ($q): void {
                $q->select(DB::raw(1))
                    ->from('exam_seatings as es')
                    ->whereColumn('es.exam_id', 'ex.id');
            })
            ->count();

        return [
            'total' => $total,
            'this_week' => $thisWeek,
            'unfilled_invigilators' => $unfilledInvigilators,
            'seating_pending' => $seatingPending,
        ];
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'invigilators' => [
                ['value' => 'chief', 'label' => 'Chief'],
                ['value' => 'assistant', 'label' => 'Assistant'],
            ],
            'seating' => [],
            default => [
                ['value' => Exam::STATUS_PLANNED, 'label' => 'Planned'],
                ['value' => Exam::STATUS_SCHEDULED, 'label' => 'Scheduled'],
                ['value' => Exam::STATUS_IN_PROGRESS, 'label' => 'In progress'],
                ['value' => Exam::STATUS_MARKED, 'label' => 'Marked'],
                ['value' => Exam::STATUS_CANCELLED, 'label' => 'Cancelled'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'exams' => (int) DB::table('exams')->count(),
            'invigilators' => (int) DB::table('exam_invigilators')->count(),
            'seating' => (int) DB::table('exam_seatings')
                ->distinct()
                ->count(DB::raw('CONCAT(exam_id, "-", room_id)')),
        ];

        return view('livewire.assessment.examinations.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'upcomingExams' => $this->upcomingExams(),
            'tabCounts' => $tabCounts,
            'statusOptions' => $this->statusOptions(),
            'examOptions' => $this->examOptions(),
        ]);
    }
}
