<?php

declare(strict_types=1);

namespace App\Modules\Academics\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Academic Reports at /reports/academic (route wired centrally), gated
 * `reports.view`. Four report types, selected via the `report` tab, each
 * with its own filter set and its own on-screen preview (paginated) plus
 * an unpaginated export twin consumed by ExcelExport/PdfExport.
 *
 * Table/column names are read straight from the migrations (never guessed):
 * class_groups, class_levels, subjects, subject_allocations,
 * timetable_slots, timetable_periods, staff_members, enrollment_segments,
 * enrollments, students, promotion_decisions.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** class_list | subject_allocation | timetable | promotion. */
    #[Url]
    public string $report = 'class_list';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $classLevel = '';

    #[Url]
    public string $academicYear = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);
    }

    public function selectReport(string $report): void
    {
        $this->report = in_array($report, ['class_list', 'subject_allocation', 'timetable', 'promotion'], true)
            ? $report
            : 'class_list';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['classGroup', 'classLevel', 'academicYear']);
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedClassLevel(): void
    {
        $this->resetPage();
    }

    public function updatedAcademicYear(): void
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

    public function exportExcel(): StreamedResponse
    {
        [$headers, $rows] = $this->exportData();

        return ExcelExport::download(
            $this->reportTitle(),
            $headers,
            $rows,
            $this->reportSlug().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        [$headers, $rows] = $this->exportData();

        return PdfExport::download(
            $this->reportTitle(),
            $headers,
            $rows,
            $this->reportSlug().'.pdf',
            $this->report === 'timetable' ? 'landscape' : 'portrait',
        );
    }

    private function reportTitle(): string
    {
        return match ($this->report) {
            'subject_allocation' => 'Subject Allocation Register',
            'timetable' => 'Timetable Register',
            'promotion' => 'Promotion Register',
            default => 'Class List',
        };
    }

    private function reportSlug(): string
    {
        return str_replace('_', '-', $this->report);
    }

    /**
     * @return array{0: list<string>, 1: iterable<int, list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->report) {
            'subject_allocation' => [$this->subjectAllocationHeaders(), $this->subjectAllocationExportRows()],
            'timetable' => [$this->timetableHeaders(), $this->timetableExportRows()],
            'promotion' => [$this->promotionHeaders(), $this->promotionExportRows()],
            default => [$this->classListHeaders(), $this->classListExportRows()],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->report) {
            'subject_allocation' => $this->subjectAllocationQuery()->paginate($this->perPage, page: $this->page),
            'timetable' => $this->timetableQuery()->paginate($this->perPage, page: $this->page),
            'promotion' => $this->promotionQuery()->paginate($this->perPage, page: $this->page),
            default => $this->classListQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    // ── Class List ───────────────────────────────────────────────────────

    private function classListQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('enrollment_segments as es')
            ->join('enrollments as e', 'e.id', '=', 'es.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('class_groups as cg', 'cg.id', '=', 'es.class_group_id')
            ->whereNull('es.ends_on')
            ->when($this->classGroup !== '', fn ($q) => $q->where('es.class_group_id', (int) $this->classGroup))
            ->when($this->academicYear !== '', fn ($q) => $q->where('e.academic_year_id', (int) $this->academicYear))
            ->orderBy('cg.name')
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->select([
                'cg.name as class_group_name',
                's.matricule',
                's.first_name',
                's.last_name',
                's.gender',
                'es.roll_number',
            ]);
    }

    /**
     * @return list<string>
     */
    private function classListHeaders(): array
    {
        return ['Class', 'Matricule', 'First Name', 'Last Name', 'Gender', 'Roll No.'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function classListExportRows(): iterable
    {
        foreach ($this->classListQuery()->get() as $row) {
            /** @var object{class_group_name: string, matricule: string, first_name: string, last_name: string, gender: string, roll_number: int|string|null} $row */
            yield [
                $row->class_group_name,
                $row->matricule,
                $row->first_name,
                $row->last_name,
                ucfirst($row->gender),
                $row->roll_number ?? '',
            ];
        }
    }

    // ── Subject Allocation Register ─────────────────────────────────────

    private function subjectAllocationQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('subject_allocations as sa')
            ->join('subjects as sub', 'sub.id', '=', 'sa.subject_id')
            ->join('class_levels as cl', 'cl.id', '=', 'sa.class_level_id')
            ->when($this->classLevel !== '', fn ($q) => $q->where('sa.class_level_id', (int) $this->classLevel))
            ->when($this->academicYear !== '', fn ($q) => $q->where('sa.academic_year_id', (int) $this->academicYear))
            ->orderBy('cl.order_index')
            ->orderBy('sub.name')
            ->select([
                'cl.name as class_level_name',
                'sub.code as subject_code',
                'sub.name as subject_name',
                'sa.coefficient',
                'sa.is_optional',
                'sa.is_active',
            ]);
    }

    /**
     * @return list<string>
     */
    private function subjectAllocationHeaders(): array
    {
        return ['Class Level', 'Subject Code', 'Subject Name', 'Coefficient', 'Optional', 'Active'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function subjectAllocationExportRows(): iterable
    {
        foreach ($this->subjectAllocationQuery()->get() as $row) {
            /** @var object{class_level_name: string, subject_code: string, subject_name: string, coefficient: string|float, is_optional: int|bool, is_active: int|bool} $row */
            yield [
                $row->class_level_name,
                $row->subject_code,
                $row->subject_name,
                $row->coefficient,
                $row->is_optional ? 'Yes' : 'No',
                $row->is_active ? 'Yes' : 'No',
            ];
        }
    }

    // ── Timetable Register ──────────────────────────────────────────────

    private const DAY_NAMES = [
        1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday',
        4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday',
    ];

    private function timetableQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('timetable_slots as ts')
            ->join('class_groups as cg', 'cg.id', '=', 'ts.class_group_id')
            ->join('subjects as sub', 'sub.id', '=', 'ts.subject_id')
            ->join('timetable_periods as tp', 'tp.id', '=', 'ts.timetable_period_id')
            ->join('staff_members as st', 'st.id', '=', 'ts.staff_member_id')
            ->when($this->classGroup !== '', fn ($q) => $q->where('ts.class_group_id', (int) $this->classGroup))
            ->when($this->academicYear !== '', fn ($q) => $q->where('ts.academic_year_id', (int) $this->academicYear))
            ->orderBy('cg.name')
            ->orderBy('ts.day_of_week')
            ->orderBy('tp.sequence')
            ->select([
                'cg.name as class_group_name',
                'ts.day_of_week',
                'tp.name as period_name',
                'tp.starts_at',
                'tp.ends_at',
                'sub.name as subject_name',
                'st.first_name as staff_first_name',
                'st.last_name as staff_last_name',
            ]);
    }

    /**
     * @return list<string>
     */
    private function timetableHeaders(): array
    {
        return ['Class', 'Day', 'Period', 'Start', 'End', 'Subject', 'Teacher'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function timetableExportRows(): iterable
    {
        foreach ($this->timetableQuery()->get() as $row) {
            /** @var object{class_group_name: string, day_of_week: int|string, period_name: string, starts_at: string, ends_at: string, subject_name: string, staff_first_name: string, staff_last_name: string} $row */
            yield [
                $row->class_group_name,
                self::DAY_NAMES[(int) $row->day_of_week] ?? (string) $row->day_of_week,
                $row->period_name,
                substr((string) $row->starts_at, 0, 5),
                substr((string) $row->ends_at, 0, 5),
                $row->subject_name,
                trim($row->staff_first_name.' '.$row->staff_last_name),
            ];
        }
    }

    // ── Promotion Register ──────────────────────────────────────────────

    private function promotionQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('promotion_decisions as pd')
            ->join('enrollments as e', 'e.id', '=', 'pd.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->academicYear !== '', fn ($q) => $q->where('e.academic_year_id', (int) $this->academicYear))
            ->orderByDesc('pd.decided_at')
            ->select([
                's.matricule',
                's.first_name',
                's.last_name',
                'e.academic_year_id',
                'pd.decision',
                'pd.outcome',
                'pd.target_class_group_key',
                'pd.decided_at',
            ]);
    }

    /**
     * @return list<string>
     */
    private function promotionHeaders(): array
    {
        return ['Matricule', 'First Name', 'Last Name', 'Academic Year ID', 'Decision', 'Outcome', 'Target Class', 'Decided On'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function promotionExportRows(): iterable
    {
        foreach ($this->promotionQuery()->get() as $row) {
            /** @var object{matricule: string, first_name: string, last_name: string, academic_year_id: int|string, decision: string|null, outcome: string|null, target_class_group_key: string|null, decided_at: string} $row */
            yield [
                $row->matricule,
                $row->first_name,
                $row->last_name,
                $row->academic_year_id,
                $row->decision ?? '',
                $row->outcome ?? '',
                $row->target_class_group_key ?? '',
                $row->decided_at,
            ];
        }
    }

    // ── Filter option lists ─────────────────────────────────────────────

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
    private function classLevelOptions(): array
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
    private function academicYearOptions(): array
    {
        $options = [];

        foreach (DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * The tab strip. Each entry carried a hardcoded `'count' => 0` that no
     * view ever read — a literal zero one badge away from being rendered as
     * "0 records" for a report that in fact has rows. Dropped rather than
     * back-filled: the tabs are report selectors, not counters.
     *
     * @return list<array{value: string, label: string}>
     */
    private function reportTabs(): array
    {
        return [
            ['value' => 'class_list', 'label' => 'Class List'],
            ['value' => 'subject_allocation', 'label' => 'Subject Allocation Register'],
            ['value' => 'timetable', 'label' => 'Timetable Register'],
            ['value' => 'promotion', 'label' => 'Promotion Register'],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.academics.reports.index', [
            'rows' => $this->rows(),
            'reportTabs' => $this->reportTabs(),
            'classGroupOptions' => $this->classGroupOptions(),
            'classLevelOptions' => $this->classLevelOptions(),
            'academicYearOptions' => $this->academicYearOptions(),
        ]);
    }
}
