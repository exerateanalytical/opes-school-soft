@php
    $grTone = ['draft' => 'amber', 'confirmed' => 'ok', 'cancelled' => 'red'];
@endphp

<x-list-screen
    :title="__('opes.procurement_screen.receipts_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.receipts_title')]"
    :paginator="$receipts"
    :empty-message="__('opes.procurement_screen.receipts_empty')"
>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_draft_receipts')" :value="$kpis['draft']" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 3l5 5-11 11H5v-5L16 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_discrepancies')" :value="$kpis['discrepancies']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="receipts-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_status') }}</span>
            <select id="receipts-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ $statusOption }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_po_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_received_on') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_delivery_note') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
    </x-slot:head>

    @foreach ($receipts as $receipt)
        <tr wire:key="receipt-{{ $receipt->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">{{ $receipt->receipt_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->supplier_name }}</td>
            <td class="px-3 py-2 font-mono text-sm">{{ $receipt->po_no ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->received_on }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->delivery_note_ref ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'"
                               :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/>
            </td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$grTone[$receipt->status] ?? 'amber'" :label="(string) $receipt->status"/>
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($receipts as $receipt)
            <article wire:key="receipt-card-{{ $receipt->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $receipt->receipt_no }}</span>
                    <x-status-pill :status="$grTone[$receipt->status] ?? 'amber'" :label="(string) $receipt->status"/>
                </div>
                <p class="mt-1 text-sm font-medium">{{ $receipt->supplier_name }}</p>
                <p class="text-xs text-charcoal/70">{{ $receipt->received_on }} · {{ $receipt->po_no ?? '—' }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
