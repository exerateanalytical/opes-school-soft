<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\ClassGroups;

use App\Modules\Academics\Actions\CreateClassGroup;
use App\Modules\Academics\Actions\CreateClassLevel;
use App\Modules\Academics\Actions\CreateStream;
use App\Modules\Academics\Actions\UpdateClassGroup;
use App\Modules\Academics\Actions\UpdateClassLevel;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Domain\Permission;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Classes for the CURRENT academic year, composing x-list-screen.
 *
 * Class groups are per-year (00-core 8), so without a current year there is
 * nothing coherent to list - the screen then explains that and points at
 * Academic Settings instead of rendering an empty table or crashing.
 *
 * Viewing needs `academics.view` (checked in mount(), mirroring the route);
 * creating needs `academics.manage`. Creation is an inline panel because only
 * `classes.index` exists in routes/web.php (Agent A1's file).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Inline create/edit panel ────────────────────────────────────────
    public bool $showForm = false;

    public ?int $editingGroupId = null;

    public string $className = '';

    public string $classLevelId = '';

    public string $streamId = '';

    public string $capacity = '';

    public string $groupStatus = 'active';

    // ── Class-level create/edit panel ───────────────────────────────────
    public bool $showLevelForm = false;

    public ?int $editingLevelId = null;

    public string $levelSectionId = '';

    public string $levelCode = '';

    public string $levelName = '';

    public string $levelNameFr = '';

    public string $levelOrderIndex = '';

    public bool $levelIsExamClass = false;

    // ── Stream create panel ─────────────────────────────────────────────
    public bool $showStreamForm = false;

    public string $streamSectionId = '';

    public string $streamCode = '';

    public string $streamName = '';

    public string $streamNameFr = '';

    public string $streamSubjectBasket = '';

    public function mount(): void
    {
        Gate::authorize(Permission::AcademicsView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['search']);
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

    public function cancelForm(): void
    {
        $this->resetForm();
    }

    public function startEditGroup(int $classGroupId): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        /** @var ClassGroup $classGroup */
        $classGroup = ClassGroup::query()->findOrFail($classGroupId);

        $this->editingGroupId = (int) $classGroup->getKey();
        $this->className = $classGroup->name;
        $this->classLevelId = (string) $classGroup->class_level_id;
        $this->streamId = $classGroup->stream_id === null ? '' : (string) $classGroup->stream_id;
        $this->capacity = (string) $classGroup->capacity;
        $this->groupStatus = $classGroup->status;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function save(CreateClassGroup $createClassGroup, UpdateClassGroup $updateClassGroup): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $year = $this->currentYear();

        if ($year === null) {
            // The button is hidden without a current year, but a stale page
            // can still post - answer with the same explanation, not a 500.
            $this->addError('className', __('opes.classes_screen.no_year'));

            return;
        }

        $validated = $this->validate([
            'className' => ['required', 'string', 'max:64'],
            'classLevelId' => ['required', 'integer', 'exists:class_levels,id'],
            'streamId' => $this->streamId === ''
                ? ['nullable']
                : ['integer', 'exists:streams,id'],
            'capacity' => ['required', 'integer', 'min:1', 'max:500'],
            'groupStatus' => ['required', 'string', 'in:active,inactive'],
        ]);

        try {
            if ($this->editingGroupId === null) {
                // Duplicate names surface as a ValidationException thrown by
                // the Action itself (it owns the UNIQUE index translation);
                // Livewire renders that on `name`, mapped under the name field.
                $createClassGroup->handle(
                    classLevelId: (int) $validated['classLevelId'],
                    academicYearId: (int) $year->getKey(),
                    name: $validated['className'],
                    capacity: (int) $validated['capacity'],
                    streamId: $this->streamId === '' ? null : (int) $this->streamId,
                );

                session()->flash('status', __('opes.classes_screen.created'));
            } else {
                /** @var ClassGroup $classGroup */
                $classGroup = ClassGroup::query()->findOrFail($this->editingGroupId);

                $updateClassGroup->handle($classGroup, [
                    'name' => $validated['className'],
                    'capacity' => (int) $validated['capacity'],
                    'stream_id' => $this->streamId === '' ? null : (int) $this->streamId,
                    'status' => $validated['groupStatus'],
                ]);

                session()->flash('status', __('opes.classes_screen.updated'));
            }
        } catch (ValidationException $exception) {
            $this->addError('className', collect($exception->errors())->collapse()->first() ?? $exception->getMessage());

            return;
        }

        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['showForm', 'editingGroupId', 'className', 'classLevelId', 'streamId', 'capacity']);
        $this->groupStatus = 'active';
        $this->resetErrorBag();
    }

    public function startCreateLevel(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->resetLevelForm();
        $this->showLevelForm = true;
    }

    public function startEditLevel(int $classLevelId): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $level = ClassLevel::query()->findOrFail($classLevelId);

        $this->editingLevelId = $classLevelId;
        $this->levelSectionId = (string) $level->school_section_id;
        $this->levelCode = $level->code;
        $this->levelName = $level->name;
        $this->levelNameFr = $level->name_fr;
        $this->levelOrderIndex = (string) $level->order_index;
        $this->levelIsExamClass = $level->is_exam_class;
        $this->showLevelForm = true;
        $this->resetErrorBag();
    }

    public function cancelLevelForm(): void
    {
        $this->resetLevelForm();
    }

    public function saveLevel(CreateClassLevel $createClassLevel, UpdateClassLevel $updateClassLevel): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'levelSectionId' => ['required', 'integer', 'exists:school_sections,id'],
            'levelCode' => ['required', 'string', 'max:32'],
            'levelName' => ['required', 'string', 'max:160'],
            'levelNameFr' => ['required', 'string', 'max:160'],
            'levelOrderIndex' => ['required', 'integer', 'min:0'],
        ], [], [
            'levelSectionId' => 'section',
            'levelCode' => 'code',
            'levelName' => 'name',
            'levelNameFr' => 'name_fr',
            'levelOrderIndex' => 'order_index',
        ]);

        try {
            if ($this->editingLevelId === null) {
                $section = SchoolSection::query()->findOrFail((int) $validated['levelSectionId']);

                $createClassLevel->handle(
                    section: $section,
                    code: $validated['levelCode'],
                    name: $validated['levelName'],
                    nameFr: $validated['levelNameFr'],
                    orderIndex: (int) $validated['levelOrderIndex'],
                    isExamClass: $this->levelIsExamClass,
                );
            } else {
                $level = ClassLevel::query()->findOrFail($this->editingLevelId);

                $updateClassLevel->handle(
                    level: $level,
                    code: $validated['levelCode'],
                    name: $validated['levelName'],
                    nameFr: $validated['levelNameFr'],
                    orderIndex: (int) $validated['levelOrderIndex'],
                    isExamClass: $this->levelIsExamClass,
                );
            }
        } catch (DomainException $exception) {
            $this->addError('levelCode', $exception->getMessage());

            return;
        } catch (UniqueConstraintViolationException) {
            // Now that the panel is actually reachable, a duplicate code is an
            // operator typo — an inline error, not a 500.
            $this->addError('levelCode', __('validation.unique', ['attribute' => 'code']));

            return;
        }

        session()->flash('status', $this->editingLevelId === null
            ? __('opes.classes_screen.level_created')
            : __('opes.classes_screen.level_updated'));
        $this->resetLevelForm();
    }

    private function resetLevelForm(): void
    {
        $this->reset([
            'showLevelForm', 'editingLevelId', 'levelSectionId', 'levelCode',
            'levelName', 'levelNameFr', 'levelOrderIndex', 'levelIsExamClass',
        ]);
        $this->resetErrorBag();
    }

    public function toggleStreamForm(): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $this->showStreamForm = ! $this->showStreamForm;

        if ($this->showStreamForm) {
            $this->resetStreamForm(keepOpen: true);
        }
    }

    public function saveStream(CreateStream $createStream): void
    {
        Gate::authorize(Permission::AcademicsManage->value);

        $validated = $this->validate([
            'streamSectionId' => ['required', 'integer', 'exists:school_sections,id'],
            'streamCode' => ['required', 'string', 'max:32'],
            'streamName' => ['required', 'string', 'max:160'],
            'streamNameFr' => ['required', 'string', 'max:160'],
        ], [], [
            'streamSectionId' => 'section',
            'streamCode' => 'code',
            'streamName' => 'name',
            'streamNameFr' => 'name_fr',
        ]);

        $basket = collect(explode(',', $this->streamSubjectBasket))
            ->map(fn (string $code): string => trim($code))
            ->filter(fn (string $code): bool => $code !== '')
            ->values()
            ->all();

        $section = SchoolSection::query()->findOrFail((int) $validated['streamSectionId']);

        try {
            $createStream->handle(
                section: $section,
                code: $validated['streamCode'],
                name: $validated['streamName'],
                nameFr: $validated['streamNameFr'],
                subjectBasket: $basket,
            );
        } catch (UniqueConstraintViolationException) {
            $this->addError('streamCode', __('validation.unique', ['attribute' => 'code']));

            return;
        }

        session()->flash('status', __('opes.classes_screen.stream_created'));
        $this->resetStreamForm();
    }

    private function resetStreamForm(bool $keepOpen = false): void
    {
        $this->reset(['streamSectionId', 'streamCode', 'streamName', 'streamNameFr', 'streamSubjectBasket']);

        if (! $keepOpen) {
            $this->showStreamForm = false;
        }

        $this->resetErrorBag();
    }

    private function currentYear(): ?AcademicYear
    {
        return AcademicYear::query()->where('is_current', true)->first();
    }

    /**
     * @return LengthAwarePaginator<int, ClassGroup>
     */
    private function classGroups(AcademicYear $year): LengthAwarePaginator
    {
        return ClassGroup::query()
            ->with(['classLevel', 'stream'])
            ->where('academic_year_id', $year->getKey())
            ->when($this->search !== '', function ($query): void {
                $query->where('name', 'like', '%'.$this->search.'%');
            })
            ->orderBy('name')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    public function render(): mixed
    {
        $year = $this->currentYear();

        return view('livewire.academics.class-groups.index', [
            'currentYear' => $year,
            'classGroups' => $year === null ? null : $this->classGroups($year),
            'levelOptions' => ClassLevel::query()->orderBy('order_index')->get(),
            'streamOptions' => Stream::query()->where('is_active', true)->orderBy('name')->get(),
            'sectionOptions' => SchoolSection::query()->orderBy('display_order')->get(),
            'canManage' => Gate::allows(Permission::AcademicsManage->value),
        ]);
    }
}
