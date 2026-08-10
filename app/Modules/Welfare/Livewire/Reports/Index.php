<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Reports;

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
 * Welfare Reports at /reports/welfare (route wired centrally), gated the
 * generic cross-module `reports.view` permission - this screen reads
 * across Transport/Hostel/Medical/Discipline/Insurance rather than
 * belonging to any single one of them, so it does not reuse those
 * screens' own `*.view` gates.
 *
 * Five report tabs, each a straight DB::table read (never another
 * module's Models: ModuleBoundaryTest) mirroring the exact join shapes
 * already proven in Transport\Index, Hostel\Index and Insurance\Index:
 *   - Transport Roster:    transport_allocations x routes/stops/students.
 *   - Hostel Occupancy:    hostel_allocations x hostels/rooms/beds/students.
 *   - Medical Log:         medical_consultations x students.
 *   - Discipline Register: discipline_cases x students/categories.
 *   - Insurance Register:  student_insurances x insurance_policies/students.
 *
 * Medical and discipline data is sensitive. This report intentionally
 * does NOT surface the encrypted clinical narrative fields
 * (medical_consultations.presenting_complaint / diagnosis / treatment)
 * or discipline's free-text description - only structured metadata
 * (severity, outcome, category, status) - matching how Medical\Index and
 * Discipline\Index already keep raw encrypted/narrative text out of their
 * list views.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which report is showing: transport | hostel | medical | discipline | insurance. */
    #[Url]
    public string $tab = 'transport';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['transport', 'hostel', 'medical', 'discipline', 'insurance'], true)
            ? $tab
            : 'transport';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['dateFrom', 'dateTo', 'status']);
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

    public function updatedStatus(): void
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
            'hostel' => $this->hostelQuery()->paginate($this->perPage, page: $this->page),
            'medical' => $this->medicalQuery()->paginate($this->perPage, page: $this->page),
            'discipline' => $this->disciplineQuery()->paginate($this->perPage, page: $this->page),
            'insurance' => $this->insuranceQuery()->paginate($this->perPage, page: $this->page),
            default => $this->transportQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function transportQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('transport_allocations as ta')
            ->join('transport_routes as tr', 'tr.id', '=', 'ta.route_id')
            ->join('transport_stops as ts', 'ts.id', '=', 'ta.stop_id')
            ->join('enrollments as e', 'e.id', '=', 'ta.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('ta.starts_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('ta.starts_on', '<=', $this->dateTo))
            ->when($this->status !== '', fn ($q) => $q->where('ta.status', $this->status))
            ->orderBy('tr.code')->orderBy('ts.sequence')->orderBy('s.last_name')
            ->select([
                's.matricule', 's.first_name', 's.last_name',
                'tr.code as route_code', 'tr.name as route_name',
                'ts.name as stop_name', 'ta.direction', 'ta.status',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function hostelQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('hostel_allocations as a')
            ->join('hostel_beds as b', 'b.id', '=', 'a.bed_id')
            ->join('hostel_rooms as r', 'r.id', '=', 'b.room_id')
            ->join('hostels as h', 'h.id', '=', 'r.hostel_id')
            ->join('enrollments as e', 'e.id', '=', 'a.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('a.starts_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('a.starts_on', '<=', $this->dateTo))
            ->when($this->status !== '', fn ($q) => $q->where('a.status', $this->status))
            ->orderBy('h.code')->orderBy('r.name')->orderBy('s.last_name')
            ->select([
                's.matricule', 's.first_name', 's.last_name',
                'h.name as hostel_name', 'r.name as room_name',
                'b.label as bed_label', 'a.status',
            ]);
    }

    /**
     * Structured metadata only - the encrypted clinical narrative
     * (presenting_complaint/diagnosis/treatment) never reaches this list.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function medicalQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('medical_consultations as c')
            ->join('students as s', 's.id', '=', 'c.student_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('c.visited_at', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('c.visited_at', '<=', $this->dateTo))
            ->when($this->status !== '', fn ($q) => $q->where('c.severity', $this->status))
            ->orderByDesc('c.visited_at')->orderByDesc('c.id')
            ->select([
                's.matricule', 's.first_name', 's.last_name',
                'c.visited_at', 'c.severity', 'c.outcome',
            ]);
    }

    /**
     * Category/status metadata only - the free-text description never
     * reaches this list.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function disciplineQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('discipline_cases as d')
            ->join('students as s', 's.id', '=', 'd.student_id')
            ->join('discipline_categories as cat', 'cat.id', '=', 'd.discipline_category_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('d.occurred_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('d.occurred_on', '<=', $this->dateTo))
            ->when($this->status !== '', fn ($q) => $q->where('d.status', $this->status))
            ->orderByDesc('d.occurred_on')->orderByDesc('d.id')
            ->select([
                's.matricule', 's.first_name', 's.last_name',
                'cat.name as category_name', 'd.occurred_on', 'd.status',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function insuranceQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('student_insurances as si')
            ->join('insurance_policies as p', 'p.id', '=', 'si.policy_id')
            ->join('enrollments as e', 'e.id', '=', 'si.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->dateFrom !== '', fn ($q) => $q->where('si.enrolled_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('si.enrolled_on', '<=', $this->dateTo))
            ->when($this->status !== '', fn ($q) => $q->where('si.status', $this->status))
            ->orderBy('p.policy_no')->orderBy('s.last_name')
            ->select([
                's.matricule', 's.first_name', 's.last_name',
                'p.policy_no', 'p.provider', 'si.status',
            ]);
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->tab) {
            'hostel' => [
                'title' => 'Hostel Occupancy',
                'headers' => ['Student', 'Matricule', 'Hostel', 'Room', 'Bed', 'Status'],
                'rows' => $this->hostelQuery()->get()->map(fn (object $row): array => [
                    $row->last_name.' '.$row->first_name, $row->matricule,
                    $row->hostel_name, $row->room_name, $row->bed_label, $row->status,
                ])->all(),
            ],
            'medical' => [
                'title' => 'Medical Log',
                'headers' => ['Student', 'Matricule', 'Date', 'Severity', 'Outcome'],
                'rows' => $this->medicalQuery()->get()->map(fn (object $row): array => [
                    $row->last_name.' '.$row->first_name, $row->matricule,
                    (string) $row->visited_at, $row->severity, $row->outcome,
                ])->all(),
            ],
            'discipline' => [
                'title' => 'Discipline Register',
                'headers' => ['Student', 'Matricule', 'Category', 'Date', 'Status'],
                'rows' => $this->disciplineQuery()->get()->map(fn (object $row): array => [
                    $row->last_name.' '.$row->first_name, $row->matricule,
                    $row->category_name, (string) $row->occurred_on, $row->status,
                ])->all(),
            ],
            'insurance' => [
                'title' => 'Insurance Register',
                'headers' => ['Student', 'Matricule', 'Policy', 'Provider', 'Status'],
                'rows' => $this->insuranceQuery()->get()->map(fn (object $row): array => [
                    $row->last_name.' '.$row->first_name, $row->matricule,
                    $row->policy_no, $row->provider, $row->status,
                ])->all(),
            ],
            default => [
                'title' => 'Transport Roster',
                'headers' => ['Student', 'Matricule', 'Route', 'Stop', 'Direction', 'Status'],
                'rows' => $this->transportQuery()->get()->map(fn (object $row): array => [
                    $row->last_name.' '.$row->first_name, $row->matricule,
                    $row->route_code.' - '.$row->route_name, $row->stop_name,
                    $row->direction, $row->status,
                ])->all(),
            ],
        };
    }

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::ReportsView->value);

        $data = $this->exportData();

        return ExcelExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.xlsx',
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::ReportsView->value);

        $data = $this->exportData();

        return PdfExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.pdf',
            'landscape',
        );
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'hostel' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
            'medical' => [
                ['value' => 'low', 'label' => 'Low'],
                ['value' => 'moderate', 'label' => 'Moderate'],
                ['value' => 'high', 'label' => 'High'],
            ],
            'discipline' => [
                ['value' => 'open', 'label' => 'Open'],
                ['value' => 'under_investigation', 'label' => 'Under investigation'],
                ['value' => 'resolved', 'label' => 'Resolved'],
                ['value' => 'dismissed', 'label' => 'Dismissed'],
            ],
            'insurance' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'lapsed', 'label' => 'Lapsed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
        };
    }

    public function render(): mixed
    {
        return view('livewire.welfare.reports.index', [
            'rows' => $this->rows(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
