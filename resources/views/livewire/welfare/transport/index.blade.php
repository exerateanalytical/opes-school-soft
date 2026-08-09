@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $vehicleTone = [
        'operational' => 'ok',
        'under_maintenance' => 'amber',
        'out_of_service' => 'red',
    ];

    $vehicleLabel = [
        'operational' => 'Operational',
        'under_maintenance' => 'Under maintenance',
        'out_of_service' => 'Out of service',
    ];

    $tabs = [
        ['value' => 'routes', 'label' => 'Routes & Stops', 'count' => $tabCounts['routes']],
        ['value' => 'vehicles', 'label' => 'Vehicles', 'count' => $tabCounts['vehicles']],
        ['value' => 'allocations', 'label' => 'Allocations', 'count' => $tabCounts['allocations']],
        ['value' => 'logs', 'label' => 'Logs', 'count' => $tabCounts['logs']],
    ];
@endphp

<x-list-screen
    title="Transport Management"
    :breadcrumb="['Dashboard', 'Transport']"
    :paginator="$rows"
    empty-message="No transport records match these filters yet. Routes, vehicles and allocations appear here as they are set up."
    rail-title="Transport Overview"
>
    {{-- Five KPI cards, mirroring the mockup's strip: buses, routes,
         riders, trips, maintenance due - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Buses" :value="$kpis['buses']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="3" width="16" height="14" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 21v-4M16 21v-4"/><circle cx="8.5" cy="13.5" r="0.5"/><circle cx="15.5" cy="13.5" r="0.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Active Routes" :value="$kpis['active_routes']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Students Transported" :value="$kpis['students']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Trips Logged" :value="$kpis['trips']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Maintenance Due" :value="$kpis['maintenance_due']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 005.4-5.4l-2.4 2.4-2.3-2.3 2.4-2.4z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="transport-filter-route" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Route</span>
            <select id="transport-filter-route" wire:model.live="route"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All routes</option>
                @foreach ($routeOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="transport-filter-vehicle" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Vehicle</span>
            <select id="transport-filter-vehicle" wire:model.live="vehicle"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All vehicles</option>
                @foreach ($vehicleOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="transport-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="transport-filter-status" wire:model.live="status"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($tab === 'logs')
            <label for="transport-filter-logtype" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Log type</span>
                <select id="transport-filter-logtype" wire:model.live="logType"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="trips">Trips</option>
                    <option value="fuel">Fuel</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </label>
        @endif

        <label for="transport-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="transport-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search route, vehicle, student..."
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
            @if ($tab === 'routes')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route Code</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Stops</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Students</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Trip Time</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'vehicles')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Make / Model</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Driver</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Capacity</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Insurance</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Inspection</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'allocations')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Stop</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Direction</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Since</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($logType === 'fuel')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Litres</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Cost</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Odometer</th>
            @elseif ($logType === 'maintenance')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Description</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Cost</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Driver</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Odometer</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Distance</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="transport-{{ $tab }}-{{ $logType }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'routes')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->code }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->stops_count }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->riders_count }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_pickup !== null ? substr((string) $row->first_pickup, 0, 5) : '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                </td>
            @elseif ($tab === 'vehicles')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ trim(($row->make ?? '').' '.($row->model ?? '')) !== '' ? trim(($row->make ?? '').' '.($row->model ?? '')) : '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->driver_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->capacity }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->insurance_expires_on ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->inspection_expires_on ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$vehicleTone[$row->status] ?? 'ok'" :label="$vehicleLabel[$row->status] ?? $row->status"/>
                </td>
            @elseif ($tab === 'allocations')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->route_code }} - {{ $row->route_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->stop_sequence }}. {{ $row->stop_name }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->direction }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                </td>
            @elseif ($logType === 'fuel')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->litres }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->cost_amount)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->odometer ?? '—' }}</td>
            @elseif ($logType === 'maintenance')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->description }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->cost_amount !== null ? Money::of((int) $row->cost_amount)->format(false) : '—' }}</td>
            @else
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->route_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->driver_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->odometer_start }} → {{ $row->odometer_end }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ (int) $row->odometer_end - (int) $row->odometer_start }} km</td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="transport-card-{{ $tab }}-{{ $logType }}-{{ $row->id }}"
                     class="rounded border border-sand bg-white p-3">
                @if ($tab === 'routes')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->name }}</p>
                        <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->code }} · {{ $row->stops_count }} stops · {{ $row->riders_count }} students</p>
                @elseif ($tab === 'vehicles')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->registration_no }}</p>
                        <x-status-pill :status="$vehicleTone[$row->status] ?? 'ok'" :label="$vehicleLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ trim(($row->make ?? '').' '.($row->model ?? '')) }} · {{ $row->capacity }} seats</p>
                @elseif ($tab === 'allocations')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->route_code }} · {{ $row->stop_name }} · {{ $row->direction }}</p>
                @elseif ($logType === 'fuel')
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->litres }} L · {{ Money::of((int) $row->cost_amount)->format(false) }}</p>
                @elseif ($logType === 'maintenance')
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm capitalize text-charcoal/70">{{ $row->type }} · {{ $row->description }}</p>
                @else
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->route_name ?? 'Off-route' }} · {{ (int) $row->odometer_end - (int) $row->odometer_start }} km</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: vehicle status bars + upcoming maintenance, as in the
         mockup's right column. --}}
    <x-slot:rail>
        <div class="space-y-4">
            <section aria-label="Vehicle status" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Vehicle Status</h3>
                <ul class="space-y-2.5">
                    @foreach ($vehicleStatusBreakdown as $band)
                        <li>
                            <div class="flex items-center justify-between text-xs text-charcoal/70">
                                <span>{{ $vehicleLabel[$band['status']] ?? $band['status'] }}</span>
                                <span class="tabular-nums">{{ $band['count'] }} ({{ $band['share'] }}%)</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                <div class="h-1.5 rounded-full {{ match ($band['status']) {
                                        'operational' => 'bg-primary',
                                        'under_maintenance' => 'bg-heritage-yellow',
                                        default => 'bg-heritage-red',
                                    } }}"
                                     style="width: {{ $band['share'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-label="Upcoming maintenance" class="rounded border border-sand bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Upcoming Maintenance</h3>
                @if ($upcomingMaintenance === [])
                    <p class="text-sm text-charcoal/60">Nothing due in the next 60 days.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($upcomingMaintenance as $item)
                            <li class="flex items-start justify-between gap-2 text-sm">
                                <div>
                                    <p class="font-medium text-charcoal">{{ $item['registration_no'] }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $item['kind'] }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs font-semibold text-heritage-red">Due: {{ $item['due_on'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
