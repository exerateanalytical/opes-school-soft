{{-- One vehicle's card — /transport/vehicles/{vehicle}. Identity and
     compliance, utilisation KPIs, current + historical drivers, the routes
     it actually runs and the students allocated to them, then the
     trip/fuel/maintenance log history, plus a printable Vehicle Card
     (Assets\Livewire\Show's asset-card pattern).
     Heritage design system, PROGRESS.md §4a Phase 2. --}}

@php
    use App\Support\Money\Money;

    $vehicleTone = [
        'operational' => 'ok',
        'under_maintenance' => 'amber',
        'out_of_service' => 'red',
    ];

    // Compliance expiry: expired = red, within 30 days = amber, else ok.
    $expiryTone = static function (?string $date): string {
        if ($date === null) {
            return 'amber';
        }
        $days = \Illuminate\Support\Carbon::parse($date)->diffInDays(now(), false);

        return $days > 0 ? 'red' : ($days > -30 ? 'amber' : 'ok');
    };
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.welfare_detail.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/transport') }}" class="hover:text-primary">{{ __('opes.welfare_detail.breadcrumb_transport') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $vehicle->registration_no }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-charcoal">{{ $vehicle->registration_no }}</h1>
            <x-status-pill :status="$vehicleTone[$vehicle->status] ?? 'ok'"
                           :label="__('opes.welfare_detail.vehicle_status.'.$vehicle->status)"/>
        </div>
        <a href="{{ url('/transport') }}"
           class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.welfare_detail.back_to_transport') }}
        </a>
    </div>

    {{-- ── Utilisation KPIs ───────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            ['label' => __('opes.welfare_detail.kpi_seats'), 'value' => $stats['capacity']],
            ['label' => __('opes.welfare_detail.kpi_allocated'), 'value' => $stats['allocated']],
            ['label' => __('opes.welfare_detail.kpi_seats_free'), 'value' => $stats['seats_free']],
            ['label' => __('opes.welfare_detail.kpi_distance'), 'value' => number_format($stats['distance_km']).' km'],
            ['label' => __('opes.welfare_detail.kpi_routes'), 'value' => $routes->count()],
        ] as $kpi)
            <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">{{ $kpi['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-charcoal">{{ $kpi['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Vehicle card ───────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.vehicle_details') }}</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_make_model') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            {{ trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) !== '' ? trim(($vehicle->make ?? '').' '.($vehicle->model ?? '')) : '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_capacity') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.seats_count', ['count' => $vehicle->capacity]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_insurance_expires') }}</dt>
                        <dd class="mt-0.5 flex flex-wrap items-center gap-2 text-base text-charcoal">
                            {{ $vehicle->insurance_expires_on ?? '—' }}
                            <x-status-pill :status="$expiryTone($vehicle->insurance_expires_on)"
                                           :label="$vehicle->insurance_expires_on === null
                                               ? __('opes.welfare_detail.compliance_unknown')
                                               : ($expiryTone($vehicle->insurance_expires_on) === 'red'
                                                   ? __('opes.welfare_detail.compliance_expired')
                                                   : ($expiryTone($vehicle->insurance_expires_on) === 'amber'
                                                       ? __('opes.welfare_detail.compliance_due_soon')
                                                       : __('opes.welfare_detail.compliance_valid')))"/>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_inspection_expires') }}</dt>
                        <dd class="mt-0.5 flex flex-wrap items-center gap-2 text-base text-charcoal">
                            {{ $vehicle->inspection_expires_on ?? '—' }}
                            <x-status-pill :status="$expiryTone($vehicle->inspection_expires_on)"
                                           :label="$vehicle->inspection_expires_on === null
                                               ? __('opes.welfare_detail.compliance_unknown')
                                               : ($expiryTone($vehicle->inspection_expires_on) === 'red'
                                                   ? __('opes.welfare_detail.compliance_expired')
                                                   : ($expiryTone($vehicle->inspection_expires_on) === 'amber'
                                                       ? __('opes.welfare_detail.compliance_due_soon')
                                                       : __('opes.welfare_detail.compliance_valid')))"/>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_asset_link') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            {{ $vehicle->asset_id !== null ? __('opes.welfare_detail.asset_ref', ['id' => $vehicle->asset_id]) : __('opes.welfare_detail.asset_unlinked') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_last_service') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $stats['last_service_on'] ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_assigned_driver') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            @if ($driver !== null)
                                {{ $driver->name }}
                                <span class="block text-xs font-normal text-charcoal/60">
                                    {{ $driver->phone ?? '—' }} · {{ __('opes.welfare_detail.since', ['date' => $driver->active_from]) }}
                                </span>
                            @else
                                {{ __('opes.welfare_detail.no_driver_assigned') }}
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>

            {{-- ── Routes served ──────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.routes_title') }}</h2>
                    <p class="mt-1 text-xs text-charcoal/60">{{ __('opes.welfare_detail.routes_note') }}</p>
                </div>
                @if ($routes->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_routes')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_route') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_stops') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_students') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_trips') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_last_trip') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($routes as $route)
                                    <tr wire:key="route-{{ $route->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">
                                            {{ $route->code }}
                                            <span class="block text-xs font-normal text-charcoal/60">{{ $route->name }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $route->stop_count }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $route->active_allocations }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $route->trip_count }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $route->last_trip_on ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$route->is_active ? 'ok' : 'amber'"
                                                           :label="$route->is_active ? __('opes.welfare_detail.active') : __('opes.welfare_detail.inactive')"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Students allocated to those routes ─────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.manifest_title') }}</h2>
                    <p class="mt-1 text-xs text-charcoal/60">{{ __('opes.welfare_detail.manifest_note') }}</p>
                </div>
                @if ($assignedStudents->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_assigned_students')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_student') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_route') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_stop') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_direction') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_times') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_since') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($assignedStudents as $allocation)
                                    <tr wire:key="assigned-{{ $allocation->id }}">
                                        <td class="px-4 py-2 text-charcoal">
                                            <a href="{{ url('/students/'.$allocation->student_id) }}" class="font-medium hover:text-primary">
                                                {{ trim($allocation->first_name.' '.$allocation->last_name) }}
                                            </a>
                                            <span class="block text-xs text-charcoal/60">{{ $allocation->matricule }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $allocation->route_code }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $allocation->stop_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ __('opes.welfare_detail.direction.'.$allocation->direction) }}</td>
                                        <td class="px-4 py-2 text-xs text-charcoal/60">
                                            {{ $allocation->pickup_time ?? '—' }} / {{ $allocation->dropoff_time ?? '—' }}
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $allocation->starts_on }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Driver history ─────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.driver_history_title') }}</h2>
                </div>
                @if ($driverHistory->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_drivers')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_driver') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_phone') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_from') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_to') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($driverHistory as $entry)
                                    <tr wire:key="driver-{{ $entry->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $entry->name }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $entry->phone ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $entry->active_from }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $entry->active_to ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$entry->active_to === null ? 'ok' : 'amber'"
                                                           :label="$entry->active_to === null ? __('opes.welfare_detail.current') : __('opes.welfare_detail.ended')"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Trip logs ──────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.trip_logs_title') }}</h2>
                </div>
                @if ($tripLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_trip_logs')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_date') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_route') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_driver') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_notes') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_odometer') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_distance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($tripLogs as $log)
                                    <tr wire:key="trip-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->route_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->driver_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->notes ?? '—' }}</td>
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
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.fuel_logs_title') }}</h2>
                </div>
                @if ($fuelLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_fuel_logs')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_date') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_litres') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_cost') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_odometer') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($fuelLogs as $log)
                                    <tr wire:key="fuel-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->litres }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ Money::of((int) $log->cost_amount)->format(false) }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->odometer ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-border-primary bg-sand/30 text-sm font-semibold text-charcoal">
                                <tr>
                                    <td class="px-4 py-2">{{ __('opes.welfare_detail.total') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $stats['litres'] }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ Money::of($stats['fuel_cost'])->format(false) }}</td>
                                    <td class="px-4 py-2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Maintenance logs ───────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.maintenance_logs_title') }}</h2>
                </div>
                @if ($maintenanceLogs->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_maintenance_logs')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_date') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_type') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_description') }}</th>
                                    <th scope="col" class="px-4 py-2 text-right">{{ __('opes.welfare_detail.col_cost') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($maintenanceLogs as $log)
                                    <tr wire:key="maint-{{ $log->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->date }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ __('opes.welfare_detail.maintenance_type.'.$log->type) }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $log->description }}</td>
                                        <td class="px-4 py-2 text-right tabular-nums">{{ $log->cost_amount !== null ? Money::of((int) $log->cost_amount)->format(false) : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="border-t border-border-primary bg-sand/30 text-sm font-semibold text-charcoal">
                                <tr>
                                    <td class="px-4 py-2" colspan="3">{{ __('opes.welfare_detail.total') }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ Money::of($stats['maintenance_cost'])->format(false) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Right rail: printable Vehicle Card ─────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm print:border-0 print:shadow-none" id="vehicle-card-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.print_vehicle_card') }}</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.welfare_detail.print') }}
                        </button>
                        <button type="button" wire:click="exportVehicleCardPdf"
                                class="rounded-xl bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            {{ __('opes.welfare_detail.export_pdf') }}
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_registration') }}</dt>
                        <dd class="mt-0.5 text-base font-medium text-charcoal">{{ $vehicle->registration_no }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.col_status') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.vehicle_status.'.$vehicle->status) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.col_driver') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $driver?->name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.kpi_utilisation') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal tabular-nums">{{ $stats['utilisation_pct'] }}%</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.running_costs_title') }}</h2>
                <dl class="mt-3 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.col_litres') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">{{ $stats['litres'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.fuel_cost') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">{{ Money::of($stats['fuel_cost'])->format(false) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-3">
                        <dt class="text-sm text-charcoal/70">{{ __('opes.welfare_detail.maintenance_cost') }}</dt>
                        <dd class="text-base font-medium tabular-nums text-charcoal">{{ Money::of($stats['maintenance_cost'])->format(false) }}</dd>
                    </div>
                </dl>
                <p class="mt-3 text-xs text-charcoal/60">{{ __('opes.welfare_detail.running_costs_note') }}</p>
            </section>
        </div>
    </div>
</div>
