<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\Suppliers;

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * docs/specs/03-tax-procurement.md §10 - the supplier profile, tabbed:
 * Details | Purchase Orders | Goods Receipts. The invoice / payment /
 * attestation tabs land with their own work packages (F3/F4) - the tab
 * strip only offers what exists.
 *
 * The encrypted bank/momo identifiers are surfaced MASKED (last three
 * characters): the profile proves an account is on file without turning
 * every screen-glance into a data leak (00-core §9.5).
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $supplierId;

    #[Url]
    public string $tab = 'details';

    public function mount(int $supplier): void
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $this->supplierId = $supplier;

        if (! in_array($this->tab, ['details', 'orders', 'receipts'], true)) {
            $this->tab = 'details';
        }
    }

    public function selectTab(string $tab): void
    {
        if (in_array($tab, ['details', 'orders', 'receipts'], true)) {
            $this->tab = $tab;
        }
    }

    /** Last three characters only - enough to match against a bank slip. */
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_repeat('*', max(0, strlen($value) - 3)).substr($value, -3);
    }

    public function render(): mixed
    {
        /** @var Supplier $supplier */
        $supplier = Supplier::query()->findOrFail($this->supplierId);

        $categoryName = $supplier->category_id === null
            ? null
            : DB::table('supplier_categories')->where('id', $supplier->category_id)->value('name');

        $payableAccount = DB::table('chart_of_accounts')->where('id', $supplier->payable_account_id)->value('code');

        $orders = DB::table('purchase_orders')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('order_date')->orderByDesc('id')
            ->paginate(25, ['id', 'po_no', 'order_date', 'status', 'total_ttc'], 'orders_page');

        $receipts = DB::table('goods_receipts as gr')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->where('gr.supplier_id', $supplier->id)
            ->orderByDesc('gr.received_on')->orderByDesc('gr.id')
            ->paginate(25, ['gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.status', 'gr.has_discrepancy', 'po.po_no'], 'receipts_page');

        return view('livewire.procurement.suppliers.show', [
            'supplier' => $supplier,
            'categoryName' => $categoryName,
            'payableAccount' => $payableAccount,
            'maskedRib' => self::mask($supplier->bank_account_rib),
            'maskedMomo' => self::mask($supplier->mobile_money_number),
            'orders' => $orders,
            'receipts' => $receipts,
        ]);
    }
}
