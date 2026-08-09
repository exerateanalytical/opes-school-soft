@php
    /** Supplier list (03-tax-procurement §10): search + state filter. */
@endphp

<x-list-screen
    :title="__('opes.procurement_screen.suppliers_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.suppliers_title')]"
    :paginator="$suppliers"
    :empty-message="__('opes.procurement_screen.suppliers_empty')"
>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_suppliers')" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V8l8-5 8 5v13M9 21v-6h6v6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_active')" :value="$kpis['active']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_archived')" :value="$kpis['archived']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path stroke-linecap="round" d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8M10 12h4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="suppliers-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.suppliers_search') }}</span>
            <input id="suppliers-search" type="search" wire:model.live.debounce.300ms="search"
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="suppliers-state" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_state') }}</span>
            <select id="suppliers-state" wire:model.live="state"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="active">{{ __('opes.procurement_screen.state_active') }}</option>
                <option value="archived">{{ __('opes.procurement_screen.state_archived') }}</option>
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_code') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_name') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_niu') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_category') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_phone') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
    </x-slot:head>

    @foreach ($suppliers as $supplier)
        <tr wire:key="supplier-{{ $supplier->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">
                <a href="{{ url('/procurement/suppliers/'.$supplier->id) }}" class="text-primary hover:underline">{{ $supplier->code }}</a>
            </td>
            <td class="px-3 py-2 text-sm">{{ $supplier->name }}</td>
            <td class="px-3 py-2 font-mono text-sm">{{ $supplier->niu ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $supplier->category_name ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $supplier->phone ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                @if ($supplier->is_archived)
                    <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
                @elseif ($supplier->is_active)
                    <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
                @else
                    <x-status-pill status="amber" :label="str_replace('_', ' ', (string) $supplier->niu_status)"/>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($suppliers as $supplier)
            <article wire:key="supplier-card-{{ $supplier->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <a href="{{ url('/procurement/suppliers/'.$supplier->id) }}" class="font-mono text-sm text-primary">{{ $supplier->code }}</a>
                    @if ($supplier->is_archived)
                        <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
                    @else
                        <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
                    @endif
                </div>
                <p class="mt-1 text-sm font-medium text-charcoal">{{ $supplier->name }}</p>
                <p class="text-xs text-charcoal/70">{{ $supplier->niu ?? '—' }} · {{ $supplier->phone ?? '—' }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
