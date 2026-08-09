@php
    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $ratingTone = [
        'good' => 'ok',
        'fair' => 'amber',
        'poor' => 'amber',
        'critical' => 'red',
    ];

    $ratingLabel = [
        'good' => 'Good',
        'fair' => 'Fair',
        'poor' => 'Poor',
        'critical' => 'Critical',
    ];

    $genderLabel = [
        'boys' => 'Boys',
        'girls' => 'Girls',
        'mixed' => 'Mixed',
    ];

    $tabs = [
        ['value' => 'rooms', 'label' => 'Rooms & Beds', 'count' => $tabCounts['rooms']],
        ['value' => 'allocations', 'label' => 'Allocations', 'count' => $tabCounts['allocations']],
        ['value' => 'inspections', 'label' => 'Inspections', 'count' => $tabCounts['inspections']],
        ['value' => 'occupancy', 'label' => 'Occupancy', 'count' => $tabCounts['occupancy']],
    ];
@endphp

<x-list-screen
    title="Hostel Management"
    :breadcrumb="['Dashboard', 'Hostel']"
    :paginator="$rows"
    empty-message="No hostel records match these filters yet. Hostels, rooms and allocations appear here as they are set up."
    rail-title="Occupancy Overview"
>
    {{-- Five KPI cards, mirroring the mockup's strip: total rooms, total
         beds, occupied beds, occupancy rate, open inspections -
         dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Rooms" :value="$kpis['rooms']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 21V5a2 2 0 012-2h14a2 2 0 012 2v16"/><path stroke-linecap="round" d="M3 21h18M9 21v-4h6v4"/><path stroke-linecap="round" d="M8 8h2M14 8h2M8 12h2M14 12h2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Total Beds" :value="$kpis['beds']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 18v-6a1 1 0 011-1h16a1 1 0 011 1v6"/><path stroke-linecap="round" d="M3 18h18M5 11V7a1 1 0 011-1h4a2 2 0 012 2v3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Occupied Beds" :value="$kpis['occupied']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Occupancy Rate" :value="number_format($kpis['occupancy_pct'], 2).'%'" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 19V5M4 19h16"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 15l4-4 3 3 5-6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Open Inspections" :value="$kpis['open_inspections']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 005.4-5.4l-2.4 2.4-2.3-2.3 2.4-2.4z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="hostel-filter-hostel" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Hostel</span>
            <select id="hostel-filter-hostel" wire:model.live="hostel"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All hostels</option>
                @foreach ($hostelOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="hostel-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ $tab === 'inspections' ? 'Rating' : 'Status' }}</span>
                <select id="hostel-filter-status" wire:model.live="status"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="hostel-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="hostel-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search by room no. or student name..."
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'rooms')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Hostel Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room No.</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Capacity</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Beds</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Occupied</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Available</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Occupancy %</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room Status</th>
            @elseif ($tab === 'allocations')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Hostel</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Bed</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Since</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'inspections')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Hostel</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Inspector</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Rating</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Findings</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Resolved</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Hostel</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Gender</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Rooms</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Beds</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Occupied</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Available</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Occupancy %</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="hostel-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'rooms')
                @php
                    $beds = (int) $row->beds_count;
                    $occupied = (int) $row->occupied_count;
                    $pct = $beds > 0 ? round($occupied * 100 / $beds, 2) : 0.0;
                    [$tone, $word] = $beds > 0 && $occupied >= $beds
                        ? ['red', 'Full']
                        : ($occupied > 0 ? ['amber', 'Occupied'] : ['ok', 'Available']);
                @endphp
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->hostel_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->capacity }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $beds }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $occupied }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ max(0, $beds - $occupied) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($pct, 2) }}%</td>
                <td class="px-4 py-2.5"><x-status-pill :status="$tone" :label="$word"/></td>
            @elseif ($tab === 'allocations')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->hostel_code }} - {{ $row->hostel_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->bed_label }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                </td>
            @elseif ($tab === 'inspections')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->inspected_on }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->hostel_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_name ?? 'Whole building' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->inspector_name ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$ratingTone[$row->rating] ?? 'ok'" :label="$ratingLabel[$row->rating] ?? $row->rating"/>
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->findings ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->resolved_at !== null ? 'ok' : 'amber'" :label="$row->resolved_at !== null ? 'Resolved' : 'Open'"/>
                </td>
            @else
                @php
                    $beds = (int) $row->beds_count;
                    $occupied = (int) $row->occupied_count;
                    $pct = $beds > 0 ? round($occupied * 100 / $beds, 2) : 0.0;
                @endphp
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->code }} - {{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $genderLabel[$row->gender] ?? $row->gender }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->rooms_count }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $beds }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $occupied }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ max(0, $beds - $occupied) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format($pct, 2) }}%</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="hostel-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-sand bg-white p-3">
                @if ($tab === 'rooms')
                    @php
                        $beds = (int) $row->beds_count;
                        $occupied = (int) $row->occupied_count;
                        [$tone, $word] = $beds > 0 && $occupied >= $beds
                            ? ['red', 'Full']
                            : ($occupied > 0 ? ['amber', 'Occupied'] : ['ok', 'Available']);
                    @endphp
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->hostel_name }} · {{ $row->name }}</p>
                        <x-status-pill :status="$tone" :label="$word"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $occupied }}/{{ $beds }} beds occupied · capacity {{ $row->capacity }}</p>
                @elseif ($tab === 'allocations')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->hostel_code }} · {{ $row->room_name }} · Bed {{ $row->bed_label }}</p>
                @elseif ($tab === 'inspections')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->hostel_name }} · {{ $row->inspected_on }}</p>
                        <x-status-pill :status="$ratingTone[$row->rating] ?? 'ok'" :label="$ratingLabel[$row->rating] ?? $row->rating"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->room_name ?? 'Whole building' }} · {{ $row->findings ?? 'No findings noted' }}</p>
                @else
                    @php
                        $beds = (int) $row->beds_count;
                        $occupied = (int) $row->occupied_count;
                    @endphp
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->code }} - {{ $row->name }}</p>
                        <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $genderLabel[$row->gender] ?? $row->gender }} · {{ $occupied }}/{{ $beds }} beds occupied</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: occupancy meter + per-gender hostel summary + open
         inspections, as in the mockup's right column. --}}
    <x-slot:rail>
        <div class="space-y-4">
            <section aria-label="Occupancy overview" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Occupancy Overview</h3>
                <p class="text-2xl font-semibold tabular-nums text-charcoal">{{ $kpis['occupied'] }}
                    <span class="text-sm font-normal text-charcoal/60">occupied beds</span></p>
                <div class="mt-2 h-1.5 w-full rounded-full bg-sand">
                    <div class="h-1.5 rounded-full bg-primary" style="width: {{ min(100, (int) round($kpis['occupancy_pct'])) }}%"></div>
                </div>
                <ul class="mt-2 space-y-1 text-xs text-charcoal/70">
                    <li class="flex justify-between"><span>Occupied</span><span class="tabular-nums">{{ $kpis['occupied'] }} ({{ number_format($kpis['occupancy_pct'], 2) }}%)</span></li>
                    <li class="flex justify-between"><span>Available</span><span class="tabular-nums">{{ max(0, $kpis['beds'] - $kpis['occupied']) }}</span></li>
                    <li class="flex justify-between"><span>Total beds</span><span class="tabular-nums">{{ $kpis['beds'] }}</span></li>
                </ul>
            </section>

            <section aria-label="Hostel summary" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Hostel Summary</h3>
                <ul class="space-y-2.5">
                    @foreach ($hostelSummary as $band)
                        <li>
                            <div class="flex items-center justify-between text-xs text-charcoal/70">
                                <span>{{ $genderLabel[$band['gender']] ?? $band['gender'] }} hostels · {{ $band['rooms'] }} rooms</span>
                                <span class="tabular-nums">{{ $band['share'] }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                <div class="h-1.5 rounded-full {{ match ($band['gender']) {
                                        'boys' => 'bg-primary',
                                        'girls' => 'bg-badge-purple',
                                        default => 'bg-heritage-yellow',
                                    } }}"
                                     style="width: {{ $band['share'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-label="Upcoming inspections" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Open Inspections</h3>
                @if ($openInspections === [])
                    <p class="text-sm text-charcoal/60">No unresolved findings.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($openInspections as $item)
                            <li class="flex items-start justify-between gap-2 text-sm">
                                <div>
                                    <p class="font-medium text-charcoal">{{ $item['hostel'] }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $item['room'] ?? 'Whole building' }} · {{ $ratingLabel[$item['rating']] ?? $item['rating'] }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs font-semibold text-heritage-red">Since: {{ $item['inspected_on'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
