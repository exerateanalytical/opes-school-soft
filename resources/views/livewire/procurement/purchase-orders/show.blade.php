@php
    use App\Support\Money\Money;

    $poTone = [
        'draft' => 'amber', 'pending_approval' => 'amber', 'approved' => 'ok', 'sent' => 'ok',
        'partially_received' => 'ok', 'received' => 'ok', 'partially_invoiced' => 'ok',
        'invoiced' => 'ok', 'closed' => 'amber', 'cancelled' => 'red',
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60 print:hidden">
        <a href="{{ url('/procurement/orders') }}" class="text-primary hover:underline">&larr; Back to purchase orders</a>
    </nav>

    <header class="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ $order->po_no }}</h1>
            <p class="text-sm text-charcoal/70">{{ $order->supplier_name }} ({{ $order->supplier_code }})</p>
        </div>
        <div class="flex items-center gap-2">
            <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', $order->status)"/>
            <button type="button" onclick="window.print()" class="rounded border border-sand px-3 py-1.5 text-sm hover:bg-sand/40">Print / Preview</button>
            <button type="button" wire:click="exportPdf" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">Export PDF</button>
        </div>
    </header>

    {{-- Header summary --}}
    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 rounded border border-sand bg-white p-4 text-sm sm:grid-cols-2 print:hidden">
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Order date</dt><dd class="font-medium">{{ $order->order_date }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Expected delivery</dt><dd class="font-medium">{{ $order->expected_delivery_date ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Delivery address</dt><dd class="font-medium">{{ $order->delivery_address ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Currency</dt><dd class="font-medium">{{ $order->currency }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Created by</dt><dd class="font-medium">{{ $createdByName ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Approved by</dt><dd class="font-medium">{{ $approvedByName ?? '—' }} @if($order->approved_at) ({{ $order->approved_at }}) @endif</dd></div>
        @if ($order->closed_reason)
            <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-charcoal/70">Closed reason</dt><dd class="font-medium">{{ $order->closed_reason }}</dd></div>
        @endif
    </dl>

    {{-- Print-preview document --}}
    <div id="print-area" class="rounded border border-sand bg-white p-8 text-sm shadow-sm print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-sand pb-4">
            <div>
                <h2 class="text-lg font-bold text-charcoal">PURCHASE ORDER</h2>
                <p class="font-mono text-charcoal/70">{{ $order->po_no }}</p>
            </div>
            <div class="text-right">
                <p class="font-medium">{{ $order->order_date }}</p>
                <p class="text-charcoal/70">Status: {{ str_replace('_', ' ', $order->status) }}</p>
            </div>
        </div>

        <div class="mb-6 grid grid-cols-2 gap-8">
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Supplier</p>
                <p class="font-medium">{{ $order->supplier_name }}</p>
                <p class="text-charcoal/70">{{ $order->supplier_code }}</p>
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Delivery</p>
                <p>{{ $order->delivery_address ?? '—' }}</p>
                <p class="text-charcoal/70">Expected: {{ $order->expected_delivery_date ?? '—' }}</p>
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
                        <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $line->tax_amount)->format(false) }}</td>
                        <td class="py-2 text-right font-mono">{{ Money::of((int) $line->amount_ttc)->format(false) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <dl class="w-64 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-charcoal/70">Subtotal</dt><dd class="font-mono">{{ Money::of((int) $order->subtotal_ht)->format(false) }}</dd></div>
                <div class="flex justify-between"><dt class="text-charcoal/70">Tax</dt><dd class="font-mono">{{ Money::of((int) $order->tax_total)->format(false) }}</dd></div>
                <div class="flex justify-between border-t border-sand pt-1 font-semibold"><dt>Total</dt><dd class="font-mono">{{ Money::of((int) $order->total_ttc)->format(false) }}</dd></div>
                @if ($order->retention_rate_bp > 0)
                    <div class="flex justify-between text-charcoal/70"><dt>Retention</dt><dd class="font-mono">{{ number_format($order->retention_rate_bp / 100, 2) }}%</dd></div>
                @endif
            </dl>
        </div>
    </div>
</div>
