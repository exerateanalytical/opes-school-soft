<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\PurchaseOrders;

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the purchase-order list. Open
 * commitments are the KPI that matters: approved-but-not-fully-received
 * value is exactly what the year-end 4818 cut-off will interrogate.
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    #[Url]
    public string $status = '';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    public function mount(): void
    {
        Gate::authorize(ProcurementPermission::VIEW);
    }

    public function resetFilters(): void
    {
        $this->reset(['status']);
        $this->page = 1;
    }

    public function updatedStatus(): void
    {
        $this->page = 1;
    }

    private function baseQuery(): QueryBuilder
    {
        $query = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id');

        if (PurchaseOrderStatus::tryFrom($this->status) !== null) {
            $query->where('po.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'po.id', 'po.po_no', 'po.order_date', 'po.status', 'po.total_ttc',
                'po.expected_delivery_date', 's.name as supplier_name', 's.code as supplier_code',
            ])
            ->orderByDesc('po.order_date')
            ->orderByDesc('po.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $open = (int) DB::table('purchase_orders')
            ->whereIn('status', ['approved', 'sent', 'partially_received'])
            ->sum('total_ttc');

        return view('livewire.procurement.purchase-orders.index', [
            'orders' => $paginator,
            'openCommitments' => $open,
            'draftCount' => DB::table('purchase_orders')->where('status', 'draft')->count(),
            'statusOptions' => array_map(static fn (PurchaseOrderStatus $s): string => $s->value, PurchaseOrderStatus::cases()),
        ]);
    }
}
