@php
    use App\Support\Money\Money;

    /**
     * Invoice status -> pill tone. The WORD carries the meaning (09-ui 10);
     * paid-ness is not a status column (04-fees §3.1) so the pill shows the
     * lifecycle status and the Balance column shows the money truth.
     */
    $statusTone = [
        'draft' => 'amber',
        'issued' => 'ok',
        'cancelled' => 'red',
    ];

    $tabs = [
        ['value' => '', 'label' => __('opes.fees_screen.tab_all'), 'count' => $kpis['total']],
        ['value' => 'unpaid', 'label' => __('opes.fees_screen.tab_unpaid'), 'count' => $kpis['unpaid']],
        ['value' => 'paid', 'label' => __('opes.fees_screen.tab_paid'), 'count' => $kpis['total'] - $kpis['unpaid']],
    ];
@endphp

<x-list-screen
    :title="__('opes.fees_screen.invoices_title')"
    :breadcrumb="[__('opes.fees_screen.breadcrumb_dashboard'), __('opes.fees_screen.breadcrumb_finance'), __('opes.fees_screen.breadcrumb_invoices')]"
    :paginator="$invoices"
    :empty-message="__('opes.fees_screen.invoices_empty')"
>
    <x-slot:actions>
        <a href="{{ route('fees.cashier') }}"
           class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.fees_screen.collect_for_student') }}
        </a>
    </x-slot:actions>

    {{-- Four KPIs, all dataset-wide numbers from the component's grouped
         queries under the SAME filters minus paidness - nothing invented. --}}
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.fees_screen.kpi_invoices')" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a1 1 0 011 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 011-1z"/><path stroke-linecap="round" d="M9 8h6M9 12h6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_unpaid')" :value="$kpis['unpaid']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_invoiced_total')" :value="Money::of($kpis['invoiced'])->format(false)" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_outstanding_total')" :value="Money::of($kpis['outstanding'])->format(false)" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 8l7-5 7 5M7 21h10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="invoices-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.status_label') }}</span>
            <select id="invoices-filter-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ __('opes.fees_screen.status_'.$statusOption) }}</option>
                @endforeach
            </select>
        </label>

        <label for="invoices-filter-class" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.class_label') }}</span>
            <select id="invoices-filter-class" wire:model.live="classGroup"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.fees_screen.all_classes') }}</option>
                @foreach ($classOptions as $classOption)
                    <option value="{{ $classOption['id'] }}">{{ $classOption['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="invoices-filter-term" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.term_label') }}</span>
            <select id="invoices-filter-term" wire:model.live="term"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.fees_screen.all_terms') }}</option>
                @foreach ($termOptions as $termOption)
                    <option value="{{ $termOption['id'] }}">{{ $termOption['name'] }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tab)
            <button type="button" wire:click="selectPaidness('{{ $tab['value'] }}')"
                    @if ($paidness === $tab['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $paidness === $tab['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tab['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_invoice_no') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_student') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_term') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_total') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_balance') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">{{ __('opes.fees_screen.print_invoice') }}</span></th>
        </tr>
    </x-slot:head>

    @foreach ($invoices as $row)
        <tr wire:key="invoice-row-{{ $row['id'] }}">
            <td class="px-4 py-2.5 font-mono text-xs text-charcoal/80">{{ $row['invoice_no'] }}</td>
            <td class="px-4 py-2.5">
                <div class="min-w-0">
                    <a href="{{ route('fees.students.statement', ['student' => $row['student_id']]) }}"
                       class="truncate font-medium text-charcoal hover:text-primary">{{ $row['student_name'] }}</a>
                    <div class="truncate font-mono text-xs text-charcoal/60">{{ $row['matricule'] }}</div>
                </div>
            </td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $row['term'] }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $row['date'] }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($row['total'])->format(false) }}</td>
            <td class="px-4 py-2.5 text-right font-mono {{ $row['outstanding'] > 0 ? 'text-heritage-red' : 'text-charcoal/60' }}">{{ Money::of($row['outstanding'])->format(false) }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$statusTone[$row['status']] ?? 'amber'"
                               :label="__('opes.fees_screen.status_'.$row['status'])"/>
            </td>
            <td class="px-4 py-2.5 text-right">
                {{-- Phase 13 D3 (10-documents §10.2): only an ISSUED invoice
                     has an invoice_no to print - PrintInvoice refuses a draft. --}}
                @if ($row['status'] === 'issued')
                    <a href="{{ route('fees.invoices.print', ['invoice' => $row['id']]) }}" target="_blank" rel="noopener"
                       title="{{ __('opes.fees_screen.print_invoice') }}"
                       class="inline-flex items-center rounded border border-sand p-1.5 text-charcoal/60 hover:border-primary/50 hover:text-primary">
                        <span class="sr-only">{{ __('opes.fees_screen.print_invoice') }}</span>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v7H6v-7z"/></svg>
                    </a>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($invoices as $row)
            <article wire:key="invoice-card-{{ $row['id'] }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('fees.students.statement', ['student' => $row['student_id']]) }}"
                           class="font-medium text-charcoal hover:text-primary">{{ $row['student_name'] }}</a>
                        <div class="font-mono text-xs text-charcoal/60">{{ $row['invoice_no'] }}</div>
                    </div>
                    <x-status-pill :status="$statusTone[$row['status']] ?? 'amber'"
                                   :label="__('opes.fees_screen.status_'.$row['status'])"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.column_total') }}</dt>
                        <dd class="font-mono">{{ Money::of($row['total'])->format(false) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.column_balance') }}</dt>
                        <dd class="font-mono {{ $row['outstanding'] > 0 ? 'text-heritage-red' : '' }}">{{ Money::of($row['outstanding'])->format(false) }}</dd>
                    </div>
                </dl>
                @if ($row['status'] === 'issued')
                    <a href="{{ route('fees.invoices.print', ['invoice' => $row['id']]) }}" target="_blank" rel="noopener"
                       class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.print_invoice') }}
                    </a>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
