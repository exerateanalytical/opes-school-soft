{{-- One vehicle's card — /transport/vehicles/{vehicle}. Header summary,
     current driver, then the trip/fuel/maintenance log history, plus a
     printable Vehicle Card (Assets\Livewire\Show's asset-card pattern). --}}

@php
    use App\Support\Money\Money;

    $vehicleTone = [
        'operational' => 'ok',
        'under_maintenance' => 'amber',
        'out_of_service' => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/transport') }}" class="hover:text-primary">Transport</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $vehicle->registration_no }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-charcoal">{{ $vehicle->registration_no }}</h1>
            <x-status-pill :status="$vehicleTone[$vehicle->status] ?? 'ok'"
                           :label="ucfirst(str_replace('_', ' ', $vehicle->status))"/>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Vehicle card ───────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Vehicle details</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Make / Model</dt>
                        <dd class="mt-0.5 text-charcoal">
                            {{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) !== '' ? trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Capacity</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $vehicle->capacity }} seats</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Insurance expires</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $vehicle->insurance_expires_on ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Inspection expires</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $vehicle->inspection_expires_on ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Assigned driver</dt>
                        <dd class="mt-0.5 text-charcoal">
                            @if ($driver !== null)
                                {{ $driver->name }}
                                <span class="block text-xs font-normal text-charcoal/60">
                                    {{ $driver->phone ?? '—' }} · since {{ $driver->active_from }}
                                </span>
                            @else
                                No driver currently assigned
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- ── Trip logs ──────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Trip log history</h2>
                </div>
                @if ($tripLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No trip logs recorded for this vehicle."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Date</th>
                                    <th scope="col" class="px-4 py-2">Route</th>
                                    <th scope="col" class="px-4 py-2">Driver</th>
                                    <th scope="col" class="px-4 py-2 text-right">Odometer</th>
                                    <th scope="col" class="px-4 py-2 text-right">Distance</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($tripLogs as $log)
                                    <tr wire:key="trip-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->route_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->driver_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->odometer_start }} → {{ $log->odometer_end }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ (int) $log->odometer_end - (int) $log->odometer_start }} km</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Fuel logs ──────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Fuel log history</h2>
                </div>
                @if ($fuelLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No fuel logs recorded for this vehicle."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Date</th>
                                    <th scope="col" class="px-4 py-2 text-right">Litres</th>
                                    <th scope="col" class="px-4 py-2 text-right">Cost</th>
                                    <th scope="col" class="px-4 py-2 text-right">Odometer</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($fuelLogs as $log)
                                    <tr wire:key="fuel-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->litres }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::of((int) $log->cost_amount)->format(false) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->odometer ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Maintenance logs ───────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Maintenance log history</h2>
                </div>
                @if ($maintenanceLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No maintenance logs recorded for this vehicle."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Date</th>
                                    <th scope="col" class="px-4 py-2">Type</th>
                                    <th scope="col" class="px-4 py-2">Description</th>
                                    <th scope="col" class="px-4 py-2 text-right">Cost</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($maintenanceLogs as $log)
                                    <tr wire:key="maint-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 capitalize text-charcoal/70">{{ $log->type }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->description }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->cost_amount !== null ? Money::of((int) $log->cost_amount)->format(false) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Right rail: printable Vehicle Card ─────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section class="rounded border border-sand bg-white p-4 print:border-0 print:shadow-none" id="vehicle-card-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print vehicle card</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            Print
                        </button>
                        <button type="button" wire:click="exportVehicleCardPdf"
                                class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            Export PDF
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Registration</dt>
                        <dd class="mt-0.5 font-medium text-charcoal">{{ $vehicle->registration_no }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Status</dt>
                        <dd class="mt-0.5 text-charcoal">{{ ucfirst(str_replace('_', ' ', $vehicle->status)) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Driver</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $driver?->name ?? '—' }}</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>
