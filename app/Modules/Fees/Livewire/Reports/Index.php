<?php

declare(strict_types=1);

namespace App\Modules\Fees\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fees Reports at /reports/fees (route wired centrally), gated `reports.view`.
 *
 * Four report tabs, each its own query built directly on DB::table() (no
 * cross-module Models - ModuleBoundaryTest):
 *
 *   - collection  : payments grouped by date + method (Collection Summary).
 *   - outstanding : per-student invoiced/paid/balance, balance > 0 only.
 *   - invoices    : the invoice register (student, class, no, date, total, status).
 *   - payments    : the payment history (receipt no, student, date, method, amount).
 *
 * The on-screen table is always a paginated query (00-core 6.2 rule 8);
 * export walks the same filtered query unpaginated via a DB cursor so a
 * large report never loads fully into memory before streaming out.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which report is showing: collection | outstanding | invoices | payments. */
    #[Url]
    public string $tab = 'collection';

    #[Url]
    public string $classGroup = '';

    #[Url]
    public string $academicYear = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    /** Meaning depends on the tab: invoice status on `invoices`, clearing state on `payments`. */
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
        $this->tab = in_array($tab, ['collection', 'outstanding', 'invoices', 'payments'], true)
            ? $tab
            : 'collection';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['classGroup', 'academicYear', 'dateFrom', 'dateTo', 'status']);
        $this->resetPage();
    }

    public function updatedClassGroup(): void
    {
        $this->resetPage();
    }

    public function updatedAcademicYear(): void
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

    // ── Exports ──────────────────────────────────────────────────────────

    public function exportExcel(): StreamedResponse
    {
        Gate::authorize(Permission::ReportsView->value);

        [$title, $headers, $rows] = $this->exportPayload();

        return ExcelExport::download($title, $headers, $rows, $this->exportFilename('xlsx'));
    }

    public function exportPdf(): SymfonyResponse
    {
        Gate::authorize(Permission::ReportsView->value);

        [$title, $headers, $rows] = $this->exportPayload();

        return PdfExport::download($title, $headers, $rows, $this->exportFilename('pdf'), 'landscape');
    }

    private function exportFilename(string $extension): string
    {
        return 'fees-'.$this->tab.'-report-'.now()->format('Y-m-d').'.'.$extension;
    }

    /**
     * @return array{0: string, 1: list<string>, 2: iterable<int, list<mixed>>}
     */
    private function exportPayload(): array
    {
        return match ($this->tab) {
            'outstanding' => [
                'Outstanding Balances',
                ['Student', 'Matricule', 'Invoiced Total', 'Paid / Settled', 'Balance Due'],
                $this->outstandingExportRows(),
            ],
            'invoices' => [
                'Invoice Register',
                ['Invoice No', 'Student', 'Matricule', 'Class', 'Date', 'Total', 'Status'],
                $this->invoiceExportRows(),
            ],
            'payments' => [
                'Payment History',
                ['Receipt No', 'Student', 'Date', 'Method', 'Amount', 'Status'],
                $this->paymentExportRows(),
            ],
            default => [
                'Collection Summary',
                ['Date', 'Method', 'Transactions', 'Total Collected'],
                $this->collectionExportRows(),
            ],
        };
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function collectionExportRows(): iterable
    {
        foreach ($this->collectionQuery()->cursor() as $row) {
            /** @var object{value_date: string, payment_method: string, txn_count: int|string, total_amount: int|string} $row */
            yield [
                (string) $row->value_date,
                $this->methodLabel((string) $row->payment_method),
                (int) $row->txn_count,
                Money::of((int) $row->total_amount)->format(false),
            ];
        }
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function outstandingExportRows(): iterable
    {
        foreach ($this->outstandingQuery()->cursor() as $row) {
            /** @var object{first_name: string, last_name: string, matricule: string, invoiced_total: int|string, balance: int|string} $row */
            $invoiced = (int) $row->invoiced_total;
            $balance = (int) $row->balance;

            yield [
                $row->first_name.' '.$row->last_name,
                (string) $row->matricule,
                Money::of($invoiced)->format(false),
                Money::of($invoiced - $balance)->format(false),
                Money::of($balance)->format(false),
            ];
        }
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function invoiceExportRows(): iterable
    {
        foreach ($this->invoiceQuery()->cursor() as $row) {
            /** @var object{invoice_no: string|null, first_name: string, last_name: string, matricule: string, class_name: string|null, issue_date: string, gross_total: int|string, status: string} $row */
            yield [
                (string) ($row->invoice_no ?? ''),
                $row->first_name.' '.$row->last_name,
                (string) $row->matricule,
                (string) ($row->class_name ?? ''),
                (string) $row->issue_date,
                Money::of((int) $row->gross_total)->format(false),
                ucfirst((string) $row->status),
            ];
        }
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function paymentExportRows(): iterable
    {
        foreach ($this->paymentQuery()->cursor() as $row) {
            /** @var object{receipt_no: string, first_name: string, last_name: string, value_date: string, payment_method: string, amount: int|string, clearing_state: string} $row */
            yield [
                (string) $row->receipt_no,
                $row->first_name.' '.$row->last_name,
                (string) $row->value_date,
                $this->methodLabel((string) $row->payment_method),
                Money::of((int) $row->amount)->format(false),
                ucfirst((string) $row->clearing_state),
            ];
        }
    }

    // ── Screen queries (paginated) ──────────────────────────────────────

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'outstanding' => $this->outstandingQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            'invoices' => $this->invoiceQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            'payments' => $this->paymentQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            default => $this->collectionQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
        };
    }

    private function classGroupExists(string $enrollmentColumn): \Closure
    {
        $classGroupId = (int) $this->classGroup;

        return function (QueryBuilder $q) use ($enrollmentColumn, $classGroupId): void {
            $q->whereExists(function (QueryBuilder $inner) use ($enrollmentColumn, $classGroupId): void {
                $inner->selectRaw('1')
                    ->from('enrollment_segments as seg')
                    ->whereColumn('seg.enrollment_id', $enrollmentColumn)
                    ->whereNull('seg.ends_on')
                    ->where('seg.class_group_id', $classGroupId);
            });
        };
    }

    private function collectionQuery(): QueryBuilder
    {
        $query = DB::table('payments as p')
            ->where('p.clearing_state', '<>', 'bounced')
            ->whereNotExists(function (QueryBuilder $q): void {
                $q->selectRaw('1')
                    ->from('payment_voids as v')
                    ->whereColumn('v.payment_id', 'p.id')
                    ->where('v.status', 'confirmed');
            })
            ->when($this->dateFrom !== '', fn ($q) => $q->where('p.value_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('p.value_date', '<=', $this->dateTo))
            ->when($this->academicYear !== '', fn ($q) => $q->where('p.academic_year_id', (int) $this->academicYear))
            ->when($this->classGroup !== '', $this->classGroupExists('p.enrollment_id'));

        return $query
            ->groupBy('p.value_date', 'p.payment_method')
            ->orderByDesc('p.value_date')
            ->select([
                'p.value_date',
                'p.payment_method',
                DB::raw('COUNT(*) as txn_count'),
                DB::raw('SUM(p.amount) as total_amount'),
            ]);
    }

    private function outstandingQuery(): QueryBuilder
    {
        $allocated = '(SELECT COALESCE(SUM(pa.amount), 0)
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            WHERE pa.invoice_id = i.id
              AND pa.reversed_at IS NULL
              AND p.clearing_state <> \'bounced\'
              AND NOT EXISTS (SELECT 1 FROM payment_voids v WHERE v.payment_id = p.id AND v.status = \'confirmed\'))';

        $adjusted = '(SELECT COALESCE(SUM(fa.amount), 0)
            FROM fee_adjustments fa
            JOIN invoice_lines al ON al.id = fa.invoice_line_id
            WHERE al.invoice_id = i.id AND fa.status = \'approved\')';

        $credited = '(SELECT COALESCE(SUM(cnl.amount + cnl.tax_amount), 0)
            FROM credit_note_lines cnl
            JOIN credit_notes cn ON cn.id = cnl.credit_note_id
            JOIN invoice_lines cl ON cl.id = cnl.invoice_line_id
            WHERE cl.invoice_id = i.id AND cn.status = \'issued\')';

        $gross = '(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id)';
        $balance = $gross.' - '.$allocated.' - '.$adjusted.' - '.$credited;

        $query = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->where('i.status', 'issued')
            ->when($this->academicYear !== '', fn ($q) => $q->where('i.academic_year_id', (int) $this->academicYear))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('i.issue_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('i.issue_date', '<=', $this->dateTo))
            ->when($this->classGroup !== '', $this->classGroupExists('i.enrollment_id'));

        return $query
            ->groupBy('s.id', 's.first_name', 's.last_name', 's.matricule')
            ->havingRaw('SUM('.$balance.') > 0')
            ->orderByDesc(DB::raw('SUM('.$balance.')'))
            ->select([
                's.id as student_id',
                's.first_name',
                's.last_name',
                's.matricule',
                DB::raw('SUM('.$gross.') as invoiced_total'),
                DB::raw('SUM('.$balance.') as balance'),
            ]);
    }

    private function invoiceQuery(): QueryBuilder
    {
        $query = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->when($this->status !== '', fn ($q) => $q->where('i.status', $this->status))
            ->when($this->academicYear !== '', fn ($q) => $q->where('i.academic_year_id', (int) $this->academicYear))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('i.issue_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('i.issue_date', '<=', $this->dateTo))
            ->when($this->classGroup !== '', $this->classGroupExists('i.enrollment_id'));

        return $query
            ->orderByDesc('i.issue_date')
            ->orderByDesc('i.id')
            ->select([
                'i.id',
                'i.invoice_no',
                's.first_name',
                's.last_name',
                's.matricule',
                'i.issue_date',
                'i.status',
                DB::raw('(SELECT COALESCE(SUM(l.amount + l.tax_amount), 0) FROM invoice_lines l WHERE l.invoice_id = i.id) as gross_total'),
                DB::raw('(SELECT cg.name FROM enrollment_segments seg
                    JOIN class_groups cg ON cg.id = seg.class_group_id
                    WHERE seg.enrollment_id = i.enrollment_id AND seg.ends_on IS NULL
                    ORDER BY seg.starts_on DESC LIMIT 1) as class_name'),
            ]);
    }

    private function paymentQuery(): QueryBuilder
    {
        $query = DB::table('payments as p')
            ->join('students as s', 's.id', '=', 'p.student_id')
            ->when($this->status !== '', fn ($q) => $q->where('p.clearing_state', $this->status))
            ->when($this->academicYear !== '', fn ($q) => $q->where('p.academic_year_id', (int) $this->academicYear))
            ->when($this->dateFrom !== '', fn ($q) => $q->where('p.value_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->where('p.value_date', '<=', $this->dateTo))
            ->when($this->classGroup !== '', $this->classGroupExists('p.enrollment_id'));

        return $query
            ->orderByDesc('p.value_date')
            ->orderByDesc('p.id')
            ->select([
                'p.id',
                'p.receipt_no',
                's.first_name',
                's.last_name',
                'p.value_date',
                'p.payment_method',
                'p.amount',
                'p.clearing_state',
            ]);
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'mobile_money' => 'Mobile Money',
            'bank' => 'Bank',
            default => 'Cash',
        };
    }

    // ── KPIs, dataset-wide under the same filters (ignoring the tab). ──

    /**
     * @return array{collected: int, outstanding: int, invoiced_students: int, invoices_issued: int}
     */
    private function kpis(): array
    {
        $collected = (int) (clone $this->collectionQuery())->reorder()->sum('p.amount');

        $outstandingRows = DB::query()->fromSub($this->outstandingQuery()->reorder(), 'o')->get(['balance']);
        $outstanding = 0;
        $studentsWithBalance = 0;

        foreach ($outstandingRows as $row) {
            /** @var object{balance: int|string} $row */
            $outstanding += (int) $row->balance;
            $studentsWithBalance++;
        }

        $invoicesIssued = (int) DB::table('invoices')->where('status', 'issued')->count();

        return [
            'collected' => $collected,
            'outstanding' => $outstanding,
            'invoiced_students' => $studentsWithBalance,
            'invoices_issued' => $invoicesIssued,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function classGroupOptions(): array
    {
        $rows = DB::table('class_groups as cg')
            ->join('academic_years as ay', 'ay.id', '=', 'cg.academic_year_id')
            ->where('ay.is_current', true)
            ->orderBy('cg.name')
            ->get(['cg.id', 'cg.name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function academicYearOptions(): array
    {
        $rows = DB::table('academic_years')->orderByDesc('starts_on')->get(['id', 'name']);

        $options = [];

        foreach ($rows as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => (string) $row->name];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices - the WORD carries the meaning (09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'invoices' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'issued', 'label' => 'Issued'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'payments' => [
                ['value' => 'cleared', 'label' => 'Cleared'],
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'bounced', 'label' => 'Bounced'],
            ],
            default => [],
        };
    }

    public function render(): mixed
    {
        $tabs = [
            ['value' => 'collection', 'label' => 'Collection Summary'],
            ['value' => 'outstanding', 'label' => 'Outstanding Balances'],
            ['value' => 'invoices', 'label' => 'Invoice Register'],
            ['value' => 'payments', 'label' => 'Payment History'],
        ];

        return view('livewire.fees.reports.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabs' => $tabs,
            'classGroupOptions' => $this->classGroupOptions(),
            'academicYearOptions' => $this->academicYearOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
