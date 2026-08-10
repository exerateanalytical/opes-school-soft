<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Procurement\Actions\AgedPayables;
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
 * Procurement Reports at /reports/procurement (route wired centrally),
 * gated `reports.view`. Four report types, selected via the `report` tab,
 * each with its own filter set: an on-screen paginated preview plus an
 * unpaginated export twin consumed by ExcelExport/PdfExport.
 *
 * Table/column names read straight from the migrations (never guessed):
 * suppliers, purchase_orders, supplier_invoices, goods_receipts,
 * goods_receipt_lines. The Payables Aging report reuses
 * App\Modules\Procurement\Actions\AgedPayables - the SAME ledger-derived
 * definition the Payables Dashboard uses - rather than inventing a second
 * "unpaid invoices" query that could drift from it.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** supplier_register | purchase_order_register | payables_aging | goods_receipt_register. */
    #[Url]
    public string $report = 'supplier_register';

    #[Url]
    public string $supplier = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $dateFrom = '';

    #[Url]
    public string $dateTo = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    private const REPORTS = ['supplier_register', 'purchase_order_register', 'payables_aging', 'goods_receipt_register'];

    public function mount(): void
    {
        Gate::authorize(Permission::ReportsView->value);
    }

    public function selectReport(string $report): void
    {
        $this->report = in_array($report, self::REPORTS, true) ? $report : 'supplier_register';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['supplier', 'status', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function updatedSupplier(): void
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
            $this->report === 'payables_aging' ? 'landscape' : 'portrait',
        );
    }

    private function reportTitle(): string
    {
        return match ($this->report) {
            'purchase_order_register' => 'Purchase Order Register',
            'payables_aging' => 'Payables Aging',
            'goods_receipt_register' => 'Goods Receipt Register',
            default => 'Supplier Register',
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
            'purchase_order_register' => [$this->purchaseOrderHeaders(), $this->purchaseOrderExportRows()],
            'payables_aging' => [$this->payablesAgingHeaders(), $this->payablesAgingExportRows()],
            'goods_receipt_register' => [$this->goodsReceiptHeaders(), $this->goodsReceiptExportRows()],
            default => [$this->supplierHeaders(), $this->supplierExportRows()],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->report) {
            'purchase_order_register' => $this->purchaseOrderQuery()->paginate($this->perPage, page: $this->page),
            'payables_aging' => $this->paginateArray($this->payablesAgingRows()),
            'goods_receipt_register' => $this->goodsReceiptQuery()->paginate($this->perPage, page: $this->page),
            default => $this->supplierQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    /**
     * @param  list<object>  $items
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function paginateArray(array $items): LengthAwarePaginator
    {
        $total = count($items);
        $slice = array_slice($items, ($this->page - 1) * $this->perPage, $this->perPage);

        return new LengthAwarePaginator($slice, $total, $this->perPage, $this->page, [
            'path' => request()->url(),
            'pageName' => 'page',
        ]);
    }

    // ── Supplier Register ───────────────────────────────────────────────

    private function supplierQuery(): QueryBuilder
    {
        return DB::table('suppliers as s')
            ->leftJoin('supplier_categories as c', 'c.id', '=', 's.category_id')
            ->when($this->supplier !== '', fn ($q) => $q->where('s.id', (int) $this->supplier))
            ->when($this->status === 'active', fn ($q) => $q->where('s.is_active', true)->where('s.is_archived', false))
            ->when($this->status === 'archived', fn ($q) => $q->where('s.is_archived', true))
            ->orderBy('s.name')
            ->select([
                's.id', 's.code', 's.name', 's.niu', 'c.name as category_name',
                's.phone', 's.is_active', 's.is_archived',
            ]);
    }

    /**
     * @return list<string>
     */
    private function supplierHeaders(): array
    {
        return ['Code', 'Name', 'NIU', 'Category', 'Phone', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function supplierExportRows(): iterable
    {
        foreach ($this->supplierQuery()->get() as $row) {
            /** @var object{code: string, name: string, niu: string|null, category_name: string|null, phone: string|null, is_active: int|bool, is_archived: int|bool} $row */
            yield [
                $row->code,
                $row->name,
                $row->niu ?? '',
                $row->category_name ?? '',
                $row->phone ?? '',
                $row->is_archived ? 'Archived' : ($row->is_active ? 'Active' : 'Inactive'),
            ];
        }
    }

    // ── Purchase Order Register ─────────────────────────────────────────

    private function purchaseOrderQuery(): QueryBuilder
    {
        return DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->when($this->supplier !== '', fn ($q) => $q->where('po.supplier_id', (int) $this->supplier))
            ->when($this->status !== '', fn ($q) => $q->where('po.status', $this->status))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('po.order_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('po.order_date', '<=', $this->dateTo))
            ->orderByDesc('po.order_date')
            ->orderByDesc('po.id')
            ->select([
                'po.id', 'po.po_no', 'po.order_date', 'po.total_ttc', 'po.status',
                's.name as supplier_name',
            ]);
    }

    /**
     * @return list<string>
     */
    private function purchaseOrderHeaders(): array
    {
        return ['Order No.', 'Supplier', 'Date', 'Total', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function purchaseOrderExportRows(): iterable
    {
        foreach ($this->purchaseOrderQuery()->get() as $row) {
            /** @var object{po_no: string, supplier_name: string, order_date: string, total_ttc: int|string, status: string} $row */
            yield [
                $row->po_no,
                $row->supplier_name,
                $row->order_date,
                $row->total_ttc,
                ucfirst(str_replace('_', ' ', $row->status)),
            ];
        }
    }

    // ── Payables Aging ──────────────────────────────────────────────────

    /**
     * @return list<object{supplier_id: int, supplier_name: string, current: int, days_1_30: int, days_31_60: int, days_61_90: int, days_90_plus: int, total: int}&\stdClass>
     */
    private function payablesAgingRows(): array
    {
        $aged = app(AgedPayables::class)->handle();

        $rows = $aged['rows'];

        if ($this->supplier !== '') {
            $rows = array_values(array_filter($rows, fn ($row): bool => $row->supplier_id === (int) $this->supplier));
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function payablesAgingHeaders(): array
    {
        return ['Supplier', 'Current', '1-30 Days', '31-60 Days', '61-90 Days', '90+ Days', 'Total'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function payablesAgingExportRows(): iterable
    {
        foreach ($this->payablesAgingRows() as $row) {
            yield [
                $row->supplier_name,
                $row->current,
                $row->days_1_30,
                $row->days_31_60,
                $row->days_61_90,
                $row->days_90_plus,
                $row->total,
            ];
        }
    }

    // ── Goods Receipt Register ──────────────────────────────────────────

    private function goodsReceiptQuery(): QueryBuilder
    {
        return DB::table('goods_receipts as gr')
            ->join('suppliers as s', 's.id', '=', 'gr.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->when($this->supplier !== '', fn ($q) => $q->where('gr.supplier_id', (int) $this->supplier))
            ->when($this->status !== '', fn ($q) => $q->where('gr.status', $this->status))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('gr.received_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('gr.received_on', '<=', $this->dateTo))
            ->orderByDesc('gr.received_on')
            ->orderByDesc('gr.id')
            ->select([
                'gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.has_discrepancy', 'gr.status',
                's.name as supplier_name', 'po.po_no',
            ]);
    }

    /**
     * @return list<string>
     */
    private function goodsReceiptHeaders(): array
    {
        return ['Receipt No.', 'Supplier', 'PO No.', 'Received Date', 'Discrepancy'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function goodsReceiptExportRows(): iterable
    {
        foreach ($this->goodsReceiptQuery()->get() as $row) {
            /** @var object{receipt_no: string, supplier_name: string, po_no: string|null, received_on: string, has_discrepancy: int|bool} $row */
            yield [
                $row->receipt_no,
                $row->supplier_name,
                $row->po_no ?? '',
                $row->received_on,
                $row->has_discrepancy ? 'Yes' : 'No',
            ];
        }
    }

    // ── Filter option lists ─────────────────────────────────────────────

    /**
     * @return list<array{id: int, name: string}>
     */
    private function supplierOptions(): array
    {
        $options = [];

        foreach (DB::table('suppliers')->orderBy('name')->get(['id', 'name']) as $row) {
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
            'purchase_order_register' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'sent', 'label' => 'Sent'],
                ['value' => 'partially_received', 'label' => 'Partially received'],
                ['value' => 'closed', 'label' => 'Closed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'goods_receipt_register' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'confirmed', 'label' => 'Confirmed'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
            'supplier_register' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'archived', 'label' => 'Archived'],
            ],
            default => [],
        };
    }

    /**
     * @return list<array{value: string, label: string, count: int}>
     */
    private function reportTabs(): array
    {
        return [
            ['value' => 'supplier_register', 'label' => 'Supplier Register', 'count' => 0],
            ['value' => 'purchase_order_register', 'label' => 'Purchase Order Register', 'count' => 0],
            ['value' => 'payables_aging', 'label' => 'Payables Aging', 'count' => 0],
            ['value' => 'goods_receipt_register', 'label' => 'Goods Receipt Register', 'count' => 0],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.procurement.reports.index', [
            'rows' => $this->rows(),
            'reportTabs' => $this->reportTabs(),
            'supplierOptions' => $this->supplierOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
