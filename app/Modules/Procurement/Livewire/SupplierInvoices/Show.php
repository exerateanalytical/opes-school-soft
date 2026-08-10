<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Livewire\SupplierInvoices;

use App\Modules\Procurement\Domain\SupplierInvoicePermission;
use App\Modules\Reporting\Support\PdfExport;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpFoundation\Response;

/**
 * docs/specs/03-tax-procurement.md §4.5 - one supplier invoice's full
 * detail: header, matched PO (if any), line items with their per-line tax
 * and withholding breakdown, and a print-preview of the document. Read-only
 * - approve / post / cancel / credit-note all stay on the list screen
 * (SupplierInvoices\Index). Gated on the SAME permission as that list,
 * `procurement.invoice_view`.
 */
#[Layout('layouts.app')]
final class Show extends Component
{
    public int $invoiceId;

    public function mount(int $invoice): void
    {
        Gate::authorize(SupplierInvoicePermission::VIEW);

        $this->invoiceId = $invoice;

        $exists = DB::table('supplier_invoices')->where('id', $invoice)->exists();

        if (! $exists) {
            abort(404);
        }
    }

    public function exportPdf(): Response
    {
        Gate::authorize(SupplierInvoicePermission::VIEW);

        $invoice = $this->invoice();
        $lines = $this->lines();

        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                (string) $line->line_no,
                (string) $line->description,
                (string) $line->quantity,
                Money::of((int) $line->unit_price_ht)->format(false),
                Money::of((int) $line->tax_amount)->format(false),
                Money::of((int) $line->withholding_amount)->format(false),
                Money::of((int) $line->amount_ht + (int) $line->tax_amount)->format(false),
            ];
        }

        return PdfExport::download(
            title: 'Supplier Invoice '.$invoice->internal_no,
            headers: ['#', 'Description', 'Qty', 'Unit Price', 'Tax', 'Withholding', 'Total'],
            rows: $rows,
            filename: 'supplier-invoice-'.$invoice->internal_no.'.pdf',
        );
    }

    /**
     * @return object{id:int, internal_no:string, supplier_invoice_no:string, supplier_id:int, supplier_name:string, supplier_code:string, purchase_order_id:?int, po_no:?string, invoice_date:string, due_date:string, currency:string, subtotal_ht:int, discount_total:int, tax_total:int, total_ttc:int, withholding_total:int, net_payable:int, retention_amount:int, status:string, match_status:string, withholding_unresolved:bool, created_by:int, approved_by:?int, approved_at:?string}
     */
    private function invoice(): object
    {
        /** @var object $invoice */
        $invoice = DB::table('supplier_invoices as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->where('i.id', $this->invoiceId)
            ->firstOrFail([
                'i.id', 'i.internal_no', 'i.supplier_invoice_no', 'i.supplier_id',
                's.name as supplier_name', 's.code as supplier_code',
                'i.purchase_order_id', 'po.po_no',
                'i.invoice_date', 'i.due_date', 'i.currency',
                'i.subtotal_ht', 'i.discount_total', 'i.tax_total', 'i.total_ttc',
                'i.withholding_total', 'i.net_payable', 'i.retention_amount',
                'i.status', 'i.match_status', 'i.withholding_unresolved',
                'i.created_by', 'i.approved_by', 'i.approved_at',
            ]);

        return $invoice;
    }

    /**
     * @return Collection<int, object>
     */
    private function lines(): Collection
    {
        return DB::table('supplier_invoice_lines')
            ->where('supplier_invoice_id', $this->invoiceId)
            ->orderBy('line_no')
            ->get([
                'line_no', 'description', 'quantity', 'unit_of_measure', 'unit_price_ht',
                'amount_ht', 'tax_rate_bp_applied', 'tax_amount', 'deductible_tax_amount',
                'non_deductible_tax_amount', 'withholding_amount', 'withholding_reason', 'match_status',
            ]);
    }

    /**
     * @return Collection<int, object>
     */
    private function payments(): Collection
    {
        return DB::table('supplier_payment_allocations as spa')
            ->join('supplier_payments as p', 'p.id', '=', 'spa.supplier_payment_id')
            ->where('spa.supplier_invoice_id', $this->invoiceId)
            ->whereNull('spa.reversed_at')
            ->orderBy('p.payment_date')
            ->get(['p.id', 'p.payment_no', 'p.payment_date', 'p.status as payment_status', 'spa.amount']);
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
        $invoice = $this->invoice();

        return view('livewire.procurement.supplier-invoices.show', [
            'invoice' => $invoice,
            'lines' => $this->lines(),
            'payments' => $this->payments(),
            'createdByName' => $this->userName($invoice->created_by),
            'approvedByName' => $this->userName($invoice->approved_by),
        ]);
    }
}
