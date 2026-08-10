@php
    use App\Support\Money\Money;

    $payTone = ['draft' => 'amber', 'approved' => 'ok', 'paid' => 'ok', 'voided' => 'red'];
@endphp

<div class="mx-auto max-w-5xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60 print:hidden">
        <a href="{{ url('/procurement/payments') }}" class="text-primary hover:underline">&larr; Back to supplier payments</a>
    </nav>

    <header class="flex flex-wrap items-center justify-between gap-2 print:hidden">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ $payment->payment_no }}</h1>
            <p class="text-sm text-charcoal/70">{{ $payment->supplier_name }} ({{ $payment->supplier_code }})</p>
        </div>
        <div class="flex items-center gap-2">
            <x-status-pill :status="$payTone[$payment->status] ?? 'amber'" :label="$payment->status"/>
            <button type="button" onclick="window.print()" class="rounded border border-sand px-3 py-1.5 text-sm hover:bg-sand/40">Print / Preview</button>
            <a href="{{ url('/procurement/payments/'.$payment->id.'/voucher') }}" target="_blank" rel="noopener" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:opacity-90">Export PDF (Voucher)</a>
        </div>
    </header>

    <dl class="grid grid-cols-1 gap-x-8 gap-y-2 rounded border border-sand bg-white p-4 text-sm sm:grid-cols-2 print:hidden">
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Payment date</dt><dd class="font-medium">{{ $payment->payment_date }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Method</dt><dd class="font-medium">{{ str_replace('_', ' ', $payment->payment_method) }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Treasury account</dt><dd class="font-medium">{{ $payment->treasury_account_name }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Reference</dt><dd class="font-medium">{{ $payment->reference ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Recorded by</dt><dd class="font-medium">{{ $recordedByName ?? '—' }}</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Approved by</dt><dd class="font-medium">{{ $approvedByName ?? '—' }} @if($payment->approved_at) ({{ $payment->approved_at }}) @endif</dd></div>
        <div class="flex justify-between gap-4"><dt class="text-charcoal/70">Paid by</dt><dd class="font-medium">{{ $paidByName ?? '—' }} @if($payment->paid_at) ({{ $payment->paid_at }}) @endif</dd></div>
        @if ($payment->notes)
            <div class="flex justify-between gap-4 sm:col-span-2"><dt class="text-charcoal/70">Notes</dt><dd class="font-medium">{{ $payment->notes }}</dd></div>
        @endif
    </dl>

    {{-- Print-preview document, mirrors the PrintPaymentVoucher layout --}}
    <div id="print-area" class="rounded border border-sand bg-white p-8 text-sm shadow-sm print:border-0 print:shadow-none">
        <div class="mb-6 flex items-start justify-between border-b border-sand pb-4">
            <div>
                <h2 class="text-lg font-bold text-charcoal">PAYMENT VOUCHER</h2>
                <p class="font-mono text-charcoal/70">{{ $payment->payment_no }}</p>
            </div>
            <div class="text-right">
                <p class="font-medium">{{ $payment->payment_date }}</p>
                <p class="text-charcoal/70">{{ str_replace('_', ' ', $payment->payment_method) }}</p>
            </div>
        </div>

        <div class="mb-6">
            <p class="mb-1 text-xs font-semibold uppercase text-charcoal/60">Paid to</p>
            <p class="font-medium">{{ $payment->supplier_name }}</p>
            <p class="text-charcoal/70">{{ $payment->supplier_code }}</p>
        </div>

        <table class="min-w-full text-sm">
            <thead>
                <tr class="border-b border-sand text-left text-xs text-charcoal/60">
                    <th class="py-2 pr-2">Invoice</th>
                    <th class="py-2 pr-2">Date</th>
                    <th class="py-2 pr-2 text-right">Amount</th>
                    <th class="py-2 text-right">Withheld</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($allocations as $allocation)
                    <tr class="border-b border-sand/60">
                        <td class="py-2 pr-2 font-mono">{{ $allocation->internal_no }}</td>
                        <td class="py-2 pr-2">{{ $allocation->invoice_date }}</td>
                        <td class="py-2 pr-2 text-right font-mono">{{ Money::of((int) $allocation->amount)->format(false) }}</td>
                        <td class="py-2 text-right font-mono">{{ Money::of((int) $allocation->withholding_amount)->format(false) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-4 text-center text-charcoal/60">No invoices allocated.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="mt-4 flex justify-end">
            <dl class="w-64 space-y-1 text-sm">
                <div class="flex justify-between"><dt class="text-charcoal/70">Gross amount</dt><dd class="font-mono">{{ Money::of((int) $payment->gross_amount)->format(false) }}</dd></div>
                <div class="flex justify-between"><dt class="text-charcoal/70">Withholding</dt><dd class="font-mono">-{{ Money::of((int) $payment->withholding_amount)->format(false) }}</dd></div>
                @if ($payment->fee_amount > 0)
                    <div class="flex justify-between"><dt class="text-charcoal/70">Fee ({{ $payment->fee_bearer }})</dt><dd class="font-mono">{{ Money::of((int) $payment->fee_amount)->format(false) }}</dd></div>
                @endif
                <div class="flex justify-between border-t border-sand pt-1 font-semibold"><dt>Net paid</dt><dd class="font-mono">{{ Money::of((int) $payment->net_amount)->format(false) }}</dd></div>
            </dl>
        </div>
    </div>
</div>
