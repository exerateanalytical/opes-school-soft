<?php

declare(strict_types=1);

namespace App\Modules\HR\Livewire\Reports;

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
 * HR & Payroll Reports at /reports/hr (route wired centrally), gated
 * `reports.view` - one of the eleven report-cluster screens (docs: Reports
 * module, 2026-08 build). A read-only register/summary screen, modelled
 * tightly on Welfare\Livewire\Transport\Index and the shared
 * Reporting\Support export helpers.
 *
 * Four report tabs, each its own DB::table() query (never a module Model -
 * ModuleBoundaryTest): Staff Register, Contract Register, Leave Register,
 * Payslip Summary. `rows()` is paginated for the on-screen preview;
 * `exportRows()`/`exportHeaders()` re-run the same filtered query
 * unbounded (matched to `rows()` shape) for Excel/PDF export - the one
 * permitted place an unbounded query is allowed to leave a Livewire
 * component, since it never reaches the Blade view (00-core 6.2 rule 8).
 *
 * NOTE (sensitive data): the Payslip Summary tab surfaces gross/deductions/
 * net pay per staff member - salary information, and the most sensitive
 * export on this screen. There is currently no finer-grained
 * payroll-specific view permission, so it is gated on the same
 * `reports.view` as the other three tabs, per the shared convention
 * documented in this build's task brief. A dedicated payroll-report
 * permission should be introduced if/when this needs tighter scoping.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which report is showing: staff | contracts | leave | payslips. */
    #[Url]
    public string $tab = 'staff';

    #[Url]
    public string $department = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $payrollRun = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);

        if ($this->payrollRun === '') {
            $latestRunId = DB::table('payroll_runs')->orderByDesc('payroll_month')->orderByDesc('id')->value('id');
            $this->payrollRun = $latestRunId === null ? '' : (string) $latestRunId;
        }
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['staff', 'contracts', 'leave', 'payslips'], true)
            ? $tab
            : 'staff';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['department', 'status']);
        $this->resetPage();
    }

    public function updatedDepartment(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPayrollRun(): void
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
        Gate::authorize(Permission::ReportsView->value);

        return ExcelExport::download(
            $this->reportTitle(),
            $this->exportHeaders(),
            $this->exportRows(),
            $this->exportFilename('xlsx'),
        );
    }

    public function exportPdf(): Response
    {
        Gate::authorize(Permission::ReportsView->value);

        return PdfExport::download(
            $this->reportTitle(),
            $this->exportHeaders(),
            $this->exportRows(),
            $this->exportFilename('pdf'),
            'landscape',
        );
    }

    private function reportTitle(): string
    {
        return match ($this->tab) {
            'contracts' => 'Contract Register',
            'leave' => 'Leave Register',
            'payslips' => 'Payslip Summary',
            default => 'Staff Register',
        };
    }

    private function exportFilename(string $extension): string
    {
        $slug = match ($this->tab) {
            'contracts' => 'contract-register',
            'leave' => 'leave-register',
            'payslips' => 'payslip-summary',
            default => 'staff-register',
        };

        return $slug.'-'.now()->format('Ymd-His').'.'.$extension;
    }

    /**
     * @return list<string>
     */
    private function exportHeaders(): array
    {
        return match ($this->tab) {
            'contracts' => ['Staff No', 'Staff Name', 'Contract Type', 'Department', 'Position', 'Starts On', 'Ends On', 'Status'],
            'leave' => ['Staff No', 'Staff Name', 'Leave Type', 'Starts On', 'Ends On', 'Working Days', 'Status'],
            'payslips' => ['Staff No', 'Staff Name', 'Gross', 'Total Deductions', 'Net Pay'],
            default => ['Staff No', 'First Name', 'Last Name', 'Department', 'Position', 'Status'],
        };
    }

    /**
     * @return list<list<mixed>>
     */
    private function exportRows(): array
    {
        $rows = match ($this->tab) {
            'contracts' => $this->contractQuery()->get(),
            'leave' => $this->leaveQuery()->get(),
            'payslips' => $this->payslipQuery()->get(),
            default => $this->staffQuery()->get(),
        };

        $export = [];

        foreach ($rows as $row) {
            $export[] = match ($this->tab) {
                'contracts' => [
                    $row->staff_no, trim($row->first_name.' '.$row->last_name), $row->contract_type,
                    $row->department_name, $row->position_name, $row->starts_on, $row->ends_on ?? '-',
                    $row->ends_on === null ? 'active' : 'ended',
                ],
                'leave' => [
                    $row->staff_no, trim($row->first_name.' '.$row->last_name), $row->leave_type_name,
                    $row->starts_on, $row->ends_on, $row->working_days, $row->status,
                ],
                'payslips' => [
                    $row->staff_no, trim($row->first_name.' '.$row->last_name),
                    $row->gross, $row->total_employee_deductions, $row->net,
                ],
                default => [
                    $row->staff_no, $row->first_name, $row->last_name,
                    $row->department_name ?? '-', $row->position_name ?? '-', $row->status,
                ],
            };
        }

        return $export;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        $query = match ($this->tab) {
            'contracts' => $this->contractQuery(),
            'leave' => $this->leaveQuery(),
            'payslips' => $this->payslipQuery(),
            default => $this->staffQuery(),
        };

        return $query->paginate($this->perPage, page: $this->page);
    }

    /**
     * Staff Register: all staff with their current department/position.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function staffQuery()
    {
        return DB::table('staff_members as sm')
            ->when($this->status !== '', fn ($q) => $q->where('sm.status', $this->status))
            ->when($this->department !== '', function ($q): void {
                $q->whereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('staff_contracts as sc')
                        ->whereColumn('sc.staff_member_id', 'sm.id')
                        ->whereNull('sc.ends_on')
                        ->where('sc.department_id', (int) $this->department);
                });
            })
            ->orderBy('sm.last_name')
            ->orderBy('sm.first_name')
            ->select(['sm.id', 'sm.staff_no', 'sm.first_name', 'sm.last_name', 'sm.status'])
            ->selectSub(
                DB::table('staff_contracts as sc')
                    ->join('departments as d', 'd.id', '=', 'sc.department_id')
                    ->whereColumn('sc.staff_member_id', 'sm.id')
                    ->whereNull('sc.ends_on')
                    ->orderByDesc('sc.starts_on')
                    ->limit(1)
                    ->select('d.name'),
                'department_name'
            )
            ->selectSub(
                DB::table('staff_contracts as sc')
                    ->join('positions as p', 'p.id', '=', 'sc.position_id')
                    ->whereColumn('sc.staff_member_id', 'sm.id')
                    ->whereNull('sc.ends_on')
                    ->orderByDesc('sc.starts_on')
                    ->limit(1)
                    ->select('p.name'),
                'position_name'
            );
    }

    /**
     * Contract Register: every contract with staff, type, department,
     * dates, status (active = no end date).
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function contractQuery()
    {
        return DB::table('staff_contracts as sc')
            ->join('staff_members as sm', 'sm.id', '=', 'sc.staff_member_id')
            ->join('departments as d', 'd.id', '=', 'sc.department_id')
            ->join('positions as p', 'p.id', '=', 'sc.position_id')
            ->when($this->department !== '', fn ($q) => $q->where('sc.department_id', (int) $this->department))
            ->when($this->status === 'active', fn ($q) => $q->whereNull('sc.ends_on'))
            ->when($this->status === 'ended', fn ($q) => $q->whereNotNull('sc.ends_on'))
            ->orderByDesc('sc.starts_on')
            ->select([
                'sc.id', 'sc.contract_type', 'sc.starts_on', 'sc.ends_on',
                'sm.staff_no', 'sm.first_name', 'sm.last_name',
                'd.name as department_name', 'p.name as position_name',
            ]);
    }

    /**
     * Leave Register: every leave request with staff, type, dates, days,
     * status.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function leaveQuery()
    {
        return DB::table('leave_requests as lr')
            ->join('staff_contracts as sc', 'sc.id', '=', 'lr.staff_contract_id')
            ->join('staff_members as sm', 'sm.id', '=', 'sc.staff_member_id')
            ->join('leave_types as lt', 'lt.id', '=', 'lr.leave_type_id')
            ->when($this->department !== '', fn ($q) => $q->where('sc.department_id', (int) $this->department))
            ->when($this->status !== '', fn ($q) => $q->where('lr.status', $this->status))
            ->orderByDesc('lr.starts_on')
            ->select([
                'lr.id', 'lr.starts_on', 'lr.ends_on', 'lr.working_days', 'lr.status',
                'sm.staff_no', 'sm.first_name', 'sm.last_name',
                'lt.name as leave_type_name',
            ]);
    }

    /**
     * Payslip Summary: payroll_items for the selected (or most recent)
     * payroll run - staff, gross, deductions, net pay. Sensitive salary
     * data; see the class-level NOTE.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function payslipQuery()
    {
        return DB::table('payroll_items as pi')
            ->join('staff_members as sm', 'sm.id', '=', 'pi.staff_member_id')
            ->join('staff_contracts as sc', 'sc.id', '=', 'pi.staff_contract_id')
            ->when($this->payrollRun !== '', fn ($q) => $q->where('pi.payroll_run_id', (int) $this->payrollRun))
            ->when($this->department !== '', fn ($q) => $q->where('sc.department_id', (int) $this->department))
            ->where('pi.is_cancelled', false)
            ->orderBy('sm.last_name')
            ->orderBy('sm.first_name')
            ->select([
                'pi.id', 'pi.gross', 'pi.total_employee_deductions', 'pi.net',
                'sm.staff_no', 'sm.first_name', 'sm.last_name',
            ]);
    }

    /**
     * @return array{total_staff: int, active_contracts: int, pending_leave: int, latest_run_net_pay: int}
     */
    private function kpis(): array
    {
        $latestRunNetPay = $this->payrollRun !== ''
            ? (int) DB::table('payroll_items')
                ->where('payroll_run_id', (int) $this->payrollRun)
                ->where('is_cancelled', false)
                ->sum('net')
            : 0;

        return [
            'total_staff' => (int) DB::table('staff_members')->count(),
            'active_contracts' => (int) DB::table('staff_contracts')->whereNull('ends_on')->count(),
            'pending_leave' => (int) DB::table('leave_requests')->where('status', 'submitted')->count(),
            'latest_run_net_pay' => $latestRunNetPay,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function departmentOptions(): array
    {
        $options = [];

        foreach (DB::table('departments')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, label: string}>
     */
    private function payrollRunOptions(): array
    {
        $options = [];

        foreach (DB::table('payroll_runs')->orderByDesc('payroll_month')->orderByDesc('id')->limit(24)->get(['id', 'payroll_month', 'run_type']) as $row) {
            /** @var object{id: int|string, payroll_month: string, run_type: string} $row */
            $options[] = ['id' => (int) $row->id, 'label' => $row->payroll_month.' ('.$row->run_type.')'];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'contracts' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
            'leave' => [
                ['value' => 'submitted', 'label' => 'Submitted'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
                ['value' => 'taken', 'label' => 'Taken'],
            ],
            'payslips' => [],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
                ['value' => 'on_leave', 'label' => 'On leave'],
                ['value' => 'suspended', 'label' => 'Suspended'],
                ['value' => 'terminated', 'label' => 'Terminated'],
                ['value' => 'retired', 'label' => 'Retired'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'staff' => (int) DB::table('staff_members')->count(),
            'contracts' => (int) DB::table('staff_contracts')->count(),
            'leave' => (int) DB::table('leave_requests')->count(),
            'payslips' => $this->payrollRun !== ''
                ? (int) DB::table('payroll_items')->where('payroll_run_id', (int) $this->payrollRun)->where('is_cancelled', false)->count()
                : 0,
        ];

        return view('livewire.hr.reports.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'departmentOptions' => $this->departmentOptions(),
            'statusOptions' => $this->statusOptions(),
            'payrollRunOptions' => $this->payrollRunOptions(),
        ]);
    }
}
