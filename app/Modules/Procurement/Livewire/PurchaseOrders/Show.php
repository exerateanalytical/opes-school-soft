<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\PurchaseOrders;

use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/03-tax-procurement.md §4.2 - one purchase order's full detail:
 * header, line items, and a print-preview styled like the printed document
 * itself (rather than an instant download the user cannot check first).
 *
 * Read-only: this screen has no lifecycle buttons of its own - approve /
 * send / cancel / close / amend all stay on the list screen where their
 * Actions already live (PurchaseOrders\Index). Gated on the SAME permission
 * as that list, `procurement.view`.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $orderId;

    public function mount(int $order): void
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $this->orderId = $order;

        // 404 early rather than rendering an empty document.
        $exists = DB::table('purchase_orders')->where('id', $order)->exists();

        if (! $exists) {
            abort(404);
        }
    }

    public function exportPdf(): Response
    {
        Gate::authorize(ProcurementPermission::VIEW);

        $order = $this->order();
        $lines = $this->lines();

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                (string) $line->line_no,
                (string) $line->description,
                (string) $line->quantity,
                Money::of((int) $line->unit_price_ht)->format(false),
                Money::of((int) $line->amount_ttc)->format(false),
            ];
        }

        return PdfExport::download(
            title: 'Purchase Order '.$order->po_no,
            headers: ['#', 'Description', 'Qty', 'Unit Price', 'Total'],
            rows: $rows,
            filename: 'purchase-order-'.$order->po_no.'.pdf',
        );
    }

    private function order(): object
    {
        /** @var object $order */
        $order = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->leftJoin('purchase_requisitions as r', 'r.id', '=', 'po.requisition_id')
            ->leftJoin('chart_of_accounts as pa', 'pa.id', '=', 'po.payable_account_id')
            ->leftJoin('fiscal_years as fy', 'fy.id', '=', 'po.fiscal_year_id')
            ->leftJoin('academic_years as ay', 'ay.id', '=', 'po.academic_year_id')
            ->where('po.id', $this->orderId)
            ->firstOrFail([
                'po.id', 'po.po_no', 'po.supplier_id', 's.name as supplier_name', 's.code as supplier_code',
                's.niu as supplier_niu', 's.phone as supplier_phone', 's.email as supplier_email',
                's.payment_terms_days as supplier_payment_terms_days',
                'po.order_date', 'po.expected_delivery_date', 'po.delivery_address', 'po.currency',
                'po.exchange_rate_bp',
                'po.subtotal_ht', 'po.tax_total', 'po.total_ttc', 'po.retention_rate_bp',
                'po.retention_release_due_on', 'po.status',
                'po.requisition_id', 'r.requisition_no',
                'po.payable_account_id', 'pa.code as payable_account_code', 'pa.name as payable_account_name',
                'fy.code as fiscal_year_code', 'ay.name as academic_year_name',
                'po.created_by', 'po.approved_by', 'po.approved_at', 'po.sent_at', 'po.closed_reason',
                'po.version', 'po.created_at', 'po.updated_at',
            ]);

        return $order;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function lines(): \Illuminate\Support\Collection
    {
        return DB::table('purchase_order_lines as l')
            ->leftJoin('chart_of_accounts as ea', 'ea.id', '=', 'l.expense_account_id')
            ->leftJoin('tax_codes as tc', 'tc.id', '=', 'l.tax_code_id')
            ->where('l.purchase_order_id', $this->orderId)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.description', 'l.quantity', 'l.unit_of_measure', 'l.unit_price_ht',
                'l.discount_rate_bp', 'l.amount_ht', 'l.tax_amount', 'l.amount_ttc',
                'l.qty_received', 'l.qty_invoiced', 'l.is_capitalised',
                'ea.code as expense_account_code', 'tc.code as tax_code',
            ]);
    }

    /**
     * §4.3 - receipts booked against this order.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function receipts(): \Illuminate\Support\Collection
    {
        return DB::table('goods_receipts as gr')
            ->leftJoin('users as u', 'u.id', '=', 'gr.received_by')
            ->where('gr.purchase_order_id', $this->orderId)
            ->orderBy('gr.received_on')
            ->get([
                'gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.status', 'gr.has_discrepancy',
                'gr.delivery_note_ref', 'u.name as received_by_name',
            ]);
    }

    /**
     * §4.5 - invoices raised against this order.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function invoices(): \Illuminate\Support\Collection
    {
        return DB::table('supplier_invoices')
            ->where('purchase_order_id', $this->orderId)
            ->orderBy('invoice_date')
            ->get([
                'id', 'internal_no', 'supplier_invoice_no', 'invoice_date', 'due_date',
                'total_ttc', 'net_payable', 'status', 'match_status',
            ]);
    }

    /**
     * §4.2 invariant 5 - an approved PO is immutable; changes are amendments.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function amendments(): \Illuminate\Support\Collection
    {
        return DB::table('purchase_order_amendments as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.amended_by')
            ->where('a.purchase_order_id', $this->orderId)
            ->orderBy('a.amendment_no')
            ->get([
                'a.amendment_no', 'a.reason', 'a.previous_subtotal_ht', 'a.previous_total_ttc',
                'a.amended_at', 'u.name as amended_by_name',
            ]);
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $name = DB::table('users')->where('id', $userId)->value('name');

        return $name === null ? null : (string) $name;
    }

    public function render(): mixed
    {
        $order = $this->order();
        $lines = $this->lines();

        // Fulfilment is derived from the lines themselves - not a second
        // stored counter that could drift from them.
        $qtyOrdered = 0.0;
        $qtyReceived = 0.0;
        $qtyInvoiced = 0.0;

        foreach ($lines as $line) {
            $qtyOrdered += (float) $line->quantity;
            $qtyReceived += (float) $line->qty_received;
            $qtyInvoiced += (float) $line->qty_invoiced;
        }

        $invoices = $this->invoices();

        return view('livewire.procurement.purchase-orders.show', [
            'order' => $order,
            'lines' => $lines,
            'receipts' => $this->receipts(),
            'invoices' => $invoices,
            'amendments' => $this->amendments(),
            'invoicedTotal' => (int) $invoices->sum('total_ttc'),
            'qtyOrdered' => $qtyOrdered,
            'qtyReceived' => $qtyReceived,
            'qtyInvoiced' => $qtyInvoiced,
            'createdByName' => $this->userName($order->created_by),
            'approvedByName' => $this->userName($order->approved_by),
        ]);
    }
}
