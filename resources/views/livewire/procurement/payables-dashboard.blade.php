@php
    /** Payables dashboard (03-tax-procurement §10): every figure comes from
        the §4.9 report Actions - no second definition of anything. */
@endphp

<div class="mx-auto max-w-6xl space-y-4 p-4">
    <nav class="text-xs text-charcoal/60">{{ __('opes.nav.dashboard') }} / {{ __('opes.payables_dashboard.title') }}</nav>

    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.payables_dashboard.title') }}</h1>

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-kpi-card :label="__('opes.payables_dashboard.kpi_outstanding')" :value="number_format($agedTotals['total'], 0, ',', ' ')" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.payables_dashboard.kpi_due_week', ['count' => $dueThisWeekCount])" :value="number_format($dueThisWeekTotal, 0, ',', ' ')" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.payables_dashboard.kpi_commitments')" :value="number_format($commitments['total'], 0, ',', ' ')" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6M9 8h6M5 21h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.payables_dashboard.kpi_receipt_not_invoiced')" :value="number_format($receiptNotInvoiced['total'], 0, ',', ' ')" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 00-1-1.73l-6-3.46a2 2 0 00-2 0L5 5.27A2 2 0 004 7v6a2 2 0 001 1.73l6 3.46a2 2 0 002 0l6-3.46A2 2 0 0020 13z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </div>

    <section class="rounded border border-sand bg-white p-4">
        <div class="mb-2 flex items-baseline justify-between">
            <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.payables_dashboard.aged_title') }}</h2>
            <p class="text-xs text-charcoal/60">{{ __('opes.payables_dashboard.aged_axis', ['axis' => $aged['axis'], 'as_of' => $aged['as_of']]) }}</p>
        </div>

        @if ($aged['rows'] === [])
            <p class="text-sm text-charcoal/60">{{ __('opes.payables_dashboard.aged_empty') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full divide-y divide-sand text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-charcoal/60">
                            <th class="px-2 py-1 text-left">{{ __('opes.payables_dashboard.col_supplier') }}</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.payables_dashboard.col_current') }}</th>
                            <th class="px-2 py-1 text-right">1–30</th>
                            <th class="px-2 py-1 text-right">31–60</th>
                            <th class="px-2 py-1 text-right">61–90</th>
                            <th class="px-2 py-1 text-right">&gt;90</th>
                            <th class="px-2 py-1 text-right">{{ __('opes.payables_dashboard.col_total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @foreach ($aged['rows'] as $row)
                            <tr wire:key="aged-{{ $row->supplier_id }}">
                                <td class="px-2 py-1">{{ $row->supplier_name }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($row->current, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($row->days_1_30, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($row->days_31_60, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($row->days_61_90, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono">{{ number_format($row->days_90_plus, 0, ',', ' ') }}</td>
                                <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($row->total, 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach
                        <tr class="border-t-2 border-sand">
                            <td class="px-2 py-1 font-semibold">{{ __('opes.payables_dashboard.col_total') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['current'], 0, ',', ' ') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['days_1_30'], 0, ',', ' ') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['days_31_60'], 0, ',', ' ') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['days_61_90'], 0, ',', ' ') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['days_90_plus'], 0, ',', ' ') }}</td>
                            <td class="px-2 py-1 text-right font-mono font-semibold">{{ number_format($agedTotals['total'], 0, ',', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <div class="grid gap-4 lg:grid-cols-2">
        <section class="rounded border border-sand bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-charcoal">{{ __('opes.payables_dashboard.exceptions_title') }}</h2>
            <ul class="space-y-1 text-sm">
                <li class="flex justify-between">
                    <span>{{ __('opes.payables_dashboard.match_exceptions') }}</span>
                    <span class="font-mono">{{ $matchExceptions }}</span>
                </li>
                <li class="flex justify-between">
                    <span>{{ __('opes.payables_dashboard.duplicate_risk') }}</span>
                    <span class="font-mono">{{ count($duplicates) }}</span>
                </li>
            </ul>
            @foreach ($duplicates as $pair)
                <p class="mt-2 text-xs text-charcoal/70">
                    {{ $pair->supplier_name }}: <span class="font-mono">{{ $pair->first_internal_no }}</span> ↔
                    <span class="font-mono">{{ $pair->second_internal_no }}</span> ·
                    {{ number_format($pair->total_ttc, 0, ',', ' ') }} FCFA · {{ $pair->days_apart }}j
                </p>
            @endforeach
        </section>

        <section class="rounded border border-sand bg-white p-4">
            <h2 class="mb-2 text-sm font-semibold text-charcoal">{{ __('opes.payables_dashboard.commitments_title') }}</h2>
            @if ($commitments['rows'] === [])
                <p class="text-sm text-charcoal/60">{{ __('opes.payables_dashboard.commitments_empty') }}</p>
            @else
                <ul class="space-y-1 text-sm">
                    @foreach ($commitments['rows'] as $row)
                        <li class="flex justify-between">
                            <span><span class="font-mono">{{ $row->po_no }}</span> · {{ $row->supplier_name }}</span>
                            <span class="font-mono">{{ number_format($row->open_value, 0, ',', ' ') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</div>
