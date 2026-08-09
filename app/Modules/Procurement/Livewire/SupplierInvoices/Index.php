<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\SupplierInvoices;

use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the supplier-invoice list:
 * search by our FF number or the supplier's own number, filter by status,
 * match exceptions and unresolved withholding surfaced as KPIs (the two
 * states that BLOCK approval). Gated `procurement.invoice_view`.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(SupplierInvoicePermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'status']);
        $this->page = 1;
    }

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('supplier_invoices as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id');

        if ($this->search !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $this->search).'%';
            $query->where(function (QueryBuilder $inner) use ($term): void {
                $inner->where('i.internal_no', 'like', $term)
                    ->orWhere('i.supplier_invoice_no', 'like', $term)
                    ->orWhere('s.name', 'like', $term);
            });
        }

        if ($this->status !== '') {
            $query->where('i.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'i.id', 'i.internal_no', 'i.supplier_invoice_no', 'i.invoice_date', 'i.due_date',
                'i.total_ttc', 'i.withholding_total', 'i.net_payable', 'i.status', 'i.match_status',
                'i.withholding_unresolved', 's.name as supplier_name',
            ])
            ->orderByDesc('i.invoice_date')
            ->orderByDesc('i.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'pending_approval' => DB::table('supplier_invoices')->where('status', 'pending_approval')->count(),
            'match_exceptions' => DB::table('supplier_invoices')->where('status', 'match_exception')->count(),
            'withholding_unresolved' => DB::table('supplier_invoices')
                ->where('withholding_unresolved', true)
                ->whereNotIn('status', ['posted', 'partially_paid', 'paid', 'cancelled'])
                ->count(),
            'posted' => DB::table('supplier_invoices')->where('status', 'posted')->count(),
        ];

        return view('livewire.procurement.supplier-invoices.index', [
            'invoices' => $paginator,
            'kpis' => $kpis,
        ]);
    }
}
