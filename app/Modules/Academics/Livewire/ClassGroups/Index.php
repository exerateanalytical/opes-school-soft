<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\ClassGroups;

use App\Modules\Academics\Actions\CreateClassGroup;
use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\Stream;
use App\Modules\Identity\Domain\Permission;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
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

    // ── Inline create panel ─────────────────────────────────────────────
    public bool $showForm = false;

    public string $className = '';

    public string $classLevelId = '';

    public string $streamId = '';

    public string $capacity = '';

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

    public function save(CreateClassGroup $createClassGroup): void
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
        ]);

        // Duplicate names surface as a ValidationException thrown by the
        // Action itself (it owns the UNIQUE index translation); Livewire
        // renders that on `name`, which the Blade maps under the name field.
        $createClassGroup->handle(
            classLevelId: (int) $validated['classLevelId'],
            academicYearId: (int) $year->getKey(),
            name: $validated['className'],
            capacity: (int) $validated['capacity'],
            streamId: $this->streamId === '' ? null : (int) $this->streamId,
        );

        session()->flash('status', __('opes.classes_screen.created'));
        $this->resetForm();
    }

    private function resetForm(): void
    {
        $this->reset(['showForm', 'className', 'classLevelId', 'streamId', 'capacity']);
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
            'canManage' => Gate::allows(Permission::AcademicsManage->value),
        ]);
    }
}
