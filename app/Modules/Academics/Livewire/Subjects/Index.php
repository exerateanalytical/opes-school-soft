<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\Subjects;

use App\Modules\Academics\Actions\AllocateSubject;
use App\Modules\Academics\Actions\CreateSubject;
use App\Modules\Academics\Actions\UpdateAllocation;
use App\Modules\Academics\Actions\UpdateSubject;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Department;
use App\Modules\Academics\Models\Stream;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\SubjectAllocation;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Subject Management (frontend images/subject management.png), composing
 * x-list-screen the same way Identity's Users\Index does.
 *
 * Viewing needs `academics.view` (checked in mount(), mirroring the route);
 * every mutation needs `academics.manage`. Create/edit is an inline panel on
 * this screen rather than a routed sibling Form component because routes are
 * Agent A1's file and only `subjects.index` exists - a page component nothing
 * routes to would be dead code.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Inline create/edit panel ────────────────────────────────────────
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $subjectCode = '';

    public string $subjectName = '';

    public string $subjectNameFr = '';

    public string $departmentId = '';

    public bool $subjectActive = true;

    // ── Allocation panel (per-subject, current academic year) ────────────
    public ?int $allocationsForSubjectId = null;

    public ?int $editingAllocationId = null;

    public string $allocCoefficient = '';

    public bool $allocIsOptional = false;

    public bool $allocCountsTowardAverage = true;

    public bool $allocIsActive = true;

    // ── New-allocation form (AllocateSubject) ────────────────────────────
    public bool $showNewAllocationForm = false;

    public string $newAllocClassLevelId = '';

    public string $newAllocStreamId = '';

    public string $newAllocCoefficient = '1';

    public bool $newAllocIsOptional = false;

    public bool $newAllocCountsTowardAverage = true;

    public function mount(): void
    {
        Gate::authorize(Permission::AcademicsView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->resetPage();
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function startCreate(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->resetForm();
        $this->showForm = true;
    }

    public function startEdit(int $subjectId): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        /** @var Subject $subject */
        $subject = Subject::query()->findOrFail($subjectId);

        $this->editingId = (int) $subject->getKey();
        $this->subjectCode = $subject->code;
        $this->subjectName = $subject->name;
        $this->subjectNameFr = $subject->name_fr ?? '';
        $this->departmentId = $subject->department_id === null ? '' : (string) $subject->department_id;
        $this->subjectActive = $subject->is_active;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function save(CreateSubject $createSubject, UpdateSubject $updateSubject): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'subjectCode' => [
                'required', 'string', 'max:32',
                Rule::unique('subjects', 'code')->ignore($this->editingId),
            ],
            'subjectName' => ['required', 'string', 'max:160'],
            'subjectNameFr' => ['nullable', 'string', 'max:160'],
            // The wire:model value is a string, and '' (the "no department"
            // option) is not null, so the integer rules only apply when a
            // department was actually chosen.
            'departmentId' => $this->departmentId === ''
                ? ['nullable']
                : ['integer', 'exists:departments,id'],
            'subjectActive' => ['boolean'],
        ]);

        $departmentId = $this->departmentId === '' ? null : (int) $this->departmentId;

        if ($this->editingId === null) {
            $createSubject->handle(
                code: $validated['subjectCode'],
                name: $validated['subjectName'],
                nameFr: $this->subjectNameFr === '' ? null : $this->subjectNameFr,
                departmentId: $departmentId,
                isActive: $this->subjectActive,
            );

            session()->flash('status', __('opes.subjects_screen.created'));
        } else {
            /** @var Subject $subject */
            $subject = Subject::query()->findOrFail($this->editingId);

            $updateSubject->handle($subject, [
                'code' => $validated['subjectCode'],
                'name' => $validated['subjectName'],
                'name_fr' => $this->subjectNameFr === '' ? null : $this->subjectNameFr,
                'department_id' => $departmentId,
                'is_active' => $this->subjectActive,
            ]);

            session()->flash('status', __('opes.subjects_screen.updated'));
        }

        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset([
            'showForm', 'editingId', 'subjectCode', 'subjectName',
            'subjectNameFr', 'departmentId', 'subjectActive',
        ]);
        $this->resetErrorBag();
    }

    public function toggleAllocations(int $subjectId): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->allocationsForSubjectId = $this->allocationsForSubjectId === $subjectId ? null : $subjectId;
        $this->editingAllocationId = null;
        $this->showNewAllocationForm = false;
        $this->resetErrorBag();
    }

    public function toggleNewAllocationForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showNewAllocationForm = ! $this->showNewAllocationForm;

        if ($this->showNewAllocationForm) {
            $this->editingAllocationId = null;
            $this->reset([
                'newAllocClassLevelId', 'newAllocStreamId',
                'newAllocIsOptional', 'newAllocCountsTowardAverage',
            ]);
            $this->newAllocCoefficient = '1';
        }

        $this->resetErrorBag();
    }

    /**
     * Put the expanded subject on a class level for the CURRENT academic
     * year. The screen only ever shows current-year allocations, so that is
     * the only year it may write to.
     *
     * AllocateSubject also accepts required_components, subject_group_id,
     * max_score_override and the effective-period window; Phase 1 has no
     * screen for any of them, so they stay at the Action's own defaults
     * rather than being half-collected here.
     */
    public function saveNewAllocation(AllocateSubject $allocateSubject): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        if ($this->allocationsForSubjectId === null) {
            return;
        }

        $year = AcademicYear::query()->where('is_current', true)->first();

        if ($year === null) {
            $this->addError('newAllocClassLevelId', __('opes.subjects_screen.allocation_needs_year'));

            return;
        }

        $validated = $this->validate([
            'newAllocClassLevelId' => ['required', 'integer', 'exists:class_levels,id'],
            'newAllocStreamId' => $this->newAllocStreamId === ''
                ? ['nullable']
                : ['integer', 'exists:streams,id'],
            'newAllocCoefficient' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'newAllocIsOptional' => ['boolean'],
            'newAllocCountsTowardAverage' => ['boolean'],
        ], [], [
            'newAllocClassLevelId' => 'class level',
            'newAllocStreamId' => 'stream',
            'newAllocCoefficient' => 'coefficient',
        ]);

        try {
            $allocateSubject->handle(
                academicYearId: (int) $year->getKey(),
                classLevelId: (int) $validated['newAllocClassLevelId'],
                streamId: $this->newAllocStreamId === '' ? null : (int) $this->newAllocStreamId,
                subjectId: $this->allocationsForSubjectId,
                coefficient: (string) $validated['newAllocCoefficient'],
                isOptional: $this->newAllocIsOptional,
                countsTowardAverage: $this->newAllocCountsTowardAverage,
            );
        } catch (DomainException $exception) {
            // Negative coefficient / already-allocated, phrased by the Action.
            $this->addError('newAllocCoefficient', $exception->getMessage());

            return;
        }

        session()->flash('status', __('opes.subjects_screen.allocation_created'));
        $this->showNewAllocationForm = false;
        $this->resetErrorBag();
    }

    public function startEditAllocation(int $allocationId): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        /** @var SubjectAllocation $allocation */
        $allocation = SubjectAllocation::query()->findOrFail($allocationId);

        $this->editingAllocationId = (int) $allocation->getKey();
        $this->allocCoefficient = (string) $allocation->coefficient;
        $this->allocIsOptional = $allocation->is_optional;
        $this->allocCountsTowardAverage = $allocation->counts_toward_average;
        $this->allocIsActive = $allocation->is_active;
        $this->resetErrorBag();
    }

    public function cancelAllocationForm(): void
    {
        $this->editingAllocationId = null;
        $this->resetErrorBag();
    }

    public function saveAllocation(UpdateAllocation $updateAllocation): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        if ($this->editingAllocationId === null) {
            return;
        }

        $validated = $this->validate([
            'allocCoefficient' => ['required', 'numeric', 'min:0', 'max:99.99'],
            'allocIsOptional' => ['boolean'],
            'allocCountsTowardAverage' => ['boolean'],
            'allocIsActive' => ['boolean'],
        ], [], [
            'allocCoefficient' => 'coefficient',
        ]);

        /** @var SubjectAllocation $allocation */
        $allocation = SubjectAllocation::query()->findOrFail($this->editingAllocationId);

        try {
            $updateAllocation->handle($allocation, [
                'coefficient' => (string) $validated['allocCoefficient'],
                'is_optional' => $this->allocIsOptional,
                'counts_toward_average' => $this->allocCountsTowardAverage,
                'is_active' => $this->allocIsActive,
            ]);
        } catch (DomainException $exception) {
            $this->addError('allocCoefficient', $exception->getMessage());

            return;
        }

        session()->flash('status', __('opes.subjects_screen.allocation_updated'));
        $this->editingAllocationId = null;
        $this->resetErrorBag();
    }

    /**
     * Allocations for the subject currently expanded, in the current
     * academic year - the only scope with live coefficients to edit.
     *
     * @return Collection<int, SubjectAllocation>
     */
    private function allocationsForExpandedSubject(): Collection
    {
        if ($this->allocationsForSubjectId === null) {
            return collect();
        }

        $year = AcademicYear::query()->where('is_current', true)->first();

        if ($year === null) {
            return collect();
        }

        return SubjectAllocation::query()
            ->with('classLevel')
            ->where('subject_id', $this->allocationsForSubjectId)
            ->where('academic_year_id', $year->getKey())
            ->orderBy('class_level_id')
            ->get();
    }

    /**
     * @return LengthAwarePaginator<int, Subject>
     */
    /**
     * The subject KPI strip.
     *
     * WHERE THIS DIVERGES FROM THE REFERENCE, AND WHY. The mockup counts
     * "Core", "Elective" and "Practical" subjects. `subjects` carries no such
     * attribute - it has code, name, department and is_active, and nothing
     * else. What the schema DOES record is per-allocation: a subject is
     * allocated to a level/stream and that allocation is compulsory or
     * optional (`subject_allocations.is_optional`).
     *
     * So core/elective are derived from the allocations, which is the same
     * distinction the reference is drawing; "practical" has no counterpart at
     * all and is not invented. In its place the strip carries UNALLOCATED,
     * which is the operationally useful number the reference does not show: a
     * subject nobody has put on any timetable.
     *
     * @return array{total: int, core: int, elective: int, unallocated: int, teachers: int}
     */
    private function subjectStats(): array
    {
        $total = (int) DB::table('subjects')->count();

        $allocated = DB::table('subject_allocations')
            ->where('is_active', true)
            ->distinct()
            ->pluck('subject_id');

        return [
            'total' => $total,

            'core' => (int) DB::table('subject_allocations')
                ->where('is_active', true)
                ->where('is_optional', false)
                ->distinct()
                ->count('subject_id'),

            'elective' => (int) DB::table('subject_allocations')
                ->where('is_active', true)
                ->where('is_optional', true)
                ->distinct()
                ->count('subject_id'),

            'unallocated' => max(0, $total - $allocated->count()),

            // Teachers are attached to an ALLOCATION, not to a subject, and
            // through users rather than staff_members. Counted distinct so a
            // teacher carrying four allocations is one teacher.
            'teachers' => (int) DB::table('subject_allocation_teachers')
                ->distinct()
                ->count('user_id'),
        ];
    }

    /**
     * Subjects per department, for the rail.
     *
     * @return list<array{label: string, value: int}>
     */
    private function departmentDistribution(): array
    {
        return DB::table('departments as d')
            ->join('subjects as s', 's.department_id', '=', 'd.id')
            ->groupBy('d.id', 'd.name')
            ->orderByDesc(DB::raw('COUNT(s.id)'))
            ->selectRaw('d.name as label, COUNT(s.id) as value')
            ->get()
            ->map(static fn (object $r): array => [
                'label' => (string) $r->label,
                'value' => (int) $r->value,
            ])
            ->all();
    }

    private function subjects(): LengthAwarePaginator
    {
        return Subject::query()
            ->when($this->search !== '', function ($query): void {
                $query->where(function ($inner): void {
                    $inner->where('code', 'like', '%'.$this->search.'%')
                        ->orWhere('name', 'like', '%'.$this->search.'%')
                        ->orWhere('name_fr', 'like', '%'.$this->search.'%');
                });
            })
            ->when($this->status !== '', function ($query): void {
                $query->where('is_active', $this->status === 'active');
            })
            ->orderBy('code')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    public function render(): mixed
    {
        // Department names fetched as one keyed map rather than through a
        // per-row relation: Subject carries department_id but Phase 1's model
        // defines no department() relation, and N+1 lookups in Blade would be
        // worse than this single bounded query (departments are a short list).
        return view('livewire.academics.subjects.index', [
            'subjects' => $this->subjects(),
            'departmentNames' => Department::query()->orderBy('name')->pluck('name', 'id'),
            'totalSubjects' => Subject::query()->count(),
            'subjectStats' => $this->subjectStats(),
            'departmentDistribution' => $this->departmentDistribution(),
            'canManage' => Gate::allows(Permission::AcademicsManage->value),
            'expandedAllocations' => $this->allocationsForExpandedSubject(),
            'levelOptions' => ClassLevel::query()->orderBy('order_index')->get(),
            'streamOptions' => Stream::query()->where('is_active', true)->orderBy('name')->get(),
        ]);
    }
}
