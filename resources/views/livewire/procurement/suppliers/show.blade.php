@php
    use App\Support\Money\Money;

    $poTone = [
        'draft' => 'amber', 'pending_approval' => 'amber', 'approved' => 'ok', 'sent' => 'ok',
        'partially_received' => 'ok', 'received' => 'ok', 'partially_invoiced' => 'ok',
        'invoiced' => 'ok', 'closed' => 'amber', 'cancelled' => 'red',
    ];
@endphp

<div class="mx-auto max-w-5xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60">
        <a href="{{ url('/procurement/suppliers') }}" class="text-primary hover:underline">{{ __('opes.procurement_screen.back_to_suppliers') }}</a>
    </nav>

    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ $supplier->name }}</h1>
            <p class="font-mono text-sm text-charcoal/70">{{ $supplier->code }} · {{ $supplier->niu ?? '—' }}</p>
        </div>
        @if ($supplier->is_archived)
            <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
        @else
            <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
        @endif
    </header>

    <div role="tablist" class="flex gap-1 border-b border-sand">
        @foreach (['details', 'orders', 'receipts'] as $tabKey)
            <button type="button" role="tab" wire:click="selectTab('{{ $tabKey }}')"
                    aria-selected="{{ $tab === $tabKey ? 'true' : 'false' }}"
                    class="rounded-t px-3 py-1.5 text-sm {{ $tab === $tabKey ? 'border border-b-0 border-sand bg-white font-semibold text-primary' : 'text-charcoal/70 hover:text-charcoal' }}">
                {{ __('opes.procurement_screen.tab_'.($tabKey === 'details' ? 'details' : ($tabKey === 'orders' ? 'orders' : 'receipts'))) }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'details')
        <dl class="grid grid-cols-1 gap-x-8 gap-y-2 rounded border border-sand bg-white p-4 text-sm sm:grid-cols-2">
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_type') }}</dt><dd class="font-medium">{{ $supplier->supplier_type->value }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_regime') }}</dt><dd class="font-medium">{{ $supplier->regime_fiscal?->value ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.col_category') }}</dt><dd class="font-medium">{{ $categoryName ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_payable_account') }}</dt><dd class="font-mono font-medium">{{ $payableAccount }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_payment_terms') }}</dt><dd class="font-medium">{{ $supplier->payment_terms_days }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.col_phone') }}</dt><dd class="font-medium">{{ $supplier->phone ?? '—' }}</dd></div>
            {{-- 00-core 9.5: encrypted identifiers are shown MASKED. --}}
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_bank') }}</dt><dd class="font-mono font-medium">{{ $maskedRib ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4"><dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_momo') }}</dt><dd class="font-mono font-medium">{{ $maskedMomo ?? '—' }}</dd></div>
            <div class="flex justify-between gap-4">
                <dt class="text-charcoal/70">{{ __('opes.procurement_screen.detail_withholding_exempt') }}</dt>
                <dd class="font-medium">
                    {{ $supplier->is_withholding_exempt
                        ? ($supplier->withholding_exemption_ref.($supplier->withholding_exemption_expires_on !== null ? ' → '.$supplier->withholding_exemption_expires_on : ''))
                        : __('opes.procurement_screen.no') }}
                </dd>
            </div>
        </dl>
    @elseif ($tab === 'orders')
        <div class="overflow-x-auto rounded border border-sand bg-white">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-charcoal/60">
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_po_no') }}</th>
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_order_date') }}</th>
                        <th class="px-3 py-2 text-right">{{ __('opes.procurement_screen.col_total_ttc') }}</th>
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($orders as $order)
                        <tr class="border-t border-sand/60">
                            <td class="px-3 py-2 font-mono">{{ $order->po_no }}</td>
                            <td class="px-3 py-2">{{ $order->order_date }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ Money::of((int) $order->total_ttc)->format(false) }}</td>
                            <td class="px-3 py-2"><x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-charcoal/60">{{ __('opes.procurement_screen.orders_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $orders->links() }}
    @else
        <div class="overflow-x-auto rounded border border-sand bg-white">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="text-left text-xs text-charcoal/60">
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_received_on') }}</th>
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_po_no') }}</th>
                        <th class="px-3 py-2">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($receipts as $receipt)
                        <tr class="border-t border-sand/60">
                            <td class="px-3 py-2 font-mono">{{ $receipt->receipt_no }}</td>
                            <td class="px-3 py-2">{{ $receipt->received_on }}</td>
                            <td class="px-3 py-2 font-mono">{{ $receipt->po_no ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'"
                                               :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-6 text-center text-charcoal/60">{{ __('opes.procurement_screen.receipts_empty') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        {{ $receipts->links() }}
    @endif
</div>
