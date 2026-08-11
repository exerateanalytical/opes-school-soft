@php
    $tabs = $reportTabs;
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
            title="Procurement Reports"
            :breadcrumb="['Dashboard', 'Reports', 'Procurement Reports']"
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

            <x-slot:filters>
                @if (in_array($report, ['supplier_register', 'purchase_order_register', 'payables_aging', 'goods_receipt_register'], true))
                    <label for="report-filter-supplier" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Supplier</span>
                        <select id="report-filter-supplier" wire:model.live="supplier"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All suppliers</option>
                            @foreach ($supplierOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($report, ['supplier_register', 'purchase_order_register', 'goods_receipt_register'], true))
                    <label for="report-filter-status" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Status</span>
                        <select id="report-filter-status" wire:model.live="status"
                                class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All statuses</option>
                            @foreach ($statusOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($report, ['purchase_order_register', 'goods_receipt_register'], true))
                    <label for="report-filter-date-from" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">From</span>
                        <input id="report-filter-date-from" type="date" wire:model.live="dateFrom"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                    </label>

                    <label for="report-filter-date-to" class="flex min-w-[9rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">To</span>
                        <input id="report-filter-date-to" type="date" wire:model.live="dateTo"
                               class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                    </label>
                @endif
            </x-slot:filters>

            <x-slot:tabs>
                @foreach ($tabs as $tabOption)
                    <button type="button" wire:click="selectReport('{{ $tabOption['value'] }}')"
                            @if ($report === $tabOption['value']) aria-current="page" @endif
                            class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $report === $tabOption['value']
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ $tabOption['label'] }}
                    </button>
                @endforeach
            </x-slot:tabs>

            <x-slot:head>
                <tr class="bg-chrome text-white">
                    @if ($report === 'purchase_order_register')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Order No.</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Supplier</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @elseif ($report === 'payables_aging')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Supplier</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Current</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">1-30 Days</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">31-60 Days</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">61-90 Days</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">90+ Days</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total</th>
                    @elseif ($report === 'goods_receipt_register')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Receipt No.</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Supplier</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">PO No.</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Received Date</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Discrepancy</th>
                    @else
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">NIU</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Phone</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="report-{{ $report }}-{{ $row->id ?? $row->supplier_id ?? $loop->index }}" class="hover:bg-sand/30">
                    @if ($report === 'purchase_order_register')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->po_no }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->supplier_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->order_date }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->total_ttc, 0) }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->status) }}</td>
                    @elseif ($report === 'payables_aging')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->supplier_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->current, 0) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->days_1_30, 0) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->days_31_60, 0) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->days_61_90, 0) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((float) $row->days_90_plus, 0) }}</td>
                        <td class="px-4 py-2.5 text-right font-medium tabular-nums">{{ number_format((float) $row->total, 0) }}</td>
                    @elseif ($report === 'goods_receipt_register')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->receipt_no }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->supplier_name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->po_no ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->received_on }}</td>
                        <td class="px-4 py-2.5">
                            <x-status-pill :status="$row->has_discrepancy ? 'amber' : 'ok'" :label="$row->has_discrepancy ? 'Discrepancy' : 'Matched'"/>
                        </td>
                    @else
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->code }}</td>
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->name }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->niu ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->phone ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <x-status-pill :status="$row->is_archived ? 'red' : ($row->is_active ? 'ok' : 'amber')" :label="$row->is_archived ? 'Archived' : ($row->is_active ? 'Active' : 'Inactive')"/>
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
        <h1 class="mb-4 text-xl font-semibold">Procurement Reports — {{ $reportTabs[array_search($report, array_column($reportTabs, 'value'), true)]['label'] ?? 'Report' }}</h1>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-black/40 text-left">
                    @if ($report === 'purchase_order_register')
                        <th class="py-1">Order No.</th>
                        <th class="py-1">Supplier</th>
                        <th class="py-1">Date</th>
                        <th class="py-1 text-right">Total</th>
                        <th class="py-1">Status</th>
                    @elseif ($report === 'payables_aging')
                        <th class="py-1">Supplier</th>
                        <th class="py-1 text-right">Current</th>
                        <th class="py-1 text-right">1-30 Days</th>
                        <th class="py-1 text-right">31-60 Days</th>
                        <th class="py-1 text-right">61-90 Days</th>
                        <th class="py-1 text-right">90+ Days</th>
                        <th class="py-1 text-right">Total</th>
                    @elseif ($report === 'goods_receipt_register')
                        <th class="py-1">Receipt No.</th>
                        <th class="py-1">Supplier</th>
                        <th class="py-1">PO No.</th>
                        <th class="py-1">Received Date</th>
                        <th class="py-1">Discrepancy</th>
                    @else
                        <th class="py-1">Code</th>
                        <th class="py-1">Name</th>
                        <th class="py-1">NIU</th>
                        <th class="py-1">Category</th>
                        <th class="py-1">Phone</th>
                        <th class="py-1">Status</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-black/10" wire:key="print-{{ $report }}-{{ $row->id ?? $row->supplier_id ?? $loop->index }}">
                        @if ($report === 'purchase_order_register')
                            <td class="py-1">{{ $row->po_no }}</td>
                            <td class="py-1">{{ $row->supplier_name }}</td>
                            <td class="py-1">{{ $row->order_date }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->total_ttc, 0) }}</td>
                            <td class="py-1">{{ str_replace('_', ' ', $row->status) }}</td>
                        @elseif ($report === 'payables_aging')
                            <td class="py-1">{{ $row->supplier_name }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->current, 0) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->days_1_30, 0) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->days_31_60, 0) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->days_61_90, 0) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->days_90_plus, 0) }}</td>
                            <td class="py-1 text-right">{{ number_format((float) $row->total, 0) }}</td>
                        @elseif ($report === 'goods_receipt_register')
                            <td class="py-1">{{ $row->receipt_no }}</td>
                            <td class="py-1">{{ $row->supplier_name }}</td>
                            <td class="py-1">{{ $row->po_no ?? '—' }}</td>
                            <td class="py-1">{{ $row->received_on }}</td>
                            <td class="py-1">{{ $row->has_discrepancy ? 'Yes' : 'No' }}</td>
                        @else
                            <td class="py-1">{{ $row->code }}</td>
                            <td class="py-1">{{ $row->name }}</td>
                            <td class="py-1">{{ $row->niu ?? '—' }}</td>
                            <td class="py-1">{{ $row->category_name ?? '—' }}</td>
                            <td class="py-1">{{ $row->phone ?? '—' }}</td>
                            <td class="py-1">{{ $row->is_archived ? 'Archived' : ($row->is_active ? 'Active' : 'Inactive') }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
