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

    /**
     * @return object{id:int, po_no:string, supplier_id:int, supplier_name:string, supplier_code:string, order_date:string, expected_delivery_date:?string, delivery_address:?string, currency:string, subtotal_ht:int, tax_total:int, total_ttc:int, retention_rate_bp:int, status:string, created_by:int, approved_by:?int, approved_at:?string, sent_at:?string, closed_reason:?string}
     */
    private function order(): object
    {
        /** @var object $order */
        $order = DB::table('purchase_orders as po')
            ->join('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('po.id', $this->orderId)
            ->firstOrFail([
                'po.id', 'po.po_no', 'po.supplier_id', 's.name as supplier_name', 's.code as supplier_code',
                'po.order_date', 'po.expected_delivery_date', 'po.delivery_address', 'po.currency',
                'po.subtotal_ht', 'po.tax_total', 'po.total_ttc', 'po.retention_rate_bp', 'po.status',
                'po.created_by', 'po.approved_by', 'po.approved_at', 'po.sent_at', 'po.closed_reason',
            ]);

        return $order;
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function lines(): \Illuminate\Support\Collection
    {
        return DB::table('purchase_order_lines')
            ->where('purchase_order_id', $this->orderId)
            ->orderBy('line_no')
            ->get([
                'line_no', 'description', 'quantity', 'unit_of_measure', 'unit_price_ht',
                'discount_rate_bp', 'amount_ht', 'tax_amount', 'amount_ttc', 'qty_received', 'qty_invoiced',
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

        return view('livewire.procurement.purchase-orders.show', [
            'order' => $order,
            'lines' => $this->lines(),
            'createdByName' => $this->userName($order->created_by),
            'approvedByName' => $this->userName($order->approved_by),
        ]);
    }
}
