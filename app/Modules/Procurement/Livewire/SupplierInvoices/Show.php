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

    private function invoice(): object
    {
        /** @var object $invoice */
        $invoice = DB::table('supplier_invoices as i')
            ->join('suppliers as s', 's.id', '=', 'i.supplier_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'i.purchase_order_id')
            ->leftJoin('chart_of_accounts as pa', 'pa.id', '=', 'i.payable_account_id')
            ->leftJoin('fiscal_years as fy', 'fy.id', '=', 'i.fiscal_year_id')
            ->leftJoin('accounting_periods as ap', 'ap.id', '=', 'i.accounting_period_id')
            ->where('i.id', $this->invoiceId)
            ->firstOrFail([
                'i.id', 'i.internal_no', 'i.supplier_invoice_no', 'i.supplier_id',
                's.name as supplier_name', 's.code as supplier_code', 's.niu as supplier_niu',
                's.payment_terms_days as supplier_payment_terms_days',
                'i.purchase_order_id', 'po.po_no',
                'i.invoice_date', 'i.received_date', 'i.value_date', 'i.due_date',
                'i.currency', 'i.exchange_rate_bp',
                'i.subtotal_ht', 'i.discount_total', 'i.tax_total', 'i.total_ttc',
                'i.withholding_total', 'i.net_payable', 'i.retention_amount',
                'i.status', 'i.match_status', 'i.withholding_unresolved',
                'i.match_override_reason', 'i.match_override_by', 'i.match_override_at',
                'i.unmatched_reason',
                'i.withholding_waived_reason', 'i.withholding_waived_by', 'i.withholding_waived_at',
                'i.payable_account_id', 'pa.code as payable_account_code', 'pa.name as payable_account_name',
                'fy.code as fiscal_year_code', 'ap.period_month', 'ap.status as period_status',
                'i.posted_at', 'i.journal_entry_id', 'i.secondary_journal_entry_id',
                'i.withholding_journal_entry_id',
                'i.cancelled_by', 'i.cancelled_at', 'i.cancellation_reason',
                'i.is_migration', 'i.document_id', 'i.version',
                'i.created_by', 'i.approved_by', 'i.approved_at', 'i.created_at',
            ]);

        return $invoice;
    }

    /**
     * @return Collection<int, object>
     */
    private function lines(): Collection
    {
        return DB::table('supplier_invoice_lines as l')
            ->leftJoin('chart_of_accounts as ea', 'ea.id', '=', 'l.expense_account_id')
            ->leftJoin('tax_codes as tc', 'tc.id', '=', 'l.tax_code_id')
            ->where('l.supplier_invoice_id', $this->invoiceId)
            ->orderBy('l.line_no')
            ->get([
                'l.line_no', 'l.description', 'l.quantity', 'l.unit_of_measure', 'l.unit_price_ht',
                'l.discount_rate_bp', 'l.amount_ht', 'l.tax_rate_bp_applied', 'l.tax_amount',
                'l.deductible_tax_amount', 'l.non_deductible_tax_amount',
                'l.withholding_base', 'l.withholding_rate_bp_applied',
                'l.withholding_amount', 'l.withholding_reason', 'l.withholding_exemption_ref',
                'l.match_status', 'l.matched_qty', 'l.price_variance', 'l.quantity_variance',
                'l.match_exception_reason', 'l.is_capitalised',
                'ea.code as expense_account_code', 'tc.code as tax_code',
            ]);
    }

    /**
     * §4.6 - the ledger postings this invoice produced. `postedLedger()` is
     * not used here because we are reading named entries by id, not scanning
     * the ledger; a reversed entry must still be visible on the document that
     * created it.
     *
     * @return Collection<int, object>
     */
    private function journalEntries(object $invoice): Collection
    {
        $ids = array_values(array_filter([
            $invoice->journal_entry_id,
            $invoice->secondary_journal_entry_id,
            $invoice->withholding_journal_entry_id,
        ]));

        if ($ids === []) {
            return collect();
        }

        return DB::table('journal_entries as je')
            ->leftJoin('journals as j', 'j.id', '=', 'je.journal_id')
            ->whereIn('je.id', $ids)
            ->orderBy('je.id')
            ->get(['je.id', 'je.piece_no', 'je.date', 'je.label', 'je.status', 'j.code as journal_code']);
    }

    /**
     * @return Collection<int, object>
     */
    private function journalLines(Collection $entries): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        return DB::table('journal_entry_lines as jl')
            ->join('chart_of_accounts as a', 'a.id', '=', 'jl.account_id')
            ->whereIn('jl.journal_entry_id', $entries->pluck('id')->all())
            ->orderBy('jl.journal_entry_id')->orderBy('jl.sequence')
            ->get(['jl.journal_entry_id', 'jl.sequence', 'a.code as account_code', 'a.name as account_name', 'jl.label', 'jl.debit', 'jl.credit']);
    }

    /**
     * §4.8 - credit notes raised against this invoice.
     *
     * @return Collection<int, object>
     */
    private function creditNotes(): Collection
    {
        return DB::table('supplier_credit_notes')
            ->where('original_invoice_id', $this->invoiceId)
            ->orderBy('credit_note_date')
            ->get(['id', 'credit_note_no', 'credit_note_date', 'reason_type', 'total_ttc', 'status']);
    }

    /**
     * §4.3 - receipts this invoice's lines were matched against.
     *
     * @return Collection<int, object>
     */
    private function receipts(): Collection
    {
        return DB::table('supplier_invoice_lines as l')
            ->join('goods_receipt_lines as grl', 'grl.id', '=', 'l.goods_receipt_line_id')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->where('l.supplier_invoice_id', $this->invoiceId)
            ->distinct()
            ->orderBy('gr.received_on')
            ->get(['gr.id', 'gr.receipt_no', 'gr.received_on', 'gr.status', 'gr.has_discrepancy']);
    }

    /**
     * §3.3 - retenue de garantie held on this invoice (account 4817).
     */
    private function retention(): ?object
    {
        /** @var object|null $row */
        $row = DB::table('supplier_retentions as sr')
            ->leftJoin('chart_of_accounts as a', 'a.id', '=', 'sr.retention_account_id')
            ->where('sr.supplier_invoice_id', $this->invoiceId)
            ->first(['sr.amount', 'sr.status', 'sr.release_due_on', 'sr.released_at', 'a.code as account_code']);

        return $row;
    }

    /**
     * §6.6 - withholding attestations issued from this invoice.
     *
     * @return Collection<int, object>
     */
    private function attestations(): Collection
    {
        return DB::table('withholding_attestations')
            ->where('supplier_invoice_id', $this->invoiceId)
            ->orderBy('id')
            ->get(['id', 'attestation_no', 'period_month', 'period_year', 'base_amount', 'rate_bp_applied', 'withheld_amount', 'status', 'issued_at']);
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
        $lines = $this->lines();
        $payments = $this->payments();
        $entries = $this->journalEntries($invoice);

        $paidTotal = (int) $payments->sum('amount');

        return view('livewire.procurement.supplier-invoices.show', [
            'invoice' => $invoice,
            'lines' => $lines,
            'payments' => $payments,
            'paidTotal' => $paidTotal,
            'outstanding' => (int) $invoice->net_payable - $paidTotal,
            'deductibleTotal' => (int) $lines->sum('deductible_tax_amount'),
            'nonDeductibleTotal' => (int) $lines->sum('non_deductible_tax_amount'),
            'entries' => $entries,
            'journalLines' => $this->journalLines($entries)->groupBy('journal_entry_id'),
            'creditNotes' => $this->creditNotes(),
            'receipts' => $this->receipts(),
            'retention' => $this->retention(),
            'attestations' => $this->attestations(),
            'createdByName' => $this->userName($invoice->created_by),
            'approvedByName' => $this->userName($invoice->approved_by),
            'matchOverrideByName' => $this->userName($invoice->match_override_by === null ? null : (int) $invoice->match_override_by),
            'withholdingWaivedByName' => $this->userName($invoice->withholding_waived_by === null ? null : (int) $invoice->withholding_waived_by),
            'cancelledByName' => $this->userName($invoice->cancelled_by === null ? null : (int) $invoice->cancelled_by),
        ]);
    }
}
