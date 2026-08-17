<?php

declare(strict_types=1);

namespace App\Modules\Assets\Livewire\Reports;

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
 * Assets & Inventory Reports at /reports/assets-inventory (route wired
 * centrally), gated `reports.view`, mirroring Welfare\Livewire\Transport\Index
 * and the sibling report-cluster screens (Academics\Livewire\Reports\Index,
 * Assessment\Livewire\Reports\Index): KPI strip, `#[Url]` filters, `selectTab`,
 * `DB::table()` per tab, one paginated query per render, plus Export Excel /
 * Export PDF / Print actions shared with the other report-cluster screens
 * (App\Modules\Reporting\Support\ExcelExport / PdfExport).
 *
 * This single screen covers BOTH the Assets module and the Inventory module
 * per the Hub's single "Assets & Inventory Reports" category, so inventory
 * data is read via cross-module DB::table reads - the same read-only pattern
 * every report-cluster screen uses (ModuleBoundaryTest only forbids Eloquent
 * Models from another module, not DB::table() reads).
 *
 * Four tabs, one paginated DB::table() query each (table/column names read
 * straight from the migrations, never guessed):
 *
 *   - Asset Register: `assets` joined to `asset_categories` - tag, name,
 *     category, acquisition cost, status.
 *   - Depreciation Schedule: `depreciation_schedules` (accounting basis)
 *     joined to `assets` - asset, period, charge, accumulated, net book value.
 *   - Stock Valuation: `items` joined to `stock_balances` - item, location,
 *     quantity on hand, value on hand.
 *   - Stock Movement Register: `stock_movements` joined to `items` and
 *     `store_locations` - date, item, location, type, quantity, reference.
 *
 * Export methods reuse the exact same filtered query as the on-screen tab,
 * unpaginated, capped by `EXPORT_ROW_LIMIT` so a browse-scale export cannot
 * pull the whole table into memory (00-core 6.2 rule 8's spirit for exports).
 */
#[Layout('layouts.app')]
#[Title('Assets & Inventory Reports')]
final class Index extends Component
{
    private const int EXPORT_ROW_LIMIT = 5000;

    /** Which report is showing: register | depreciation | valuation | movements. */
    #[Url]
    public string $tab = 'register';

    #[Url]
    public string $category = '';

    #[Url]
    public string $location = '';

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

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['register', 'depreciation', 'valuation', 'movements'], true)
            ? $tab
            : 'register';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['category', 'location', 'dateFrom', 'dateTo']);
        $this->resetPage();
    }

    public function updatedCategory(): void
    {
        $this->resetPage();
    }

    public function updatedLocation(): void
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
            'landscape',
        );
    }

    private function reportTitle(): string
    {
        return match ($this->tab) {
            'depreciation' => 'Depreciation Schedule',
            'valuation' => 'Stock Valuation',
            'movements' => 'Stock Movement Register',
            default => 'Asset Register',
        };
    }

    private function reportSlug(): string
    {
        return match ($this->tab) {
            'depreciation' => 'depreciation-schedule',
            'valuation' => 'stock-valuation',
            'movements' => 'stock-movement-register',
            default => 'asset-register',
        };
    }

    /**
     * @return array{0: list<string>, 1: iterable<int, list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->tab) {
            'depreciation' => [$this->depreciationHeaders(), $this->depreciationExportRows()],
            'valuation' => [$this->valuationHeaders(), $this->valuationExportRows()],
            'movements' => [$this->movementHeaders(), $this->movementExportRows()],
            default => [$this->registerHeaders(), $this->registerExportRows()],
        };
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'depreciation' => $this->depreciationQuery()->paginate($this->perPage, page: $this->page),
            'valuation' => $this->valuationQuery()->paginate($this->perPage, page: $this->page),
            'movements' => $this->movementQuery()->paginate($this->perPage, page: $this->page),
            default => $this->registerQuery()->paginate($this->perPage, page: $this->page),
        };
    }

    // ── Asset Register ──────────────────────────────────────────────────

    private function registerQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('assets as a')
            ->join('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->when($this->category !== '', fn ($q) => $q->where('a.asset_category_id', (int) $this->category))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('a.acquisition_date', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('a.acquisition_date', '<=', $this->dateTo))
            ->orderBy('a.tag_number')
            ->select([
                'a.id', 'a.tag_number', 'a.name', 'a.status', 'a.acquisition_date', 'a.acquisition_cost',
                'c.name as category_name',
            ]);
    }

    /**
     * @return list<string>
     */
    private function registerHeaders(): array
    {
        return ['Tag Number', 'Name', 'Category', 'Acquisition Date', 'Acquisition Cost', 'Status'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function registerExportRows(): iterable
    {
        foreach ($this->registerQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{tag_number: string, name: string, category_name: string, acquisition_date: string, acquisition_cost: int|string, status: string} $row */
            yield [
                $row->tag_number,
                $row->name,
                $row->category_name,
                $row->acquisition_date,
                $row->acquisition_cost,
                ucfirst(str_replace('_', ' ', $row->status)),
            ];
        }
    }

    // ── Depreciation Schedule ───────────────────────────────────────────

    private function depreciationQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('depreciation_schedules as ds')
            ->join('assets as a', 'a.id', '=', 'ds.asset_id')
            ->join('asset_categories as c', 'c.id', '=', 'a.asset_category_id')
            ->join('fiscal_years as fy', 'fy.id', '=', 'ds.fiscal_year_id')
            ->where('ds.basis', 'accounting')
            ->when($this->category !== '', fn ($q) => $q->where('a.asset_category_id', (int) $this->category))
            ->orderBy('a.tag_number')
            ->orderByDesc('ds.fiscal_year_id')
            ->orderByDesc('ds.period_month')
            ->select([
                // The column is headed "Fiscal Year", so it carries the year's
                // code ("2026"), not the internal row id the operator cannot
                // read and did not ask for.
                'ds.id', 'a.tag_number', 'a.name as asset_name', 'fy.code as fiscal_year_code', 'ds.period_month',
                'ds.charge', 'ds.closing_accumulated', 'ds.net_book_value',
            ]);
    }

    /**
     * @return list<string>
     */
    private function depreciationHeaders(): array
    {
        return ['Asset Tag', 'Asset Name', 'Fiscal Year', 'Period Month', 'Charge', 'Accumulated', 'Net Book Value'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function depreciationExportRows(): iterable
    {
        foreach ($this->depreciationQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{tag_number: string, asset_name: string, fiscal_year_code: string, period_month: int|string, charge: int|string, closing_accumulated: int|string, net_book_value: int|string} $row */
            yield [
                $row->tag_number,
                $row->asset_name,
                $row->fiscal_year_code,
                $row->period_month,
                $row->charge,
                $row->closing_accumulated,
                $row->net_book_value,
            ];
        }
    }

    // ── Stock Valuation ─────────────────────────────────────────────────

    private function valuationQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('items as i')
            ->join('stock_balances as sb', 'sb.item_id', '=', 'i.id')
            ->join('store_locations as sl', 'sl.id', '=', 'sb.store_location_id')
            ->join('item_categories as ic', 'ic.id', '=', 'i.item_category_id')
            ->when($this->category !== '', fn ($q) => $q->where('i.item_category_id', (int) $this->category))
            ->when($this->location !== '', fn ($q) => $q->where('sb.store_location_id', (int) $this->location))
            ->orderBy('i.item_code')
            ->orderBy('sl.name')
            ->select([
                'i.id', 'i.item_code', 'i.name as item_name', 'ic.name as category_name',
                'sl.name as location_name', 'sb.quantity_on_hand', 'sb.value_on_hand',
            ]);
    }

    /**
     * @return list<string>
     */
    private function valuationHeaders(): array
    {
        return ['Item Code', 'Item Name', 'Category', 'Location', 'Quantity on Hand', 'Value on Hand'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function valuationExportRows(): iterable
    {
        foreach ($this->valuationQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{item_code: string, item_name: string, category_name: string, location_name: string, quantity_on_hand: string, value_on_hand: int|string} $row */
            yield [
                $row->item_code,
                $row->item_name,
                $row->category_name,
                $row->location_name,
                $row->quantity_on_hand,
                $row->value_on_hand,
            ];
        }
    }

    // ── Stock Movement Register ─────────────────────────────────────────

    private function movementQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('stock_movements as m')
            ->join('items as i', 'i.id', '=', 'm.item_id')
            ->join('store_locations as sl', 'sl.id', '=', 'm.store_location_id')
            ->when($this->location !== '', fn ($q) => $q->where('m.store_location_id', (int) $this->location))
            ->when($this->dateFrom !== '', fn ($q) => $q->whereDate('m.moved_on', '>=', $this->dateFrom))
            ->when($this->dateTo !== '', fn ($q) => $q->whereDate('m.moved_on', '<=', $this->dateTo))
            ->orderByDesc('m.moved_on')
            ->orderByDesc('m.id')
            ->select([
                'm.id', 'm.moved_on', 'i.item_code', 'i.name as item_name', 'sl.name as location_name',
                'm.movement_type', 'm.quantity', 'm.document_ref',
            ]);
    }

    /**
     * @return list<string>
     */
    private function movementHeaders(): array
    {
        return ['Date', 'Item', 'Location', 'Type', 'Quantity', 'Reference'];
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function movementExportRows(): iterable
    {
        foreach ($this->movementQuery()->limit(self::EXPORT_ROW_LIMIT)->get() as $row) {
            /** @var object{moved_on: string, item_code: string, item_name: string, location_name: string, movement_type: string, quantity: string, document_ref: string|null} $row */
            yield [
                $row->moved_on,
                $row->item_code.' — '.$row->item_name,
                $row->location_name,
                ucfirst(str_replace('_', ' ', $row->movement_type)),
                $row->quantity,
                $row->document_ref ?? '',
            ];
        }
    }

    // ── KPI strip ────────────────────────────────────────────────────────

    /**
     * Dataset-wide KPI cards: total assets, net book value total, total
     * stock value, movements this month - never filter-dependent.
     *
     * @return array{total_assets: int, net_book_value: int, stock_value: int, movements_this_month: int}
     */
    private function kpis(): array
    {
        $latestNbvPerAsset = DB::table('depreciation_schedules as ds')
            ->select('ds.asset_id', 'ds.net_book_value')
            ->whereRaw('ds.id = (
                select ds2.id from depreciation_schedules ds2
                where ds2.asset_id = ds.asset_id and ds2.basis = "accounting"
                order by ds2.fiscal_year_id desc, ds2.period_month desc
                limit 1
            )')
            ->where('ds.basis', 'accounting');

        $netBookValue = (int) DB::query()->fromSub($latestNbvPerAsset, 'nbv')->sum('net_book_value');

        $monthStart = \Illuminate\Support\Carbon::today()->startOfMonth()->toDateString();

        return [
            'total_assets' => (int) DB::table('assets')->count(),
            'net_book_value' => $netBookValue,
            'stock_value' => (int) DB::table('stock_balances')->sum('value_on_hand'),
            'movements_this_month' => (int) DB::table('stock_movements')->where('moved_on', '>=', $monthStart)->count(),
        ];
    }

    // ── Filter option lists ─────────────────────────────────────────────

    /**
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(): array
    {
        return match ($this->tab) {
            'valuation' => $this->itemCategoryOptions(),
            'depreciation', 'register' => $this->assetCategoryOptions(),
            default => [],
        };
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function assetCategoryOptions(): array
    {
        $options = [];

        foreach (DB::table('asset_categories')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function itemCategoryOptions(): array
    {
        $options = [];

        foreach (DB::table('item_categories')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function locationOptions(): array
    {
        $options = [];

        foreach (DB::table('store_locations')->orderBy('name')->get(['id', 'name']) as $row) {
            /** @var object{id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->name];
        }

        return $options;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function reportTabs(): array
    {
        return [
            ['value' => 'register', 'label' => 'Asset Register'],
            ['value' => 'depreciation', 'label' => 'Depreciation Schedule'],
            ['value' => 'valuation', 'label' => 'Stock Valuation'],
            ['value' => 'movements', 'label' => 'Stock Movement Register'],
        ];
    }

    public function render(): mixed
    {
        return view('livewire.assets.reports.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'reportTabs' => $this->reportTabs(),
            'categoryOptions' => $this->categoryOptions(),
            'locationOptions' => $this->locationOptions(),
        ]);
    }
}
