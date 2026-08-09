<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Discipline;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Welfare\Actions\OpenDisciplineCase;
use App\Modules\Welfare\Domain\DisciplineCaseStatus;
use App\Modules\Welfare\Domain\DisciplineVisibility;
use App\Modules\Welfare\Models\DisciplineCase;
use App\Modules\Welfare\Models\DisciplineCategory;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use stdClass;

/**
 * Discipline case list at /welfare/discipline — 09-ui §2 places it "not in
 * the sidebar, reached from within". No mockup ships for this screen, so it
 * mirrors the Attendance Management chrome from the same phase: breadcrumb,
 * KPI row, status tabs, filterable table.
 *
 * Student and class names cross the module boundary as DB::table joins —
 * Student/Enrollment Models are Students-owned (ModuleBoundaryTest).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** @var list<string> */
    public const TABS = ['', 'open', 'under_investigation', 'resolved', 'dismissed'];

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $categoryId = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Open Case form ──────────────────────────────────────────────────
    public bool $showOpenForm = false;

    public string $studentQuery = '';

    public string $studentId = '';

    public string $formCategoryId = '';

    public string $occurredOn = '';

    public string $description = '';

    public string $visibility = 'internal';

    public bool $isPositive = false;

    public function mount(): void
    {
        Gate::authorize(Permission::DisciplineView->value);

        if (! in_array($this->status, self::TABS, true)) {
            $this->status = '';
        }
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedCategoryId(): void
    {
        $this->page = 1;
    }

    public function selectStatus(string $status): void
    {
        $this->status = in_array($status, self::TABS, true) ? $status : '';
        $this->page = 1;
    }

    public function toggleOpenForm(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        $this->showOpenForm = ! $this->showOpenForm;

        if ($this->showOpenForm && $this->occurredOn === '') {
            $this->occurredOn = now()->toDateString();
        }
    }

    public function openCase(): void
    {
        Gate::authorize(Permission::DisciplineManage->value);

        $this->validate([
            'studentId' => ['required', 'integer'],
            'formCategoryId' => ['required', 'integer'],
            'occurredOn' => ['required', 'date'],
            'description' => ['required', 'string', 'min:3'],
            'visibility' => ['required', 'in:internal,guardian'],
        ], [], [
            'studentId' => __('discipline.field_student'),
            'formCategoryId' => __('discipline.field_category'),
            'occurredOn' => __('discipline.field_occurred_on'),
            'description' => __('discipline.field_description'),
        ]);

        $case = app(OpenDisciplineCase::class)->handle(
            studentId: (int) $this->studentId,
            categoryId: (int) $this->formCategoryId,
            occurredOn: $this->occurredOn,
            description: $this->description,
            visibility: DisciplineVisibility::from($this->visibility),
            isPositive: $this->isPositive,
        );

        $this->reset([
            'showOpenForm', 'studentQuery', 'studentId', 'formCategoryId',
            'description', 'isPositive',
        ]);
        $this->visibility = 'internal';
        $this->occurredOn = '';

        $this->redirect('/welfare/discipline/'.$case->getKey());
    }

    /**
     * Live student picker for the Open Case form: name/matricule/admission
     * number, capped at 8 candidates.
     *
     * @return list<stdClass>
     */
    private function studentCandidates(): array
    {
        if (mb_strlen(trim($this->studentQuery)) < 2) {
            return [];
        }

        $term = '%'.trim($this->studentQuery).'%';

        /** @var list<stdClass> */
        return DB::table('students')
            ->where(function ($query) use ($term): void {
                $query->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('matricule', 'like', $term)
                    ->orWhere('admission_no', 'like', $term);
            })
            ->orderBy('last_name')
            ->limit(8)
            ->get(['id', 'first_name', 'last_name', 'matricule'])
            ->values()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, DisciplineCase>
     */
    private function cases(): LengthAwarePaginator
    {
        $query = DisciplineCase::query()
            ->with('category')
            ->select('discipline_cases.*')
            ->addSelect([
                'student_name' => DB::table('students')
                    ->whereColumn('students.id', 'discipline_cases.student_id')
                    ->selectRaw("CONCAT(first_name, ' ', last_name)")
                    ->limit(1),
                'student_matricule' => DB::table('students')
                    ->whereColumn('students.id', 'discipline_cases.student_id')
                    ->select('matricule')
                    ->limit(1),
                'class_group_name' => DB::table('enrollment_segments as seg')
                    ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
                    ->whereColumn('seg.enrollment_id', 'discipline_cases.enrollment_id')
                    ->whereNull('seg.ends_on')
                    ->select('cg.name')
                    ->limit(1),
            ]);

        $query->when(
            DisciplineCaseStatus::tryFrom($this->status) !== null,
            fn (Builder $builder) => $builder->where('discipline_cases.status', $this->status),
        );

        $query->when($this->categoryId !== '', fn (Builder $builder) => $builder
            ->where('discipline_cases.discipline_category_id', (int) $this->categoryId));

        $query->when($this->search !== '', function (Builder $builder): void {
            $term = '%'.$this->search.'%';

            $builder->whereExists(function ($inner) use ($term): void {
                $inner->selectRaw('1')
                    ->from('students')
                    ->whereColumn('students.id', 'discipline_cases.student_id')
                    ->where(function ($nested) use ($term): void {
                        $nested->where('first_name', 'like', $term)
                            ->orWhere('last_name', 'like', $term)
                            ->orWhere('matricule', 'like', $term)
                            ->orWhere('admission_no', 'like', $term);
                    });
            });
        });

        return $query
            ->orderByDesc('occurred_on')
            ->orderByDesc('id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    /**
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = DisciplineCase::query()
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    public function render(): mixed
    {
        $statusCounts = $this->statusCounts();

        $positiveCount = DisciplineCase::query()->where('is_positive', true)->count();

        $unacknowledged = (int) DB::table('discipline_sanctions')
            ->whereNull('acknowledged_at')
            ->count();

        return view('livewire.welfare.discipline.index', [
            'cases' => $this->cases(),
            'statusCounts' => $statusCounts,
            'totalCases' => array_sum($statusCounts),
            'positiveCount' => $positiveCount,
            'unacknowledged' => $unacknowledged,
            'categories' => DisciplineCategory::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'severity']),
            'studentCandidates' => $this->studentCandidates(),
        ]);
    }
}
