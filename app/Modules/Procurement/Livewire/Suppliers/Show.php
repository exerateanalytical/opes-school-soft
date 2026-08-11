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

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'details';
        }
    }

    /** @var list<string> */
    public const TABS = ['details', 'orders', 'receipts', 'invoices', 'payments'];

    public function selectTab(string $tab): void
    {
        if (in_array($tab, self::TABS, true)) {
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

        $expenseAccount = $supplier->default_expense_account_id === null
            ? null
            : DB::table('chart_of_accounts')->where('id', $supplier->default_expense_account_id)->value('code');

        $defaultTaxCode = $supplier->default_tax_code_id === null
            ? null
            : DB::table('tax_codes')->where('id', $supplier->default_tax_code_id)->value('code');

        $niuVerifiedByName = $supplier->niu_verified_by === null
            ? null
            : DB::table('users')->where('id', $supplier->niu_verified_by)->value('name');

        $createdByName = DB::table('users')->where('id', $supplier->created_by)->value('name');
        $updatedByName = $supplier->updated_by === null
            ? null
            : DB::table('users')->where('id', $supplier->updated_by)->value('name');

        // §4.9 supplier statement: what has been invoiced, what has been
        // settled, and what is still owed. Cancelled invoices are excluded -
        // they never created a payable.
        $invoiceTotals = DB::table('supplier_invoices')
            ->where('supplier_id', $supplier->id)
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(total_ttc), 0) as ttc, COALESCE(SUM(net_payable), 0) as net, COALESCE(SUM(withholding_total), 0) as wht')
            ->first();

        $paidTotal = (int) DB::table('supplier_payment_allocations as spa')
            ->join('supplier_payments as p', 'p.id', '=', 'spa.supplier_payment_id')
            ->where('p.supplier_id', $supplier->id)
            ->whereNull('spa.reversed_at')
            ->where('p.status', '!=', 'voided')
            ->sum('spa.amount');

        // Open commitments (§4.9): approved/sent POs not yet fully invoiced.
        $openCommitments = (int) DB::table('purchase_orders')
            ->where('supplier_id', $supplier->id)
            ->whereIn('status', ['approved', 'sent', 'partially_received', 'received', 'partially_invoiced'])
            ->sum('total_ttc');

        $invoices = DB::table('supplier_invoices')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('invoice_date')->orderByDesc('id')
            ->paginate(25, [
                'id', 'internal_no', 'supplier_invoice_no', 'invoice_date', 'due_date',
                'total_ttc', 'net_payable', 'status', 'match_status',
            ], 'invoices_page');

        $payments = DB::table('supplier_payments')
            ->where('supplier_id', $supplier->id)
            ->orderByDesc('payment_date')->orderByDesc('id')
            ->paginate(25, [
                'id', 'payment_no', 'payment_date', 'payment_method', 'reference',
                'gross_amount', 'withholding_amount', 'net_amount', 'status',
            ], 'payments_page');

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
            'invoices' => $invoices,
            'payments' => $payments,
            'expenseAccount' => $expenseAccount,
            'defaultTaxCode' => $defaultTaxCode,
            'niuVerifiedByName' => $niuVerifiedByName,
            'createdByName' => $createdByName,
            'updatedByName' => $updatedByName,
            'invoiceCount' => (int) ($invoiceTotals->c ?? 0),
            'invoicedTtc' => (int) ($invoiceTotals->ttc ?? 0),
            'invoicedNet' => (int) ($invoiceTotals->net ?? 0),
            'withheldTotal' => (int) ($invoiceTotals->wht ?? 0),
            'paidTotal' => $paidTotal,
            'outstanding' => (int) ($invoiceTotals->net ?? 0) - $paidTotal,
            'openCommitments' => $openCommitments,
        ]);
    }
}
