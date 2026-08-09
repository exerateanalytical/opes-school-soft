@php
    /** Supplier invoice list (03-tax-procurement §10): search + status filter,
        blocking states (match exception, unresolved withholding) as KPIs. */
@endphp

<x-list-screen
    :title="__('opes.supplier_invoice_screen.title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.supplier_invoice_screen.title')]"
    :paginator="$invoices"
    :empty-message="__('opes.supplier_invoice_screen.empty')"
>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_pending_approval')" :value="$kpis['pending_approval']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_match_exceptions')" :value="$kpis['match_exceptions']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_withholding_unresolved')" :value="$kpis['withholding_unresolved']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.2 8.2a4 4 0 016.9 2.8c0 2-3 3-3 3m.1 3h.01M12 21a9 9 0 110-18 9 9 0 010 18z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_invoice_screen.kpi_posted')" :value="$kpis['posted']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="invoices-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.search') }}</span>
            <input id="invoices-search" type="search" wire:model.live.debounce.300ms="search"
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="invoices-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_invoice_screen.filter_status') }}</span>
            <select id="invoices-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach (['draft', 'pending_approval', 'match_exception', 'approved', 'posted', 'partially_paid', 'paid', 'cancelled'] as $option)
                    <option value="{{ $option }}">{{ str_replace('_', ' ', $option) }}</option>
                @endforeach
            </select>
        </label>

        <a href="{{ url('/procurement/invoices/capture') }}"
           class="self-end rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.supplier_invoice_screen.new_invoice') }}
        </a>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_internal_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_supplier_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_invoice_date') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_due_date') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_invoice_screen.col_total_ttc') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_invoice_screen.col_net_payable') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_match') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_invoice_screen.col_status') }}</th>
    </x-slot:head>

    @foreach ($invoices as $invoice)
        <tr wire:key="invoice-{{ $invoice->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">
                <a href="{{ url('/procurement/invoices/capture?invoice='.$invoice->id) }}" class="text-primary hover:underline">{{ $invoice->internal_no }}</a>
            </td>
            <td class="px-3 py-2 font-mono text-sm">{{ $invoice->supplier_invoice_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->supplier_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->invoice_date }}</td>
            <td class="px-3 py-2 text-sm">{{ $invoice->due_date }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $invoice->total_ttc, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $invoice->net_payable, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-sm">
                @if ($invoice->match_status === 'exception')
                    <x-status-pill status="red" :label="__('opes.supplier_invoice_screen.match_exception')"/>
                @elseif ($invoice->match_status === 'matched')
                    <x-status-pill status="ok" :label="__('opes.supplier_invoice_screen.match_matched')"/>
                @elseif ($invoice->match_status === 'overridden')
                    <x-status-pill status="amber" :label="__('opes.supplier_invoice_screen.match_overridden_pill')"/>
                @else
                    <span class="text-charcoal/50">—</span>
                @endif
                @if ($invoice->withholding_unresolved)
                    <x-status-pill status="amber" :label="__('opes.supplier_invoice_screen.withholding_unresolved')"/>
                @endif
            </td>
            <td class="px-3 py-2 text-sm">{{ str_replace('_', ' ', (string) $invoice->status) }}</td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($invoices as $invoice)
            <article wire:key="invoice-card-{{ $invoice->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <a href="{{ url('/procurement/invoices/capture?invoice='.$invoice->id) }}" class="font-mono text-sm text-primary hover:underline">{{ $invoice->internal_no }}</a>
                    <span class="text-xs text-charcoal/60">{{ str_replace('_', ' ', (string) $invoice->status) }}</span>
                </div>
                <p class="mt-1 text-sm">{{ $invoice->supplier_name }} · {{ $invoice->supplier_invoice_no }}</p>
                <p class="mt-1 font-mono text-sm">{{ number_format((int) $invoice->total_ttc, 0, ',', ' ') }} FCFA</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
