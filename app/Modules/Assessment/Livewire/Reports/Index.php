<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Livewire\Reports;

use App\Modules\Assessment\Models\ClassStatistic;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Assessment Reports at /reports/assessment (route wired centrally), gated
 * `reports.view`, mirroring Welfare\Livewire\Transport\Index and
 * Assessment\Livewire\Results\Index's tabbed-screen convention: KPI strip,
 * `#[Url]` filters, `selectTab`, `DB::table()` per tab, one paginated query
 * per render, plus Export Excel / Export PDF / Print actions shared with the
 * other ten report-cluster screens (App\Modules\Reporting\Support\ExcelExport
 * / PdfExport).
 *
 * Four tabs, one paginated DB::table() query each:
 *
 *   - Mark Sheet: raw marks per class/subject/period, from `marks` joined to
 *     enrollments/students/subject_allocations/subjects (state = 'scored'
 *     rows carry a score; other states show their state instead).
 *   - Results Register: one row per student per period, from
 *     `period_results` - average, rank, pass/fail per class/period.
 *   - Class Statistics Summary: the class-level aggregate row per class per
 *     period, from `class_statistics` (subject_allocation_id =
 *     ClassStatistic::GENERAL, mirroring Results\Index's statisticRows()).
 *   - Exam Schedule Register: from `exams` joined to subject_allocations/
 *     subjects/class_groups/rooms - subject, class, date, room, status.
 *
 * Cross-module reads go through DB::table joins only - never another
 * module's Models (ModuleBoundaryTest). Export methods reuse the exact same
 * filtered query as the on-screen tab, unpaginated, capped by
 * `EXPORT_ROW_LIMIT` so a browse-scale export cannot pull the whole table
 * into memory (00-core 6.2 rule 8's spirit for exports).
 */
#[Layout('layouts.app')]
#[Title('Assessment Reports')]
final class Index extends Component
{
    private const int EXPORT_ROW_LIMIT = 5000;

    /** Which report is showing: marksheet | results | statistics | exams. */
    #[Url]
    public string $tab = 'marksheet';

    #[Url]
    public string $period = '';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $subject = '';

    #[Url]
    public string $search = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['marksheet', 'results', 'statistics', 'exams'], true)
            ? $tab
            : 'marksheet';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['period', 'classGroup', 'subject', 'search']);
        $this->resetPage();
    }

    public function updatedPeriod(): void
    {
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedSubject(): void
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

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::ReportsView);

        [$title, $headers, $rows, $slug] = $this->exportData();

        return ExcelExport::download($title, $headers, $rows, $slug.'.xlsx');
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::ReportsView);

        [$title, $headers, $rows, $slug] = $this->exportData();

        return PdfExport::download($title, $headers, $rows, $slug.'.pdf', 'landscape');
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: list<list<mixed>>, 3: string}
     */
    private function exportData(): array
    {
        return match ($this->tab) {
            'results' => [
                'Results Register',
                ['Student', 'Matricule', 'Class', 'Period', 'Average', 'Rank', 'Result'],
                $this->resultsExportRows(),
                'results-register',
            ],
            'statistics' => [
                'Class Statistics Summary',
                ['Class', 'Period', 'Students', 'Mean', 'Lowest', 'Highest', 'Pass Rate (%)'],
                $this->statisticsExportRows(),
                'class-statistics-summary',
            ],
            'exams' => [
                'Exam Schedule Register',
                ['Subject', 'Class', 'Date', 'Time', 'Room', 'Status'],
                $this->examsExportRows(),
                'exam-schedule-register',
            ],
            default => [
                'Mark Sheet',
                ['Student', 'Matricule', 'Subject', 'Class', 'Period', 'Score', 'State'],
                $this->markSheetExportRows(),
                'mark-sheet',
            ],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'results' => $this->resultsQuery()->paginate($this->perPage, page: $this->page),
            'statistics' => $this->statisticsQuery()->paginate($this->perPage, page: $this->page),
            'exams' => $this->examsQuery()->paginate($this->perPage, page: $this->page),
            default => $this->markSheetQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function markSheetQuery()
    {
        return DB::table('marks as m')
            ->join('enrollments as e', 'e.id', '=', 'm.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('subject_allocations as sa', 'sa.id', '=', 'm.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'm.assessment_period_id')
            // The student's CURRENT class group: enrollments no longer
            // carries class_group_id directly (it moved to
            // enrollment_segments - see Marks\Entry::openSegmentOfClassGroup),
            // so the open (ends_on IS NULL) segment is the source of truth.
            ->join('enrollment_segments as seg', function ($join): void {
                $join->on('seg.enrollment_id', '=', 'e.id')->whereNull('seg.ends_on');
            })
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->when($this->period !== '', fn ($q) => $q->where('m.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('cg.id', (int) $this->classGroup))
            ->when($this->subject !== '', fn ($q) => $q->where('sa.subject_id', (int) $this->subject))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->orderBy('sub.name')
            ->orderBy('s.last_name')
            ->select([
                'm.id', 'm.score', 'm.state',
                's.first_name', 's.last_name', 's.matricule',
                'sub.name as subject_name', 'cg.name as class_group_name', 'ap.name as period_name',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function resultsQuery()
    {
        return DB::table('period_results as pr')
            ->join('enrollments as e', 'e.id', '=', 'pr.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('class_groups as cg', 'cg.id', '=', 'pr.class_group_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'pr.assessment_period_id')
            ->when($this->period !== '', fn ($q) => $q->where('pr.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('pr.class_group_id', (int) $this->classGroup))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->orderByRaw('pr.rank_position IS NULL')
            ->orderBy('pr.rank_position')
            ->select([
                'pr.id', 'pr.general_average_rounded', 'pr.rank_position', 'pr.rank_denominator',
                'pr.is_pass', 'pr.is_ranked', 'pr.nc_reason',
                'cg.name as class_group_name', 'ap.name as period_name',
                's.first_name', 's.last_name', 's.matricule',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function statisticsQuery()
    {
        return DB::table('class_statistics as cs')
            ->join('class_groups as cg', 'cg.id', '=', 'cs.class_group_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'cs.assessment_period_id')
            ->where('cs.subject_allocation_id', ClassStatistic::GENERAL)
            ->when($this->period !== '', fn ($q) => $q->where('cs.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('cs.class_group_id', (int) $this->classGroup))
            ->when($this->search !== '', fn ($q) => $q->where('cg.name', 'like', '%'.$this->search.'%'))
            ->orderBy('ap.id')
            ->orderBy('cg.name')
            ->select([
                'cs.id', 'cs.n', 'cs.mean', 'cs.min_score', 'cs.max_score',
                'cs.median', 'cs.stdev_population', 'cs.pass_count', 'cs.pass_rate',
                'cg.name as class_group_name', 'ap.name as period_name',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function examsQuery()
    {
        return DB::table('exams as ex')
            ->join('subject_allocations as sa', 'sa.id', '=', 'ex.subject_allocation_id')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_groups as cg', 'cg.id', '=', 'ex.class_group_id')
            ->leftJoin('assessment_periods as ap', 'ap.id', '=', 'ex.assessment_period_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ex.room_id')
            ->when($this->period !== '', fn ($q) => $q->where('ex.assessment_period_id', (int) $this->period))
            ->when($this->classGroup !== '', fn ($q) => $q->where('ex.class_group_id', (int) $this->classGroup))
            ->when($this->subject !== '', fn ($q) => $q->where('sa.subject_id', (int) $this->subject))
            ->when($this->search !== '', fn ($q) => $q->where('cg.name', 'like', '%'.$this->search.'%'))
            ->orderBy('ex.scheduled_on')
            ->orderBy('ex.starts_at')
            ->select([
                'ex.id', 'ex.scheduled_on', 'ex.starts_at', 'ex.duration_minutes', 'ex.status',
                'sub.name as subject_name', 'cg.name as class_group_name',
                'r.name as room_name', 'ap.name as period_name',
            ]);
    }

    /**
     * @return list<list<mixed>>
     */
    private function markSheetExportRows(): array
    {
        $rows = [];

        foreach ($this->markSheetQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{first_name: string, last_name: string, matricule: string, subject_name: string, class_group_name: string, period_name: string, score: string|null, state: string} $row */
            $rows[] = [
                trim($row->first_name.' '.$row->last_name),
                $row->matricule,
                $row->subject_name,
                $row->class_group_name,
                $row->period_name,
                $row->score ?? '—',
                $row->state,
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function resultsExportRows(): array
    {
        $rows = [];

        foreach ($this->resultsQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{first_name: string, last_name: string, matricule: string, class_group_name: string, period_name: string, general_average_rounded: string|null, rank_position: int|null, rank_denominator: int|null, is_pass: int} $row */
            $rows[] = [
                trim($row->first_name.' '.$row->last_name),
                $row->matricule,
                $row->class_group_name,
                $row->period_name,
                $row->general_average_rounded ?? '—',
                $row->rank_position !== null ? $row->rank_position.'/'.$row->rank_denominator : '—',
                $row->is_pass ? 'Pass' : 'Fail',
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function statisticsExportRows(): array
    {
        $rows = [];

        foreach ($this->statisticsQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{class_group_name: string, period_name: string, n: int, mean: string|null, min_score: string|null, max_score: string|null, pass_rate: string|null} $row */
            $rows[] = [
                $row->class_group_name,
                $row->period_name,
                $row->n,
                $row->mean ?? '—',
                $row->min_score ?? '—',
                $row->max_score ?? '—',
                $row->pass_rate ?? '—',
            ];
        }

        return $rows;
    }

    /**
     * @return list<list<mixed>>
     */
    private function examsExportRows(): array
    {
        $rows = [];

        foreach ($this->examsQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{subject_name: string, class_group_name: string, scheduled_on: string, starts_at: string, room_name: string|null, status: string} $row */
            $rows[] = [
                $row->subject_name,
                $row->class_group_name,
                $row->scheduled_on,
                substr((string) $row->starts_at, 0, 5),
                $row->room_name ?? '—',
                $row->status,
            ];
        }

        return $rows;
    }

    /**
     * KPI strip: count-based only, dataset-wide (never filter-dependent
     * inventions), mirroring Results\Index::kpis().
     *
     * @return array{marks: int, results: int, statistics: int, exams: int}
     */
    private function kpis(): array
    {
        return [
            'marks' => (int) DB::table('marks')->where('state', 'scored')->count(),
            'results' => (int) DB::table('period_results')->count(),
            'statistics' => (int) DB::table('class_statistics')
                ->where('subject_allocation_id', ClassStatistic::GENERAL)
                ->distinct()
                ->count('class_group_id'),
            'exams' => (int) DB::table('exams')->count(),
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function periodOptions(): array
    {
        $options = [];

        foreach (DB::table('assessment_periods')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function classGroupOptions(): array
    {
        $options = [];

        foreach (DB::table('class_groups')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function subjectOptions(): array
    {
        $options = [];

        foreach (DB::table('subjects')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    public function render(): mixed
    {
        $tabCounts = [
            'marksheet' => (int) DB::table('marks')->count(),
            'results' => (int) DB::table('period_results')->count(),
            'statistics' => (int) DB::table('class_statistics')
                ->where('subject_allocation_id', ClassStatistic::GENERAL)
                ->count(),
            'exams' => (int) DB::table('exams')->count(),
        ];

        return view('livewire.assessment.reports.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'periodOptions' => $this->periodOptions(),
            'classGroupOptions' => $this->classGroupOptions(),
            'subjectOptions' => $this->subjectOptions(),
        ]);
    }
}
