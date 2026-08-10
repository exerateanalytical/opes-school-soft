@php
    use App\Support\Money\Money;

    $invTone = [
        'draft' => 'amber', 'pending_match' => 'amber', 'match_exception' => 'red',
        'pending_approval' => 'amber', 'approved' => 'ok', 'posted' => 'ok',
        'partially_paid' => 'ok', 'paid' => 'ok', 'cancelled' => 'red', 'disputed' => 'red',
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60 print:hidden">
        <a href="{{ url('/procurement/invoices') }}" class="text-primary hover:underline">&larr; Back to supplier invoices</a>
    </nav>

    <header class="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ $invoice->internal_no }}</h1>
            <p class="text-sm text-charcoal/70">{{ $invoice->supplier_name }} ({{ $invoice->supplier_code }}) &middot; supplier ref {{ $invoice->supplier_invoice_no }}</p>
        </div>
        <div class="flex items-center gap-2">
            <x-status-pill :status="$invTone[$invoice->status] ?? 'amber'" :label="str_replace('_', ' ', $invoice->status)"/>
            <button type="button" onclick="window.print()" class="rounded border border-sand px-3 py-1.5 text-sm hover:bg-sand/40">Print / Preview</button>
            <button type="button" wire:click="exportPdf" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">Export PDF</button>
        </div>
    </header>

    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 rounded border border-sand bg-white p-4 text-sm sm:grid-cols-2 print:hidden">
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Invoice date</dt><dd class="font-medium">{{ $invoice->invoice_date }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Due date</dt><dd class="font-medium">{{ $invoice->due_date }}</dd></div>
        <div class="flex justify-between gap-4">
            <dt class="text-charcoal/70">Matched purchase order</dt>
            <dd class="font-medium">
                @if ($invoice->purchase_order_id)
                    <a href="{{ url('/procurement/orders/'.$invoice->purchase_order_id) }}" class="text-primary hover:underline font-mono">{{ $invoice->po_no }}</a>
                @else
                    —
                @endif
            </dd>
        </div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Match status</dt><dd class="font-medium">{{ str_replace('_', ' ', $invoice->match_status) }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Created by</dt><dd class="font-medium">{{ $createdByName ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Approved by</dt><dd class="font-medium">{{ $approvedByName ?? '—' }} @if($invoice->approved_at) ({{ $invoice->approved_at }}) @endif</dd></div>
        @if ($invoice->withholding_unresolved)
            <div class="sm:col-span-2 rounded border border-red-300 bg-red-50 px-3 py-2 text-red-700">Withholding is unresolved on this invoice.</div>
        @endif
    </dl>

    @if ($payments->isNotEmpty())
        <div class="rounded border border-sand bg-white p-4 text-sm print:hidden">
            <p class="mb-2 text-xs font-semibold uppercase text-charcoal/60">Payments applied</p>
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-charcoal/60">
                        <th class="py-1 pr-2">Payment</th>
                        <th class="py-1 pr-2">Date</th>
                        <th class="py-1 pr-2">Status</th>
                        <th class="py-1 text-right">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $payment)
                        <tr class="border-t border-sand/60">
                            <td class="py-1 pr-2"><a href="{{ url('/procurement/payments/'.$payment->id) }}" class="font-mono text-primary hover:underline">{{ $payment->payment_no }}</a></td>
                            <td class="py-1 pr-2">{{ $payment->payment_date }}</td>
                            <td class="py-1 pr-2">{{ str_replace('_', ' ', $payment->payment_status) }}</td>
                            <td class="py-1 text-right font-mono">{{ Money::of((int) $payment->amount)->format(false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Print-preview document --}}
    <div id="print-area" class="rounded border border-sand bg-white p-8 text-sm shadow-sm print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-sand pb-4">
            <div>
                <h2 class="text-lg font-bold text-charcoal">SUPPLIER INVOICE</h2>
                <p class="font-mono text-charcoal/70">{{ $invoice->internal_no }}</p>
            </div>
            <div class="text-right">
                <p class="font-medium">{{ $invoice->invoice_date }}</p>
                <p class="text-charcoal/70">Due {{ $invoice->due_date }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-8">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Supplier</p>
                <p class="font-medium">{{ $invoice->supplier_name }}</p>
                <p class="text-charcoal/70">{{ $invoice->supplier_code }}</p>
                <p class="text-charcoal/70">Ref {{ $invoice->supplier_invoice_no }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Purchase order</p>
                <p>{{ $invoice->po_no ?? '—' }}</p>
            </div>
        </div>

        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-sand text-left text-xs text-charcoal/60">
                    <th class="py-2 pr-2">#</th>
                    <th class="py-2 pr-2">Description</th>
                    <th class="py-2 pr-2 text-right">Qty</th>
                    <th class="py-2 pr-2 text-right">Unit price</th>
                    <th class="py-2 pr-2 text-right">Tax</th>
                    <th class="py-2 pr-2 text-right">Withholding</th>
                    <th class="py-2 text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr class="border-b border-sand/60">
                        <td class="py-2 pr-2">{{ $line->line_no }}</td>
                        <td class="py-2 pr-2">{{ $line->description }}</td>
                        <td class="py-2 pr-2 text-right font-mono">{{ $line->quantity }}{{ $line->unit_of_measure ? ' '.$line->unit_of_measure : '' }}</td>
                        <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->unit_price_ht)->format(false) }}</td>
                        <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->tax_amount)->format(false) }} <span class="text-xs text-charcoal/60">({{ number_format($line->tax_rate_bp_applied / 100, 2) }}%)</span></td>
                        <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->withholding_amount)->format(false) }}</td>
                        <td class="py-2 text-right font-mono">{{ Money::of((int) $line->amount_ht + (int) $line->tax_amount)->format(false) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <dl class="w-72 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-charcoal/70">Subtotal</dt><dd class="font-mono">{{ Money::of((int) $invoice->subtotal_ht)->format(false) }}</dd></div>
                @if ($invoice->discount_total > 0)
                    <div class="flex justify-between"><dt class="text-charcoal/70">Discount</dt><dd class="font-mono">-{{ Money::of((int) $invoice->discount_total)->format(false) }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-charcoal/70">Tax</dt><dd class="font-mono">{{ Money::of((int) $invoice->tax_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-sand pt-1 font-semibold"><dt>Total (TTC)</dt><dd class="font-mono">{{ Money::of((int) $invoice->total_ttc)->format(false) }}</dd></div>
                <div class="flex justify-between text-charcoal/70"><dt>Withholding</dt><dd class="font-mono">-{{ Money::of((int) $invoice->withholding_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-sand pt-1 font-semibold"><dt>Net payable</dt><dd class="font-mono">{{ Money::of((int) $invoice->net_payable)->format(false) }}</dd></div>
                @if ($invoice->retention_amount > 0)
                    <div class="flex justify-between text-charcoal/70"><dt>Retention held</dt><dd class="font-mono">{{ Money::of((int) $invoice->retention_amount)->format(false) }}</dd></div>
                @endif
            </dl>
        </div>
    </div>
</div>
