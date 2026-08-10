<?php

declare(strict_types=1);

namespace App\Modules\Tax\Livewire\Reports;

use App\Modules\Identity\Domain\Permission;
use App\Modules\Reporting\Support\ExcelExport;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tax Reports at /reports/tax (route wired centrally), gated the generic
 * `reports.view` right shared by every cross-module report cluster - this
 * screen is a read-only rollup over data owned by the Tax module's own
 * screens (Declarations\Index, TaxConfiguration), never a place to edit it.
 *
 * Four tabs, each a plain DB::table() read (00-core 6.2 rule: reports never
 * reach through another module's Eloquent model, and this IS the Tax
 * module's own tables so a query builder keeps the read side decoupled from
 * whatever the write-side Models decide to eager-load):
 *   - Declarations Register: `tax_declarations` - type, period, status,
 *     filed date, amount.
 *   - Withholding Register:  `withholding_attestations` joined to
 *     `suppliers` - supplier, amount, rate, date.
 *   - Tax Code Configuration Summary: `tax_codes` - a config snapshot, not
 *     a transactional register, so it ignores the period filters.
 *   - VAT Summary: `vat_proratas` joined to `fiscal_years` - the prorata
 *     basis/rate/turnover figures are the only VAT-specific dataset with a
 *     real summary shape; per spec fallback would be `tax_declarations`
 *     filtered to TVA-type rows, but vat_proratas exists and carries
 *     meaningful numbers, so it is used instead.
 *
 * On-screen preview paginates a query per tab; export methods always
 * re-run the unpaginated query so the spreadsheet/PDF carries every row.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which report is showing: declarations | withholding | tax-codes | vat-summary. */
    #[Url]
    public string $tab = 'declarations';

    #[Url]
    public string $fiscalYearId = '';

    #[Url]
    public string $declarationType = '';

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
        $this->tab = in_array($tab, ['declarations', 'withholding', 'tax-codes', 'vat-summary'], true)
            ? $tab
            : 'declarations';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['fiscalYearId', 'declarationType', 'status']);
        $this->resetPage();
    }

    public function updatedFiscalYearId(): void
    {
        $this->resetPage();
    }

    public function updatedDeclarationType(): void
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
     * @return \Illuminate\Database\Query\Builder
     */
    private function declarationsQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('tax_declarations as td')
            ->when($this->fiscalYearId !== '', fn ($q) => $q->where('td.fiscal_year_id', (int) $this->fiscalYearId))
            ->when($this->declarationType !== '', fn ($q) => $q->where('td.declaration_type', $this->declarationType))
            ->when($this->status !== '', fn ($q) => $q->where('td.status', $this->status))
            ->orderByDesc('td.period_year')
            ->orderByDesc('td.period_month')
            ->orderByDesc('td.id')
            ->select([
                'td.id', 'td.declaration_type', 'td.period_type', 'td.period_year', 'td.period_month',
                'td.status', 'td.filed_at', 'td.due_date', 'td.amount_declared', 'td.amount_paid',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function withholdingQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('withholding_attestations as wa')
            ->join('suppliers as s', 's.id', '=', 'wa.supplier_id')
            ->when($this->fiscalYearId !== '', function ($q): void {
                $years = DB::table('fiscal_years')->where('id', (int) $this->fiscalYearId)
                    ->first(['starts_on', 'ends_on']);

                if ($years !== null) {
                    $q->whereRaw(
                        "STR_TO_DATE(CONCAT(wa.period_year, '-', LPAD(wa.period_month, 2, '0'), '-01'), '%Y-%m-%d') BETWEEN ? AND ?",
                        [$years->starts_on, $years->ends_on]
                    );
                }
            })
            ->when($this->status !== '', fn ($q) => $q->where('wa.status', $this->status))
            ->orderByDesc('wa.period_year')
            ->orderByDesc('wa.period_month')
            ->orderByDesc('wa.id')
            ->select([
                'wa.id', 'wa.attestation_no', 's.name as supplier_name', 'wa.period_year', 'wa.period_month',
                'wa.base_amount', 'wa.rate_bp_applied', 'wa.withheld_amount', 'wa.status', 'wa.issued_at',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function taxCodesQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('tax_codes as tc')
            ->when($this->status !== '', function ($q): void {
                $q->where('tc.is_active', $this->status === 'active');
            })
            ->orderBy('tc.tax_type')
            ->orderBy('tc.code')
            ->orderBy('tc.effective_from')
            ->select([
                'tc.id', 'tc.code', 'tc.name', 'tc.tax_type', 'tc.rate_bp', 'tc.direction',
                'tc.is_exempt', 'tc.is_zero_rated', 'tc.is_active', 'tc.effective_from', 'tc.effective_to',
            ]);
    }

    /**
     * @return \Illuminate\Database\Query\Builder
     */
    private function vatSummaryQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('vat_proratas as vp')
            ->join('fiscal_years as fy', 'fy.id', '=', 'vp.fiscal_year_id')
            ->when($this->fiscalYearId !== '', fn ($q) => $q->where('vp.fiscal_year_id', (int) $this->fiscalYearId))
            ->orderByDesc('fy.starts_on')
            ->orderBy('vp.basis')
            ->select([
                'vp.id', 'fy.code as fiscal_year_code', 'vp.basis', 'vp.rate_bp',
                'vp.numerator_amount', 'vp.denominator_amount', 'vp.confirmed_at', 'vp.computed_at',
            ]);
    }

    /**
     * @return LengthAwarePaginator<int, object>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'withholding' => $this->withholdingQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            'tax-codes' => $this->taxCodesQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            'vat-summary' => $this->vatSummaryQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
            default => $this->declarationsQuery()->paginate($this->perPage, ['*'], 'page', $this->page),
        };
    }

    /**
     * @return array{title: string, headers: list<string>, rows: list<list<mixed>>}
     */
    private function exportData(): array
    {
        return match ($this->tab) {
            'withholding' => [
                'title' => 'Withholding Register',
                'headers' => ['Attestation No', 'Supplier', 'Period', 'Base', 'Rate', 'Withheld', 'Status', 'Issued'],
                'rows' => $this->withholdingQuery()->get()->map(fn (object $row): array => [
                    $row->attestation_no,
                    $row->supplier_name,
                    sprintf('%04d-%02d', $row->period_year, $row->period_month),
                    Money::of((int) $row->base_amount)->format(false),
                    number_format($row->rate_bp_applied / 1000, 2).'%',
                    Money::of((int) $row->withheld_amount)->format(false),
                    ucfirst($row->status),
                    $row->issued_at !== null ? (string) $row->issued_at : '—',
                ])->all(),
            ],
            'tax-codes' => [
                'title' => 'Tax Code Configuration Summary',
                'headers' => ['Code', 'Name', 'Type', 'Rate', 'Direction', 'Exempt', 'Zero-rated', 'Active', 'Effective from', 'Effective to'],
                'rows' => $this->taxCodesQuery()->get()->map(fn (object $row): array => [
                    $row->code,
                    $row->name,
                    $row->tax_type,
                    number_format($row->rate_bp / 1000, 2).'%',
                    ucfirst($row->direction),
                    $row->is_exempt ? 'Yes' : 'No',
                    $row->is_zero_rated ? 'Yes' : 'No',
                    $row->is_active ? 'Yes' : 'No',
                    (string) $row->effective_from,
                    $row->effective_to !== null ? (string) $row->effective_to : '—',
                ])->all(),
            ],
            'vat-summary' => [
                'title' => 'VAT Summary',
                'headers' => ['Fiscal Year', 'Basis', 'Rate', 'Numerator', 'Denominator', 'Confirmed'],
                'rows' => $this->vatSummaryQuery()->get()->map(fn (object $row): array => [
                    $row->fiscal_year_code,
                    ucfirst($row->basis),
                    number_format($row->rate_bp / 1000, 2).'%',
                    Money::of((int) $row->numerator_amount)->format(false),
                    Money::of((int) $row->denominator_amount)->format(false),
                    $row->confirmed_at !== null ? 'Yes' : 'No',
                ])->all(),
            ],
            default => [
                'title' => 'Declarations Register',
                'headers' => ['Type', 'Period', 'Status', 'Filed date', 'Due date', 'Amount declared', 'Amount paid'],
                'rows' => $this->declarationsQuery()->get()->map(fn (object $row): array => [
                    $this->declarationTypeLabel($row->declaration_type),
                    $row->period_month > 0
                        ? sprintf('%04d-%02d', $row->period_year, $row->period_month)
                        : (string) $row->period_year,
                    ucfirst(str_replace('_', ' ', $row->status)),
                    $row->filed_at !== null ? (string) $row->filed_at : '—',
                    $row->due_date !== null ? (string) $row->due_date : '—',
                    Money::of((int) $row->amount_declared)->format(false),
                    Money::of((int) $row->amount_paid)->format(false),
                ])->all(),
            ],
        };
    }

    private function declarationTypeLabel(string $code): string
    {
        return match ($code) {
            'tva_monthly' => 'TVA (monthly)',
            'withholding_monthly' => 'Withholding (monthly)',
            'dsf_annual' => 'DSF (annual)',
            default => str_replace('_', ' ', $code),
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
        $orientation = in_array($this->tab, ['withholding', 'declarations'], true) ? 'landscape' : 'portrait';

        return PdfExport::download(
            $data['title'],
            $data['headers'],
            $data['rows'],
            str($data['title'])->slug()->value().'.pdf',
            $orientation,
        );
    }

    /**
     * @return Collection<int, object>
     */
    private function fiscalYearOptions(): Collection
    {
        return DB::table('fiscal_years')->orderByDesc('starts_on')->get(['id', 'code']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function declarationTypeOptions(): array
    {
        return [
            ['value' => 'tva_monthly', 'label' => 'TVA (monthly)'],
            ['value' => 'withholding_monthly', 'label' => 'Withholding (monthly)'],
            ['value' => 'dsf_annual', 'label' => 'DSF (annual)'],
        ];
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'withholding' => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'issued', 'label' => 'Issued'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
                ['value' => 'replaced', 'label' => 'Replaced'],
            ],
            'tax-codes' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
            ],
            'vat-summary' => [],
            default => [
                ['value' => 'draft', 'label' => 'Draft'],
                ['value' => 'generated', 'label' => 'Generated'],
                ['value' => 'under_review', 'label' => 'Under review'],
                ['value' => 'filed', 'label' => 'Filed'],
                ['value' => 'paid', 'label' => 'Paid'],
                ['value' => 'amended', 'label' => 'Amended'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
            ],
        };
    }

    public function render(): mixed
    {
        return view('livewire.tax.reports.index', [
            'rows' => $this->rows(),
            'fiscalYearOptions' => $this->fiscalYearOptions(),
            'declarationTypeOptions' => $this->declarationTypeOptions(),
            'statusOptions' => $this->statusOptions(),
        ]);
    }
}
