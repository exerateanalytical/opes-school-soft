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
            title="Library Reports"
            :breadcrumb="['Dashboard', 'Reports', 'Library Reports']"
            :paginator="$rows"
            empty-message="No data matches this report's filters yet."
        >
            <x-slot:actions>
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Print
                </button>
            </x-slot:actions>

            <x-slot:filters>
                @if ($report === 'catalogue')
                    <label for="report-filter-category" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Category</span>
                        <select id="report-filter-category" wire:model.live="category"
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All categories</option>
                            @foreach ($categoryOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (in_array($report, ['circulation', 'overdue', 'fines'], true))
                    <label for="report-filter-member-type" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Member type</span>
                        <select id="report-filter-member-type" wire:model.live="memberType"
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All member types</option>
                            @foreach ($memberTypeOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif

                @if (! empty($statusOptions))
                    <label for="report-filter-status" class="flex min-w-[11rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Status</span>
                        <select id="report-filter-status" wire:model.live="status"
                                class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">All statuses</option>
                            @foreach ($statusOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
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
                    @if ($report === 'circulation')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Book</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Issued On</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Due On</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Returned On</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @elseif ($report === 'overdue')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Book</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Days Overdue</th>
                    @elseif ($report === 'fines')
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Member</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Book</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Waived Amount</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    @else
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Title</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Author</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total Copies</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Available Copies</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($rows as $row)
                <tr wire:key="report-{{ $report }}-{{ $row->id ?? $loop->index }}" class="hover:bg-sand/30">
                    @if ($report === 'circulation')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->book_title }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->issued_on }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->due_on }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->returned_on ?? '—' }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->status) }}</td>
                    @elseif ($report === 'overdue')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->book_title }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->days_overdue }}</td>
                    @elseif ($report === 'fines')
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->book_title ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->amount }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->waived_amount }}</td>
                        <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ str_replace('_', ' ', $row->status) }}</td>
                    @else
                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->title }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->author }}</td>
                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->copies_total }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->copies_available }}</td>
                    @endif
                </tr>
            @endforeach
        </x-list-screen>
    </div>

    {{-- Print-only clean table: the on-screen x-list-screen (KPIs, filters,
         nav chrome) is hidden by .print-hide above; this simple table is
         what actually prints. --}}
    <div class="hidden print:block">
        <h1 class="mb-4 text-xl font-semibold">Library Reports — {{ $reportTabs[array_search($report, array_column($reportTabs, 'value'), true)]['label'] ?? 'Report' }}</h1>
        <table class="w-full border-collapse text-sm">
            <thead>
                <tr class="border-b border-black/40 text-left">
                    @if ($report === 'circulation')
                        <th class="py-1">Member</th>
                        <th class="py-1">Book</th>
                        <th class="py-1">Issued On</th>
                        <th class="py-1">Due On</th>
                        <th class="py-1">Returned On</th>
                        <th class="py-1">Status</th>
                    @elseif ($report === 'overdue')
                        <th class="py-1">Member</th>
                        <th class="py-1">Book</th>
                        <th class="py-1 text-right">Days Overdue</th>
                    @elseif ($report === 'fines')
                        <th class="py-1">Member</th>
                        <th class="py-1">Book</th>
                        <th class="py-1 text-right">Amount</th>
                        <th class="py-1 text-right">Waived Amount</th>
                        <th class="py-1">Status</th>
                    @else
                        <th class="py-1">Title</th>
                        <th class="py-1">Author</th>
                        <th class="py-1">Category</th>
                        <th class="py-1 text-right">Total Copies</th>
                        <th class="py-1 text-right">Available Copies</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr class="border-b border-black/10" wire:key="print-{{ $report }}-{{ $row->id ?? $loop->index }}">
                        @if ($report === 'circulation')
                            <td class="py-1">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                            <td class="py-1">{{ $row->book_title }}</td>
                            <td class="py-1">{{ $row->issued_on }}</td>
                            <td class="py-1">{{ $row->due_on }}</td>
                            <td class="py-1">{{ $row->returned_on ?? '—' }}</td>
                            <td class="py-1">{{ str_replace('_', ' ', $row->status) }}</td>
                        @elseif ($report === 'overdue')
                            <td class="py-1">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                            <td class="py-1">{{ $row->book_title }}</td>
                            <td class="py-1 text-right">{{ $row->days_overdue }}</td>
                        @elseif ($report === 'fines')
                            <td class="py-1">{{ $row->member_no }}{{ $row->external_name !== null ? ' - '.$row->external_name : '' }}</td>
                            <td class="py-1">{{ $row->book_title ?? '—' }}</td>
                            <td class="py-1 text-right">{{ $row->amount }}</td>
                            <td class="py-1 text-right">{{ $row->waived_amount }}</td>
                            <td class="py-1">{{ str_replace('_', ' ', $row->status) }}</td>
                        @else
                            <td class="py-1">{{ $row->title }}</td>
                            <td class="py-1">{{ $row->author }}</td>
                            <td class="py-1">{{ $row->category_name }}</td>
                            <td class="py-1 text-right">{{ $row->copies_total }}</td>
                            <td class="py-1 text-right">{{ $row->copies_available }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
