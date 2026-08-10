<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Transport;

use App\Modules\Reporting\Support\PdfExport;
use App\Modules\Welfare\Domain\TransportPermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * One vehicle's card at /transport/vehicles/{vehicle}, gated
 * `transport.view` (Transport\Index's own gate) - registration, make/model,
 * status, compliance dates, its currently assigned driver, and the
 * trip/fuel/maintenance log history for that vehicle only, plus a printable
 * "Vehicle Card" (Assets\Livewire\Show's asset-card pattern).
 *
 * Cross-module and own-module reads go through DB::table joins only, never
 * an Eloquent model reach across module boundaries (ModuleBoundaryTest);
 * every history list is capped, no unbounded collection reaches the view
 * (00-core 6.2 rule 8).
 */
#[Layout('layouts.app')]
final class VehicleShow extends Component
{
    /** Cap per history list. */
    private const int HISTORY_LIMIT = 50;

    public int $vehicleId;

    public function mount(int $vehicle): void
    {
        Gate::authorize(TransportPermission::VIEW);

        $this->vehicleId = $vehicle;

        // 404 early rather than rendering an empty card.
        DB::table('vehicles')->where('id', $vehicle)->firstOrFail();
    }

    private function vehicleRow(): stdClass
    {
        /** @var stdClass $row */
        $row = DB::table('vehicles')->where('id', $this->vehicleId)->first();

        return $row;
    }

    private function currentDriver(): ?stdClass
    {
        $row = DB::table('vehicle_drivers')
            ->where('vehicle_id', $this->vehicleId)
            ->whereNull('active_to')
            ->orderByDesc('active_from')
            ->first(['id', 'name', 'phone', 'active_from']);

        return $row instanceof stdClass ? $row : null;
    }

    /**
     * Every driver ever posted to this vehicle, newest first. `licence_no`
     * is an encrypted column and is deliberately NOT surfaced here.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function driverHistory(): \Illuminate\Support\Collection
    {
        return DB::table('vehicle_drivers')
            ->where('vehicle_id', $this->vehicleId)
            ->orderByDesc('active_from')->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get(['id', 'name', 'phone', 'active_from', 'active_to']);
    }

    /**
     * The routes this vehicle actually runs, derived from its trip log
     * (there is no vehicle→route FK in the schema), with the live student
     * allocation count on each.
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function routesServed(): \Illuminate\Support\Collection
    {
        return DB::table('vehicle_trip_logs as l')
            ->join('transport_routes as tr', 'tr.id', '=', 'l.route_id')
            ->where('l.vehicle_id', $this->vehicleId)
            ->groupBy('tr.id', 'tr.code', 'tr.name', 'tr.is_active')
            ->orderBy('tr.code')
            ->limit(self::HISTORY_LIMIT)
            ->select(['tr.id', 'tr.code', 'tr.name', 'tr.is_active'])
            ->selectRaw('COUNT(l.id) as trip_count')
            ->selectRaw('MAX(l.date) as last_trip_on')
            ->selectSub(
                DB::table('transport_allocations')
                    ->whereColumn('route_id', 'tr.id')
                    ->where('status', 'active')
                    ->selectRaw('COUNT(*)'),
                'active_allocations'
            )
            ->selectSub(
                DB::table('transport_stops')->whereColumn('route_id', 'tr.id')->selectRaw('COUNT(*)'),
                'stop_count'
            )
            ->get();
    }

    /**
     * Students currently allocated to any route this vehicle runs - the
     * closest the schema allows to a "manifest" (allocations are keyed to a
     * route, not to a vehicle).
     *
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function assignedStudents(): \Illuminate\Support\Collection
    {
        $routeIds = $this->routesServed()->pluck('id')->all();

        if ($routeIds === []) {
            return collect();
        }

        return DB::table('transport_allocations as a')
            ->join('enrollments as e', 'e.id', '=', 'a.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->join('transport_routes as tr', 'tr.id', '=', 'a.route_id')
            ->leftJoin('transport_stops as st', 'st.id', '=', 'a.stop_id')
            ->whereIn('a.route_id', $routeIds)
            ->where('a.status', 'active')
            ->orderBy('tr.code')->orderBy('st.sequence')->orderBy('s.last_name')
            ->limit(self::HISTORY_LIMIT * 4)
            ->select([
                'a.id', 'a.direction', 'a.starts_on',
                's.id as student_id', 's.first_name', 's.last_name', 's.matricule',
                'tr.code as route_code', 'st.name as stop_name', 'st.pickup_time', 'st.dropoff_time',
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function tripLogs(): \Illuminate\Support\Collection
    {
        return DB::table('vehicle_trip_logs as l')
            ->leftJoin('transport_routes as tr', 'tr.id', '=', 'l.route_id')
            ->leftJoin('vehicle_drivers as d', 'd.id', '=', 'l.driver_id')
            ->where('l.vehicle_id', $this->vehicleId)
            ->orderByDesc('l.date')->orderByDesc('l.id')
            ->limit(self::HISTORY_LIMIT)
            ->select([
                'l.id', 'l.date', 'l.odometer_start', 'l.odometer_end', 'l.notes',
                'tr.name as route_name', 'd.name as driver_name',
            ])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function fuelLogs(): \Illuminate\Support\Collection
    {
        return DB::table('vehicle_fuel_logs')
            ->where('vehicle_id', $this->vehicleId)
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->select(['id', 'date', 'litres', 'cost_amount', 'odometer'])
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, stdClass>
     */
    private function maintenanceLogs(): \Illuminate\Support\Collection
    {
        return DB::table('vehicle_maintenance_logs')
            ->where('vehicle_id', $this->vehicleId)
            ->orderByDesc('date')->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->select(['id', 'date', 'type', 'description', 'cost_amount'])
            ->get();
    }

    // ── Export ────────────────────────────────────────────────────────────

    public function exportVehicleCardPdf(): Response
    {
        Gate::authorize(TransportPermission::VIEW);

        $vehicle = $this->vehicleRow();

        return PdfExport::download(
            'Vehicle Card — '.$vehicle->registration_no,
            ['Field', 'Value'],
            $this->vehicleCardRows(),
            'vehicle-card-'.$vehicle->registration_no.'.pdf',
        );
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function vehicleCardRows(): iterable
    {
        $vehicle = $this->vehicleRow();
        $driver = $this->currentDriver();

        yield ['Registration No', $vehicle->registration_no];
        yield ['Make', $vehicle->make ?? '—'];
        yield ['Model', $vehicle->model ?? '—'];
        yield ['Capacity', (string) $vehicle->capacity];
        yield ['Status', ucfirst(str_replace('_', ' ', $vehicle->status))];
        yield ['Insurance Expires', $vehicle->insurance_expires_on ?? '—'];
        yield ['Inspection Expires', $vehicle->inspection_expires_on ?? '—'];
        yield ['Current Driver', $driver?->name ?? '—'];
        yield ['Routes Served', (string) $this->routesServed()->count()];
        yield ['Students Allocated', (string) $this->assignedStudents()->count()];
    }

    public function render(): mixed
    {
        $vehicle = $this->vehicleRow();
        $tripLogs = $this->tripLogs();
        $fuelLogs = $this->fuelLogs();
        $maintenanceLogs = $this->maintenanceLogs();
        $assigned = $this->assignedStudents();

        $capacity = (int) $vehicle->capacity;
        $allocated = $assigned->count();

        return view('livewire.welfare.transport.vehicle-show', [
            'vehicle' => $vehicle,
            'driver' => $this->currentDriver(),
            'driverHistory' => $this->driverHistory(),
            'routes' => $this->routesServed(),
            'assignedStudents' => $assigned,
            'tripLogs' => $tripLogs,
            'fuelLogs' => $fuelLogs,
            'maintenanceLogs' => $maintenanceLogs,
            'stats' => [
                'capacity' => $capacity,
                'allocated' => $allocated,
                'seats_free' => max(0, $capacity - $allocated),
                'utilisation_pct' => $capacity > 0 ? (int) round($allocated / $capacity * 100) : 0,
                'distance_km' => (int) $tripLogs->sum(
                    fn (stdClass $l): int => max(0, (int) $l->odometer_end - (int) $l->odometer_start)
                ),
                'litres' => (float) $fuelLogs->sum(fn (stdClass $l): float => (float) $l->litres),
                'fuel_cost' => (int) $fuelLogs->sum(fn (stdClass $l): int => (int) $l->cost_amount),
                'maintenance_cost' => (int) $maintenanceLogs->sum(fn (stdClass $l): int => (int) $l->cost_amount),
                'last_service_on' => $maintenanceLogs->first()->date ?? null,
            ],
        ]);
    }
}
