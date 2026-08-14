<?php

declare(strict_types=1);

namespace App\Modules\Curriculum\Livewire;

use App\Modules\Academics\Domain\SubSystem;
use App\Modules\Curriculum\Actions\CreateCurriculum;
use App\Modules\Curriculum\Domain\CurriculumPermission;
use App\Modules\Curriculum\Domain\CurriculumStatus;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Curriculum Framework at /curriculum, gated `curriculum.view`, composing
 * x-list-screen (09-ui 4): KPI strip (curricula / published / drafts /
 * competencies), filter bar (subject / class level / status), paginated
 * table of curriculum versions.
 *
 * Cross-module reads (subject names, class levels, academic years) go
 * through DB::table joins only - never another module's Models
 * (ModuleBoundaryTest). One paginated query per render plus the KPI
 * aggregates (00-core 6.2 rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $subject = '';

    #[Url]
    public string $level = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── New Curriculum form ─────────────────────────────────────────────
    public bool $showCreateForm = false;

    public string $createFormSubjectId = '';

    public string $createFormClassLevelId = '';

    public string $createFormAcademicYearId = '';

    public string $createFormSubSystem = 'anglophone';

    public string $createFormTitle = '';

    public string $createFormDescription = '';

    public function mount(): void
    {
        Gate::authorize(CurriculumPermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['subject', 'level', 'status', 'search']);
        $this->resetPage();
    }

    public function updatedSubject(): void
    {
        $this->resetPage();
    }

    public function updatedLevel(): void
    {
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

    public function toggleCreateForm(): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->showCreateForm = ! $this->showCreateForm;
    }

    public function saveCurriculum(CreateCurriculum $createCurriculum): void
    {
        Gate::authorize(CurriculumPermission::MANAGE);

        $this->validate([
            'createFormSubjectId' => ['required', 'integer', 'min:1'],
            'createFormClassLevelId' => ['required', 'integer', 'min:1'],
            'createFormAcademicYearId' => ['required', 'integer', 'min:1'],
            'createFormSubSystem' => ['required', 'string', 'in:anglophone,francophone'],
            'createFormTitle' => ['required', 'string', 'max:160'],
            'createFormDescription' => ['nullable', 'string', 'max:500'],
        ], [], [
            'createFormSubjectId' => 'subject',
            'createFormClassLevelId' => 'class level',
            'createFormAcademicYearId' => 'academic year',
            'createFormSubSystem' => 'sub-system',
            'createFormTitle' => 'title',
            'createFormDescription' => 'description',
        ]);

        try {
            $curriculum = $createCurriculum->handle([
                'subject_id' => (int) $this->createFormSubjectId,
                'class_level_id' => (int) $this->createFormClassLevelId,
                'academic_year_id' => (int) $this->createFormAcademicYearId,
                'sub_system' => $this->createFormSubSystem,
                'title' => $this->createFormTitle,
                'description' => $this->createFormDescription !== '' ? $this->createFormDescription : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('createForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('createFormTitle', $e->getMessage());

            return;
        }

        $this->redirectRoute('curriculum.show', ['curriculum' => $curriculum->getKey()]);
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
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
        return DB::table('curricula as c')
            ->join('subjects as s', 's.id', '=', 'c.subject_id')
            ->join('class_levels as cl', 'cl.id', '=', 'c.class_level_id')
            ->join('academic_years as ay', 'ay.id', '=', 'c.academic_year_id')
            ->when($this->subject !== '', fn ($q) => $q->where('c.subject_id', (int) $this->subject))
            ->when($this->level !== '', fn ($q) => $q->where('c.class_level_id', (int) $this->level))
            ->when($this->status !== '', fn ($q) => $q->where('c.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('c.title', 'like', '%'.$this->search.'%')
                        ->orWhere('s.name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.code', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('s.name')
            ->orderBy('cl.order_index')
            ->orderByDesc('c.version')
            ->select([
                'c.id', 'c.title', 'c.sub_system', 'c.version', 'c.status',
                's.name as subject_name', 's.code as subject_code',
                'cl.name as level_name', 'ay.code as year_code',
            ])
            ->selectSub(
                DB::table('curriculum_units')->whereColumn('curriculum_id', 'c.id')->selectRaw('COUNT(*)'),
                'units_count'
            )
            ->selectSub(
                DB::table('competencies')->whereColumn('curriculum_id', 'c.id')->selectRaw('COUNT(*)'),
                'competencies_count'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * Dataset-wide KPI numbers, never filter-dependent.
     *
     * @return array{curricula: int, published: int, drafts: int, competencies: int}
     */
    private function kpis(): array
    {
        return [
            'curricula' => (int) DB::table('curricula')->count(),
            'published' => (int) DB::table('curricula')->where('status', CurriculumStatus::Published->value)->count(),
            'drafts' => (int) DB::table('curricula')->where('status', CurriculumStatus::Draft->value)->count(),
            'competencies' => (int) DB::table('competencies')->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function subjectOptions(): array
    {
        $options = [];

        foreach (DB::table('subjects')->orderBy('name')->get(['id', 'code', 'name']) as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code.' - '.$row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function levelOptions(): array
    {
        $options = [];

        foreach (DB::table('class_levels')->orderBy('order_index')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function yearOptions(): array
    {
        $options = [];

        foreach (DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'code']) as $row) {
            /** @var object{id: int|string, code: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function subSystemOptions(): array
    {
        $options = [];

        foreach (SubSystem::cases() as $case) {
            $options[] = ['value' => $case->value, 'label' => ucfirst($case->value)];
        }

        return $options;
    }

    public function render(): mixed
    {
        return view('livewire.curriculum.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'subjectOptions' => $this->subjectOptions(),
            'levelOptions' => $this->levelOptions(),
            'yearOptions' => $this->yearOptions(),
            'subSystemOptions' => $this->subSystemOptions(),
            'canManage' => Gate::allows(CurriculumPermission::MANAGE),
        ]);
    }
}
