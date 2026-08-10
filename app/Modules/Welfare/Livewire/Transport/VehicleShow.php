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
    }

    public function render(): mixed
    {
        return view('livewire.welfare.transport.vehicle-show', [
            'vehicle' => $this->vehicleRow(),
            'driver' => $this->currentDriver(),
            'tripLogs' => $this->tripLogs(),
            'fuelLogs' => $this->fuelLogs(),
            'maintenanceLogs' => $this->maintenanceLogs(),
        ]);
    }
}
