@php
    use App\Support\Money\Money;

    $poTone = [
        'draft' => 'amber', 'pending_approval' => 'amber', 'approved' => 'ok', 'sent' => 'ok',
        'partially_received' => 'ok', 'received' => 'ok', 'partially_invoiced' => 'ok',
        'invoiced' => 'ok', 'closed' => 'amber', 'cancelled' => 'red',
    ];
@endphp

<x-list-screen
    :title="__('opes.procurement_screen.orders_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.orders_title')]"
    :paginator="$orders"
    :empty-message="__('opes.procurement_screen.orders_empty')"
>
    <x-slot:actions>
        <a href="{{ url('/procurement/orders/new') }}"
           class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.procurement_screen.new_order') }}
        </a>
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_open_commitments')" :value="Money::of($openCommitments)->format(false)" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 8l7-5 7 5M7 21h10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_drafts')" :value="$draftCount" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 3l5 5-11 11H5v-5L16 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="orders-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_status') }}</span>
            <select id="orders-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ str_replace('_', ' ', $statusOption) }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_po_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_order_date') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.procurement_screen.col_total_ttc') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_expected') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
    </x-slot:head>

    @foreach ($orders as $order)
        <tr wire:key="order-{{ $order->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">{{ $order->po_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $order->supplier_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $order->order_date }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ Money::of((int) $order->total_ttc)->format(false) }}</td>
            <td class="px-3 py-2 text-sm">{{ $order->expected_delivery_date ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/>
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($orders as $order)
            <article wire:key="order-card-{{ $order->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $order->po_no }}</span>
                    <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/>
                </div>
                <p class="mt-1 text-sm font-medium">{{ $order->supplier_name }}</p>
                <p class="text-xs text-charcoal/70">{{ $order->order_date }} · {{ Money::of((int) $order->total_ttc)->format(false) }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
