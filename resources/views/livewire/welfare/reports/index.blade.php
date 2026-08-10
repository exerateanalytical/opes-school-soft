@php
    $tabs = [
        ['value' => 'transport', 'label' => 'Transport Roster'],
        ['value' => 'hostel', 'label' => 'Hostel Occupancy'],
        ['value' => 'medical', 'label' => 'Medical Log'],
        ['value' => 'discipline', 'label' => 'Discipline Register'],
        ['value' => 'insurance', 'label' => 'Insurance Register'],
    ];
@endphp

<div class="min-w-0 space-y-4 print:space-y-2">
    <style>
        @media print {
            nav, .no-print { display: none !important; }
            body { background: #fff; }
        }
    </style>

    <x-list-screen
        title="Welfare Reports"
        :breadcrumb="['Dashboard', 'Welfare', 'Reports']"
        :paginator="$rows"
        empty-message="No data for the selected filters."
    >
        <x-slot:actions>
            <div class="flex items-center gap-2 no-print">
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    Print
                </button>
            </div>
        </x-slot:actions>

        <x-slot:filters>
            <label for="wr-date-from" class="flex min-w-[9rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">From</span>
                <input id="wr-date-from" type="date" wire:model.live="dateFrom"
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="wr-date-to" class="flex min-w-[9rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">To</span>
                <input id="wr-date-to" type="date" wire:model.live="dateTo"
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="wr-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="wr-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <button type="button" wire:click="resetFilters"
                    class="self-end rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:text-charcoal no-print">
                Reset
            </button>
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
            @if ($tab === 'hostel')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Hostel</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Bed</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                </tr>
            @elseif ($tab === 'medical')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Severity</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Outcome</th>
                </tr>
            @elseif ($tab === 'discipline')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                </tr>
            @elseif ($tab === 'insurance')
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Policy</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Provider</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                </tr>
            @else
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Stop</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                </tr>
            @endif
        </x-slot:head>

        @if ($tab === 'hostel')
            @foreach ($rows as $row)
                <tr wire:key="wr-hostel-{{ $loop->index }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->last_name }} {{ $row->first_name }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->hostel_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->bed_label }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'neutral'" :label="ucfirst($row->status)"/>
                    </td>
                </tr>
            @endforeach
        @elseif ($tab === 'medical')
            @foreach ($rows as $row)
                <tr wire:key="wr-medical-{{ $loop->index }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->last_name }} {{ $row->first_name }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->visited_at)->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->severity === 'high' ? 'red' : ($row->severity === 'moderate' ? 'amber' : 'neutral')" :label="ucfirst($row->severity)"/>
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ ucfirst(str_replace('_', ' ', $row->outcome)) }}</td>
                </tr>
            @endforeach
        @elseif ($tab === 'discipline')
            @foreach ($rows as $row)
                <tr wire:key="wr-discipline-{{ $loop->index }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->last_name }} {{ $row->first_name }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->occurred_on)->format('d/m/Y') }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->status === 'resolved' ? 'ok' : ($row->status === 'open' ? 'amber' : 'neutral')" :label="ucfirst(str_replace('_', ' ', $row->status))"/>
                    </td>
                </tr>
            @endforeach
        @elseif ($tab === 'insurance')
            @foreach ($rows as $row)
                <tr wire:key="wr-insurance-{{ $loop->index }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->last_name }} {{ $row->first_name }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->policy_no }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->provider }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'neutral'" :label="ucfirst($row->status)"/>
                    </td>
                </tr>
            @endforeach
        @else
            @foreach ($rows as $row)
                <tr wire:key="wr-transport-{{ $loop->index }}">
                    <td class="px-4 py-2.5 text-charcoal">{{ $row->last_name }} {{ $row->first_name }}</td>
                    <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->matricule }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->route_code }} — {{ $row->route_name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->stop_name }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'neutral'" :label="ucfirst($row->status)"/>
                    </td>
                </tr>
            @endforeach
        @endif
    </x-list-screen>
</div>
