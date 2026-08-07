<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Students;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\Gender;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Student;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Student Management, docs/specs/07-students.md 11.1.
 *
 * Composes x-list-screen exactly as the Users and Classes screens do, so the
 * three read as one product.
 *
 * ── Why the class column is a query-builder sub-select ────────────────────
 *
 * A student's class is NOT a column on `students` (07-students 3.1 removes it
 * deliberately, C4): it is the class group of the OPEN EnrollmentSegment of
 * the student's live Enrollment. Resolving it needs `class_groups`, which is
 * an Academics table - and tests/Architecture/ModuleBoundaryTest.php forbids
 * this module from using App\Modules\Academics\Models at all. The Enrollment
 * model's own header states the sanctioned way round that: read the other
 * module's data "through the query builder, which involves no cross-module
 * class at all". So the class name arrives as a correlated sub-select rather
 * than through an Eloquent relation, and one query still serves the page.
 *
 * ── Guardians are absent from this screen on purpose ──────────────────────
 *
 * The mockup's row has no guardian column and the primary guardian lives on
 * `student_guardians`, which belongs to the Guardians module. Reaching it from
 * here would either break the boundary or reimplement the 7.3 validity
 * predicate. The profile screen renders it through a Guardians-owned nested
 * component instead.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        // Mirrors routes/web.php. Hiding the nav item is presentation; the
        // component refuses on its own (00-core 6.2).
        Gate::authorize(Permission::StudentsView->value);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status', 'classGroup']);
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

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /**
     * The status tabs of 11.1 drive the same property the select does, so the
     * two can never disagree about what is on screen.
     */
    public function selectStatus(string $status): void
    {
        $this->status = StudentStatus::tryFrom($status) !== null ? $status : '';
        $this->resetPage();
    }

    /**
     * The open segment of a live enrollment - "what class is this student in
     * today" (07-students 5.2).
     *
     * @return QueryBuilder
     */
    private function currentClassSubQuery(): QueryBuilder
    {
        return DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereColumn('enr.student_id', 'students.id')
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->limit(1);
    }

    /**
     * @return LengthAwarePaginator<int, Student>
     */
    private function students(): LengthAwarePaginator
    {
        $query = Student::query()
            ->notArchived()
            ->select('students.*')
            ->addSelect([
                'current_class_name' => $this->currentClassSubQuery()->select('cg.name'),
            ]);

        $query->when($this->search !== '', function (Builder $builder): void {
            $term = '%'.$this->search.'%';

            $builder->where(function (Builder $inner) use ($term): void {
                $inner->where('first_name', 'like', $term)
                    ->orWhere('middle_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('preferred_name', 'like', $term)
                    // Both identifiers the mockup's placeholder promises.
                    ->orWhere('matricule', 'like', $term)
                    ->orWhere('admission_no', 'like', $term);
            });
        });

        $query->when(
            StudentStatus::tryFrom($this->status) !== null,
            fn (Builder $builder) => $builder->where('status', '=', $this->status),
        );

        $query->when($this->classGroup !== '', function (Builder $builder): void {
            $builder->whereExists(function (QueryBuilder $inner): void {
                $inner->selectRaw('1')
                    ->from('enrollment_segments as fseg')
                    ->join('enrollments as fenr', 'fenr.id', '=', 'fseg.enrollment_id')
                    ->whereColumn('fenr.student_id', 'students.id')
                    ->whereNull('fseg.ends_on')
                    ->whereIn('fenr.status', ['pending', 'active', 'suspended'])
                    ->where('fseg.class_group_id', '=', (int) $this->classGroup);
            });
        });

        return $query
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->paginate($this->perPage, ['*'], 'page', $this->page);
    }

    /**
     * Dataset-wide counts, one grouped query. 11.1 asks for a PERSISTED daily
     * count rather than a live COUNT(*) over 1,245 rows; no daily-snapshot
     * table exists in Phase 2, so this is the live count and the view says so.
     * Inventing a trend line from data that was never captured would be worse.
     *
     * @return array<string, int>
     */
    private function statusCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Student::query()
            ->notArchived()
            ->toBase()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function genderCounts(): array
    {
        /** @var array<string, int> $counts */
        $counts = Student::query()
            ->notArchived()
            ->toBase()
            ->selectRaw('gender, COUNT(*) as aggregate')
            ->groupBy('gender')
            ->pluck('aggregate', 'gender')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();

        return $counts;
    }

    /**
     * Class groups of the CURRENT academic year, for the filter select and the
     * "Students by Class" rail. Query builder, not the Academics models - see
     * the class header.
     *
     * @return list<array{id: int, name: string, students: int}>
     */
    private function classGroupOptions(): array
    {
        $rows = DB::table('class_groups as cg')
            ->join('academic_years as ay', 'ay.id', '=', 'cg.academic_year_id')
            ->where('ay.is_current', '=', true)
            ->leftJoin('enrollment_segments as seg', function ($join): void {
                $join->on('seg.class_group_id', '=', 'cg.id')->whereNull('seg.ends_on');
            })
            ->leftJoin('enrollments as enr', function ($join): void {
                $join->on('enr.id', '=', 'seg.enrollment_id')
                    ->whereIn('enr.status', ['pending', 'active', 'suspended']);
            })
            ->groupBy('cg.id', 'cg.name')
            ->orderBy('cg.name')
            ->selectRaw('cg.id as id, cg.name as name, COUNT(enr.id) as students')
            ->get();

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string, students: int|string} $row */
            $options[] = [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'students' => (int) $row->students,
            ];
        }

        return $options;
    }

    /**
     * @return list<StudentStatus>
     */
    private function statusOptions(): array
    {
        return StudentStatus::cases();
    }

    public function render(): mixed
    {
        $statusCounts = $this->statusCounts();
        $genderCounts = $this->genderCounts();

        return view('livewire.students.index', [
            'students' => $this->students(),
            'statusCounts' => $statusCounts,
            'totalStudents' => array_sum($statusCounts),
            'maleCount' => $genderCounts[Gender::Male->value] ?? 0,
            'femaleCount' => $genderCounts[Gender::Female->value] ?? 0,
            'statusOptions' => $this->statusOptions(),
            'classGroupOptions' => $this->classGroupOptions(),
        ]);
    }
}
