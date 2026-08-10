<?php

declare(strict_types=1);

namespace App\Modules\Students\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Students & Guardians Reports at /reports/students-guardians (route wired
 * centrally), gated `reports.view`. Four report types, selected via the
 * `report` tab, each with its own on-screen preview (paginated) plus an
 * unpaginated export twin consumed by ExcelExport/PdfExport - the same
 * shape as Academics\Livewire\Reports\Index.
 *
 * This single screen deliberately covers BOTH the Students and Guardians
 * domains (the Hub groups them under one category); guardian data is read
 * via DB::table joins only, never Guardians module Models
 * (ModuleBoundaryTest forbids cross-module Eloquent reach).
 *
 * Table/column names are read straight from the migrations (never guessed):
 * students, enrollments, enrollment_segments, class_groups, guardians,
 * student_guardians, attendance_summaries, assessment_periods.
 *
 * ── Attendance Summary simplification ───────────────────────────────────
 * `attendance_records` only stores EXCEPTION rows (present is never
 * written; see the migration header), so a clean per-student present count
 * needs the register's expected_count as a base, keyed per date range -
 * expensive to do generically across arbitrary from/to filters. The
 * already-PERSISTED `attendance_summaries` table (one row per enrollment per
 * assessment_period, docs/specs/07-students.md 9.8) exists precisely to
 * avoid recomputing this, so the report reads it directly: one row per
 * student per assessment period, with present/absent/late/excused counts.
 * The date-range filter narrows by the period's starts_on/ends_on rather
 * than by individual session dates - the noted simplification.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** student_register | guardian_directory | admission_register | attendance_summary. */
    #[Url]
    public string $report = 'student_register';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);
    }

    public function selectReport(string $report): void
    {
        $this->report = in_array($report, [
            'student_register', 'guardian_directory', 'admission_register', 'attendance_summary',
        ], true) ? $report : 'student_register';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['classGroup', 'status', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
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
        );
    }

    private function reportTitle(): string
    {
        return match ($this->report) {
            'guardian_directory' => 'Guardian Directory',
            'admission_register' => 'Admission Register',
            'attendance_summary' => 'Attendance Summary',
            default => 'Student Register',
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
            'guardian_directory' => [$this->guardianDirectoryHeaders(), $this->guardianDirectoryExportRows()],
            'admission_register' => [$this->admissionRegisterHeaders(), $this->admissionRegisterExportRows()],
            'attendance_summary' => [$this->attendanceSummaryHeaders(), $this->attendanceSummaryExportRows()],
            default => [$this->studentRegisterHeaders(), $this->studentRegisterExportRows()],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->report) {
            'guardian_directory' => $this->guardianDirectoryQuery()->paginate($this->perPage, page: $this->page),
            'admission_register' => $this->admissionRegisterQuery()->paginate($this->perPage, page: $this->page),
            'attendance_summary' => $this->attendanceSummaryQuery()->paginate($this->perPage, page: $this->page),
            default => $this->studentRegisterQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    // ── Student Register ────────────────────────────────────────────────

    /**
     * The open segment of a live enrollment - "what class is this student in
     * today", same correlated-subselect approach as
     * Students\Livewire\Students\Index (07-students 5.2).
     */
    private function currentClassSubQuery(): QueryBuilder
    {
        return DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
            ->whereColumn('enr.student_id', 's.id')
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->limit(1);
    }

    private function studentRegisterQuery(): QueryBuilder
    {
        return DB::table('students as s')
            ->when($this->classGroup !== '', function ($q): void {
                $q->whereExists(function (QueryBuilder $inner): void {
                    $inner->selectRaw('1')
                        ->from('enrollment_segments as fseg')
                        ->join('enrollments as fenr', 'fenr.id', '=', 'fseg.enrollment_id')
                        ->whereColumn('fenr.student_id', 's.id')
                        ->whereNull('fseg.ends_on')
                        ->whereIn('fenr.status', ['pending', 'active', 'suspended'])
                        ->where('fseg.class_group_id', '=', (int) $this->classGroup);
                });
            })
            ->when($this->status !== '', fn ($q) => $q->where('s.status', $this->status))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('s.first_admission_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('s.first_admission_date', '<=', $this->dateTo))
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->select([
                's.id', 's.matricule', 's.first_name', 's.last_name', 's.gender', 's.status',
            ])
            ->addSelect(['class_group_name' => $this->currentClassSubQuery()->select('cg.name')]);
    }

    /**
     * @return list<string>
     */
    private function studentRegisterHeaders(): array
    {
        return ['Matricule', 'First Name', 'Last Name', 'Class', 'Gender', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function studentRegisterExportRows(): iterable
    {
        foreach ($this->studentRegisterQuery()->get() as $row) {
            /** @var object{matricule: string, first_name: string, last_name: string, class_group_name: string|null, gender: string, status: string} $row */
            yield [
                $row->matricule,
                $row->first_name,
                $row->last_name,
                $row->class_group_name ?? '',
                ucfirst($row->gender),
                ucfirst(str_replace('_', ' ', $row->status)),
            ];
        }
    }

    // ── Guardian Directory ──────────────────────────────────────────────

    private function guardianDirectoryQuery(): QueryBuilder
    {
        return DB::table('guardians as g')
            ->when($this->status !== '', fn ($q) => $q->where('g.status', $this->status))
            ->orderBy('g.last_name')
            ->orderBy('g.first_name')
            ->select([
                'g.id', 'g.guardian_no', 'g.first_name', 'g.last_name', 'g.phone', 'g.portal_user_id',
            ])
            ->selectSub(
                DB::table('student_guardians')
                    ->whereColumn('guardian_id', 'g.id')
                    ->whereNull('valid_to')
                    ->selectRaw('COUNT(*)'),
                'students_count'
            );
    }

    /**
     * @return list<string>
     */
    private function guardianDirectoryHeaders(): array
    {
        return ['Guardian No.', 'First Name', 'Last Name', 'Phone', 'Linked Students', 'Portal Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function guardianDirectoryExportRows(): iterable
    {
        foreach ($this->guardianDirectoryQuery()->get() as $row) {
            /** @var object{guardian_no: string, first_name: string, last_name: string, phone: string, students_count: int|string, portal_user_id: int|string|null} $row */
            yield [
                $row->guardian_no,
                $row->first_name,
                $row->last_name,
                $row->phone,
                (int) $row->students_count,
                $row->portal_user_id !== null ? 'Active' : 'Not activated',
            ];
        }
    }

    // ── Admission Register ──────────────────────────────────────────────

    /**
     * "Admission" = the student's own admission record (matricule,
     * admission_no, first_admission_date already live on `students`), one
     * row per student - the current class comes from the same open-segment
     * correlated subselect as the Student Register.
     */
    private function admissionRegisterQuery(): QueryBuilder
    {
        return DB::table('students as s')
            ->whereNotNull('s.first_admission_date')
            ->when($this->classGroup !== '', function ($q): void {
                $q->whereExists(function (QueryBuilder $inner): void {
                    $inner->selectRaw('1')
                        ->from('enrollment_segments as fseg')
                        ->join('enrollments as fenr', 'fenr.id', '=', 'fseg.enrollment_id')
                        ->whereColumn('fenr.student_id', 's.id')
                        ->whereNull('fseg.ends_on')
                        ->whereIn('fenr.status', ['pending', 'active', 'suspended'])
                        ->where('fseg.class_group_id', '=', (int) $this->classGroup);
                });
            })
            ->when($this->status !== '', fn ($q) => $q->where('s.status', $this->status))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('s.first_admission_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('s.first_admission_date', '<=', $this->dateTo))
            ->orderByDesc('s.first_admission_date')
            ->select([
                's.id', 's.matricule', 's.admission_no', 's.first_name', 's.last_name',
                's.first_admission_date',
            ])
            ->addSelect(['class_group_name' => $this->currentClassSubQuery()->select('cg.name')]);
    }

    /**
     * @return list<string>
     */
    private function admissionRegisterHeaders(): array
    {
        return ['Admission No.', 'Matricule', 'Student', 'Class', 'Admission Date'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function admissionRegisterExportRows(): iterable
    {
        foreach ($this->admissionRegisterQuery()->get() as $row) {
            /** @var object{admission_no: string, matricule: string, first_name: string, last_name: string, class_group_name: string|null, first_admission_date: string} $row */
            yield [
                $row->admission_no,
                $row->matricule,
                trim($row->first_name.' '.$row->last_name),
                $row->class_group_name ?? '',
                $row->first_admission_date,
            ];
        }
    }

    // ── Attendance Summary ──────────────────────────────────────────────

    /**
     * Per student per assessment period, from the persisted rollup table
     * (see class header for why attendance_records is not aggregated
     * on-the-fly here).
     */
    private function attendanceSummaryQuery(): QueryBuilder
    {
        return DB::table('attendance_summaries as asum')
            ->join('enrollments as e', 'e.id', '=', 'asum.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('assessment_periods as ap', 'ap.id', '=', 'asum.assessment_period_id')
            ->leftJoin('enrollment_segments as es', function ($join): void {
                $join->on('es.enrollment_id', '=', 'e.id')->whereNull('es.ends_on');
            })
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'es.class_group_id')
            ->when($this->classGroup !== '', fn ($q) => $q->where('es.class_group_id', (int) $this->classGroup))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('ap.ends_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('ap.starts_on', '<=', $this->dateTo))
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->orderByDesc('ap.starts_on')
            ->select([
                's.id', 's.matricule', 's.first_name', 's.last_name', 'cg.name as class_group_name',
                'ap.name as period_name', 'asum.sessions_present', 'asum.sessions_absent',
                'asum.sessions_late', 'asum.sessions_excused',
            ]);
    }

    /**
     * @return list<string>
     */
    private function attendanceSummaryHeaders(): array
    {
        return ['Matricule', 'Student', 'Class', 'Period', 'Present', 'Absent', 'Late', 'Excused'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function attendanceSummaryExportRows(): iterable
    {
        foreach ($this->attendanceSummaryQuery()->get() as $row) {
            /** @var object{matricule: string, first_name: string, last_name: string, class_group_name: string|null, period_name: string, sessions_present: int|string, sessions_absent: int|string, sessions_late: int|string, sessions_excused: int|string} $row */
            yield [
                $row->matricule,
                trim($row->first_name.' '.$row->last_name),
                $row->class_group_name ?? '',
                $row->period_name,
                (int) $row->sessions_present,
                (int) $row->sessions_absent,
                (int) $row->sessions_late,
                (int) $row->sessions_excused,
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
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->report) {
            'guardian_directory' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
            ],
            'attendance_summary' => [],
            default => [
                ['value' => 'prospective', 'label' => 'Prospective'],
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
                ['value' => 'graduated', 'label' => 'Graduated'],
                ['value' => 'transferred_out', 'label' => 'Transferred out'],
                ['value' => 'withdrawn', 'label' => 'Withdrawn'],
            ],
        };
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    private function reportTabs(): array
    {
        return [
            ['value' => 'student_register', 'label' => 'Student Register', 'count' => 0],
            ['value' => 'guardian_directory', 'label' => 'Guardian Directory', 'count' => 0],
            ['value' => 'admission_register', 'label' => 'Admission Register', 'count' => 0],
            ['value' => 'attendance_summary', 'label' => 'Attendance Summary', 'count' => 0],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.students.reports.index', [
            'rows' => $this->rows(),
            'reportTabs' => $this->reportTabs(),
            'classGroupOptions' => $this->classGroupOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
