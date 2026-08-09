<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\GoodsReceipts;

use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use App\Modules\Procurement\Domain\ProcurementPermission;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the goods-receipt list, with the
 * discrepancy flag surfaced: a receipt with rejected quantities is a
 * blocked three-way match until its credit note or amendment arrives.
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
        $query = DB::table('goods_receipts as gr')
            ->join('suppliers as s', 's.id', '=', 'gr.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id');

        if (GoodsReceiptStatus::tryFrom($this->status) !== null) {
            $query->where('gr.status', $this->status);
        }

        return $query;
    }

    public function render(): mixed
    {
        $paginator = $this->baseQuery()
            ->select([
                'gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.status', 'gr.has_discrepancy',
                'gr.delivery_note_ref', 's.name as supplier_name', 'po.po_no',
            ])
            ->orderByDesc('gr.received_on')
            ->orderByDesc('gr.id')
            ->paginate($this->perPage, ['*'], 'page', $this->page);

        $kpis = [
            'draft' => DB::table('goods_receipts')->where('status', 'draft')->count(),
            'discrepancies' => DB::table('goods_receipts')->where('has_discrepancy', true)->count(),
        ];

        return view('livewire.procurement.goods-receipts.index', [
            'receipts' => $paginator,
            'kpis' => $kpis,
            'statusOptions' => array_map(static fn (GoodsReceiptStatus $s): string => $s->value, GoodsReceiptStatus::cases()),
        ]);
    }
}
