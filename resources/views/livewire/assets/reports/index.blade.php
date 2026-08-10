@php
    use App\Support\Money\Money;

    $tabs = $reportTabs;
    $tabLabel = $tabs[array_search($tab, array_column($tabs, 'value'), true)]['label'] ?? 'Report';
@endphp

<div class="min-w-0 space-y-4">
    <style>
        @media print {
            .print-hide { display: none !important; }
            .print-shell { box-shadow: none !important; border: none !important; }
        }
    </style>

    <div class="print-hide">
        <x-list-screen
            title="Assets & Inventory Reports"
            :breadcrumb="['Dashboard', 'Reports', 'Assets & Inventory Reports']"
            :paginator="$rows"
            empty-message="No data matches this report's filters yet."
        >
            <x-slot:actions>
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Print
                </button>
            </x-slot:actions>

            <x-slot:kpis>
                <x-kpi-card label="Total Assets" :value="$kpis['total_assets']" icon-bg="bg-primary">
                    <x-slot:icon>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
                <x-kpi-card label="Net Book Value" :value="Money::of($kpis['net_book_value'])->format(false)" icon-bg="bg-badge-blue">
                    <x-slot:icon>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V6m0 10v2"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
                <x-kpi-card label="Stock Value" :value="Money::of($kpis['stock_value'])->format(false)" icon-bg="bg-badge-orange">
                    <x-slot:icon>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7L12 3 4 7m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
                <x-kpi-card label="Movements This Month" :value="$kpis['movements_this_month']" icon-bg="bg-badge-purple">
                    <x-slot:icon>
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4"/></svg>
                    </x-slot:icon>
                </x-kpi-card>
            </x-slot:kpis>

            <x-slot:filters>
                @if (in_array($tab, ['register', 'depreciation', 'valuation'], true))
                    <label for="report-filter-category" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Category</span>
                        <select id="report-filter-category" wire:model.live="category"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All categories</option>
                            @foreach ($categoryOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($tab, ['valuation', 'movements'], true))
                    <label for="report-filter-location" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Location</span>
                        <select id="report-filter-location" wire:model.live="location"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All locations</option>
                            @foreach ($locationOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($tab, ['register', 'movements'], true))
                    <label for="report-filter-date-from" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">From</span>
                        <input type="date" id="report-filter-date-from" wire:model.live="dateFrom"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    </label>
                    <label for="report-filter-date-to" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">To</span>
                        <input type="date" id="report-filter-date-to" wire:model.live="dateTo"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    </label>
                @endif
            </x-slot:filters>

            <x-slot:tabs>
                @foreach ($tabs as $tabOption)
                    <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                            @if ($tab === $tabOption['value']) aria-current="page" @endif
                            class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ $tabOption['label'] }}
                    </button>
                @endforeach
            </x-slot:tabs>

            <x-slot:head>
                <tr class="bg-chrome text-white">
                    @if ($tab === 'depreciation')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Asset Tag</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Asset Name</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Fiscal Year</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Charge</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Accumulated</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Net Book Value</th>
                    @elseif ($tab === 'valuation')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Item Code</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Item Name</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Qty on Hand</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Value on Hand</th>
                    @elseif ($tab === 'movements')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Item</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Quantity</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reference</th>
                    @else
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Tag Number</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Acquisition Date</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Acquisition Cost</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="report-{{ $tab }}-{{ $row->id ?? $loop->index }}" class="hover:bg-sand/30">
                    @if ($tab === 'depreciation')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->tag_number }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->asset_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->fiscal_year_id }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->period_month }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->charge)->format(false) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->closing_accumulated)->format(false) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->net_book_value)->format(false) }}</td>
                    @elseif ($tab === 'valuation')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->item_code }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->item_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->location_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->quantity_on_hand }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->value_on_hand)->format(false) }}</td>
                    @elseif ($tab === 'movements')
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->moved_on }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->item_code }} — {{ $row->item_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->location_name }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->movement_type) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->quantity }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->document_ref ?? '—' }}</td>
                    @else
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->tag_number }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->acquisition_date }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->acquisition_cost)->format(false) }}</td>
                        <td class="px-4 py-2.5">
                            <x-status-pill :status="$row->status === 'in_service' ? 'ok' : 'amber'" :label="ucfirst(str_replace('_', ' ', $row->status))"/>
                        </td>
                    @endif
                </tr>
            @endforeach
        </x-list-screen>
    </div>

    {{-- Print-only clean table: the on-screen x-list-screen (KPIs, filters,
         nav chrome) is hidden by .print-hide above; this simple table is
         what actually prints. --}}
    <div class="hidden print:block">
        <h1 class="mb-4 text-xl font-semibold">Assets & Inventory Reports — {{ $tabLabel }}</h1>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-black/40 text-left">
                    @if ($tab === 'depreciation')
                        <th class="py-1">Asset Tag</th>
                        <th class="py-1">Asset Name</th>
                        <th class="py-1">Fiscal Year</th>
                        <th class="py-1">Period</th>
                        <th class="py-1 text-right">Charge</th>
                        <th class="py-1 text-right">Accumulated</th>
                        <th class="py-1 text-right">Net Book Value</th>
                    @elseif ($tab === 'valuation')
                        <th class="py-1">Item Code</th>
                        <th class="py-1">Item Name</th>
                        <th class="py-1">Category</th>
                        <th class="py-1">Location</th>
                        <th class="py-1 text-right">Qty on Hand</th>
                        <th class="py-1 text-right">Value on Hand</th>
                    @elseif ($tab === 'movements')
                        <th class="py-1">Date</th>
                        <th class="py-1">Item</th>
                        <th class="py-1">Location</th>
                        <th class="py-1">Type</th>
                        <th class="py-1 text-right">Quantity</th>
                        <th class="py-1">Reference</th>
                    @else
                        <th class="py-1">Tag Number</th>
                        <th class="py-1">Name</th>
                        <th class="py-1">Category</th>
                        <th class="py-1">Acquisition Date</th>
                        <th class="py-1 text-right">Acquisition Cost</th>
                        <th class="py-1">Status</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-black/10" wire:key="print-{{ $tab }}-{{ $row->id ?? $loop->index }}">
                        @if ($tab === 'depreciation')
                            <td class="py-1">{{ $row->tag_number }}</td>
                            <td class="py-1">{{ $row->asset_name }}</td>
                            <td class="py-1">{{ $row->fiscal_year_id }}</td>
                            <td class="py-1">{{ $row->period_month }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->charge) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->closing_accumulated) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->net_book_value) }}</td>
                        @elseif ($tab === 'valuation')
                            <td class="py-1">{{ $row->item_code }}</td>
                            <td class="py-1">{{ $row->item_name }}</td>
                            <td class="py-1">{{ $row->category_name }}</td>
                            <td class="py-1">{{ $row->location_name }}</td>
                            <td class="py-1 text-right">{{ $row->quantity_on_hand }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->value_on_hand) }}</td>
                        @elseif ($tab === 'movements')
                            <td class="py-1">{{ $row->moved_on }}</td>
                            <td class="py-1">{{ $row->item_code }} — {{ $row->item_name }}</td>
                            <td class="py-1">{{ $row->location_name }}</td>
                            <td class="py-1">{{ str_replace('_', ' ', $row->movement_type) }}</td>
                            <td class="py-1 text-right">{{ $row->quantity }}</td>
                            <td class="py-1">{{ $row->document_ref ?? '—' }}</td>
                        @else
                            <td class="py-1">{{ $row->tag_number }}</td>
                            <td class="py-1">{{ $row->name }}</td>
                            <td class="py-1">{{ $row->category_name }}</td>
                            <td class="py-1">{{ $row->acquisition_date }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->acquisition_cost) }}</td>
                            <td class="py-1">{{ ucfirst(str_replace('_', ' ', $row->status)) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
