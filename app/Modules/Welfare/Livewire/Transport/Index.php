<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Transport;

use App\Modules\Welfare\Actions\AllocateTransport;
use App\Modules\Welfare\Actions\CreateRoute;
use App\Modules\Welfare\Actions\EndTransportAllocation;
use App\Modules\Welfare\Actions\RecordFuelLog;
use App\Modules\Welfare\Actions\RecordMaintenanceLog;
use App\Modules\Welfare\Actions\RecordTripLog;
use App\Modules\Welfare\Actions\SaveVehicle;
use App\Modules\Welfare\Domain\TransportDirection;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Domain\VehicleMaintenanceType;
use App\Modules\Welfare\Domain\VehicleStatus;
use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Transport Management at /transport (route wired by W5), gated
 * `transport.view`, replicating 'frontend images/Transport Management.png':
 * KPI strip (buses / active routes / students transported / trips /
 * maintenance due), filter bar, tabbed table (Routes, Vehicles,
 * Allocations, Logs) and the vehicle-status + upcoming-maintenance rail.
 *
 * Cross-module reads (student names on the Allocations tab) go through
 * DB::table joins only - never another module's Models
 * (ModuleBoundaryTest). One paginated query per render plus the KPI
 * aggregates; no unbounded collection reaches the view (00-core 6.2
 * rule 8, enforced by x-list-screen).
 */
#[Layout('layouts.app')]
final class Index extends Component
{
    /** Which table is showing: routes | vehicles | allocations | logs. */
    #[Url]
    public string $tab = 'routes';

    #[Url]
    public string $route = '';

    #[Url]
    public string $vehicle = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $search = '';

    /** Logs tab only: trips | fuel | maintenance. */
    #[Url]
    public string $logType = 'trips';

    #[Url]
    public int $page = 1;

    public int $perPage = 25;

    // ── Add Route form ──────────────────────────────────────────────────
    public bool $showRouteForm = false;

    public string $routeFormCode = '';

    public string $routeFormName = '';

    public bool $routeFormActive = true;

    // ── Allocate Student form ───────────────────────────────────────────
    public bool $showAllocationForm = false;

    public string $allocationFormEnrollmentId = '';

    public string $allocationFormRouteId = '';

    public string $allocationFormStopId = '';

    public string $allocationFormDirection = 'both';

    public string $allocationFormStartsOn = '';

    // ── Add/Edit Vehicle form ───────────────────────────────────────────
    public bool $showVehicleForm = false;

    public string $vehicleFormRegistrationNo = '';

    public string $vehicleFormMake = '';

    public string $vehicleFormModel = '';

    public string $vehicleFormCapacity = '';

    public string $vehicleFormStatus = 'operational';

    /**
     * Compliance expiry dates. SaveVehicle has always accepted both (they are
     * on Vehicle::$fillable), and the Insurance/Inspection columns, the
     * "Maintenance Due" KPI and the "Upcoming Maintenance" rail all read them
     * - but no form ever collected them, so all four were permanently blank.
     */
    public string $vehicleFormInsuranceExpiresOn = '';

    public string $vehicleFormInspectionExpiresOn = '';

    // ── Record Trip Log form ────────────────────────────────────────────
    public bool $showTripLogForm = false;

    public string $tripLogFormVehicleId = '';

    public string $tripLogFormDriverId = '';

    public string $tripLogFormRouteId = '';

    public string $tripLogFormDate = '';

    public string $tripLogFormOdometerStart = '';

    public string $tripLogFormOdometerEnd = '';

    public string $tripLogFormNotes = '';

    // ── Record Fuel Log form ────────────────────────────────────────────
    public bool $showFuelLogForm = false;

    public string $fuelLogFormVehicleId = '';

    public string $fuelLogFormDate = '';

    public string $fuelLogFormLitres = '';

    public string $fuelLogFormCostAmount = '';

    public string $fuelLogFormOdometer = '';

    // ── Record Maintenance Log form ─────────────────────────────────────
    public bool $showMaintenanceLogForm = false;

    public string $maintenanceLogFormVehicleId = '';

    public string $maintenanceLogFormType = 'service';

    public string $maintenanceLogFormDescription = '';

    public string $maintenanceLogFormCostAmount = '';

    /**
     * `vehicle_maintenance_logs.date` is NOT NULL and RecordMaintenanceLog
     * reads it straight out of $data, but the form never asked for it - so
     * every submission inserted NULL and failed. Defaulted in the toggle,
     * exactly like the trip and fuel log forms alongside it.
     */
    public string $maintenanceLogFormDate = '';

    public function mount(): void
    {
        Gate::authorize(TransportPermission::VIEW);
    }

    public function selectTab(string $tab): void
    {
        $this->tab = in_array($tab, ['routes', 'vehicles', 'allocations', 'logs'], true)
            ? $tab
            : 'routes';
        $this->status = '';
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['route', 'vehicle', 'status', 'search', 'logType']);
        $this->resetPage();
    }

    public function updatedRoute(): void
    {
        $this->resetPage();
    }

    public function updatedVehicle(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedLogType(): void
    {
        $this->logType = in_array($this->logType, ['trips', 'fuel', 'maintenance'], true)
            ? $this->logType
            : 'trips';
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleRouteForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showRouteForm = ! $this->showRouteForm;
    }

    public function saveRoute(CreateRoute $createRoute): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'routeFormCode' => ['required', 'string', 'max:20'],
            'routeFormName' => ['required', 'string', 'max:100'],
        ], [], [
            'routeFormCode' => 'code',
            'routeFormName' => 'name',
        ]);

        try {
            $createRoute->handle(null, [
                'code' => $this->routeFormCode,
                'name' => $this->routeFormName,
                'is_active' => $this->routeFormActive,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('routeForm'.ucfirst($field), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('routeFormCode', $e->getMessage());

            return;
        }

        $this->reset(['showRouteForm', 'routeFormCode', 'routeFormName', 'routeFormActive']);
        $this->routeFormActive = true;
        $this->tab = 'routes';
        $this->resetPage();
        session()->flash('status', 'Route created.');
    }

    public function toggleAllocationForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showAllocationForm = ! $this->showAllocationForm;

        if ($this->showAllocationForm && $this->allocationFormStartsOn === '') {
            $this->allocationFormStartsOn = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveAllocation(AllocateTransport $allocateTransport): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'allocationFormEnrollmentId' => ['required', 'integer', 'min:1'],
            'allocationFormRouteId' => ['required', 'integer', 'min:1'],
            'allocationFormStopId' => ['required', 'integer', 'min:1'],
            'allocationFormDirection' => ['required', 'string', 'in:both,pickup,dropoff'],
            'allocationFormStartsOn' => ['required', 'date'],
        ], [], [
            'allocationFormEnrollmentId' => 'enrollment',
            'allocationFormRouteId' => 'route',
            'allocationFormStopId' => 'stop',
            'allocationFormDirection' => 'direction',
            'allocationFormStartsOn' => 'start date',
        ]);

        try {
            $allocateTransport->handle(
                (int) $this->allocationFormEnrollmentId,
                (int) $this->allocationFormRouteId,
                (int) $this->allocationFormStopId,
                TransportDirection::from($this->allocationFormDirection),
                Carbon::parse($this->allocationFormStartsOn),
                $this->actor(),
            );
        } catch (DomainException $e) {
            $this->addError('allocationFormStopId', $e->getMessage());

            return;
        }

        $this->reset([
            'showAllocationForm', 'allocationFormEnrollmentId', 'allocationFormRouteId',
            'allocationFormStopId', 'allocationFormDirection', 'allocationFormStartsOn',
        ]);
        $this->allocationFormDirection = 'both';
        $this->tab = 'allocations';
        $this->resetPage();
        session()->flash('status', 'Student allocated to route.');
    }

    public function toggleVehicleForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showVehicleForm = ! $this->showVehicleForm;
    }

    public function saveVehicle(SaveVehicle $saveVehicle): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'vehicleFormRegistrationNo' => ['required', 'string', 'max:20'],
            'vehicleFormMake' => ['nullable', 'string', 'max:100'],
            'vehicleFormModel' => ['nullable', 'string', 'max:100'],
            'vehicleFormCapacity' => ['required', 'integer', 'min:1'],
            'vehicleFormStatus' => ['required', 'string', 'in:operational,under_maintenance,out_of_service'],
            'vehicleFormInsuranceExpiresOn' => ['nullable', 'date'],
            'vehicleFormInspectionExpiresOn' => ['nullable', 'date'],
        ], [], [
            'vehicleFormInsuranceExpiresOn' => 'insurance expiry',
            'vehicleFormInspectionExpiresOn' => 'inspection expiry',
            'vehicleFormRegistrationNo' => 'registration number',
            'vehicleFormMake' => 'make',
            'vehicleFormModel' => 'model',
            'vehicleFormCapacity' => 'capacity',
            'vehicleFormStatus' => 'status',
        ]);

        try {
            $saveVehicle->handle(null, [
                'registration_no' => $this->vehicleFormRegistrationNo,
                'make' => $this->vehicleFormMake !== '' ? $this->vehicleFormMake : null,
                'model' => $this->vehicleFormModel !== '' ? $this->vehicleFormModel : null,
                'capacity' => (int) $this->vehicleFormCapacity,
                'status' => VehicleStatus::from($this->vehicleFormStatus),
                'insurance_expires_on' => $this->vehicleFormInsuranceExpiresOn !== '' ? $this->vehicleFormInsuranceExpiresOn : null,
                'inspection_expires_on' => $this->vehicleFormInspectionExpiresOn !== '' ? $this->vehicleFormInspectionExpiresOn : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('vehicleForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('vehicleFormRegistrationNo', $e->getMessage());

            return;
        }

        $this->reset([
            'showVehicleForm', 'vehicleFormRegistrationNo', 'vehicleFormMake',
            'vehicleFormModel', 'vehicleFormCapacity', 'vehicleFormStatus',
            'vehicleFormInsuranceExpiresOn', 'vehicleFormInspectionExpiresOn',
        ]);
        $this->vehicleFormStatus = 'operational';
        $this->tab = 'vehicles';
        $this->resetPage();
        session()->flash('status', 'Vehicle saved.');
    }

    public function endAllocation(int $allocationId, EndTransportAllocation $endTransportAllocation): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        try {
            // `ends_on` is a DATE column; Carbon::now() was silently truncated.
            $endTransportAllocation->handle($allocationId, Carbon::today(), $this->actor());
        } catch (DomainException $e) {
            session()->flash('status', $e->getMessage());

            return;
        }

        $this->resetPage();
        session()->flash('status', 'Allocation ended.');
    }

    public function toggleTripLogForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showTripLogForm = ! $this->showTripLogForm;

        if ($this->showTripLogForm && $this->tripLogFormDate === '') {
            $this->tripLogFormDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveTripLog(RecordTripLog $recordTripLog): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'tripLogFormVehicleId' => ['required', 'integer', 'min:1'],
            'tripLogFormDriverId' => ['nullable', 'integer', 'min:1'],
            'tripLogFormRouteId' => ['nullable', 'integer', 'min:1'],
            'tripLogFormDate' => ['required', 'date'],
            'tripLogFormOdometerStart' => ['required', 'integer', 'min:0'],
            'tripLogFormOdometerEnd' => ['required', 'integer', 'min:0'],
            'tripLogFormNotes' => ['nullable', 'string', 'max:500'],
        ], [], [
            'tripLogFormVehicleId' => 'vehicle',
            'tripLogFormDriverId' => 'driver',
            'tripLogFormRouteId' => 'route',
            'tripLogFormDate' => 'date',
            'tripLogFormOdometerStart' => 'odometer start',
            'tripLogFormOdometerEnd' => 'odometer end',
            'tripLogFormNotes' => 'notes',
        ]);

        try {
            $recordTripLog->handle([
                'vehicle_id' => (int) $this->tripLogFormVehicleId,
                'driver_id' => $this->tripLogFormDriverId !== '' ? (int) $this->tripLogFormDriverId : null,
                'route_id' => $this->tripLogFormRouteId !== '' ? (int) $this->tripLogFormRouteId : null,
                'date' => $this->tripLogFormDate,
                'odometer_start' => (int) $this->tripLogFormOdometerStart,
                'odometer_end' => (int) $this->tripLogFormOdometerEnd,
                'notes' => $this->tripLogFormNotes !== '' ? $this->tripLogFormNotes : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('tripLogForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('tripLogFormOdometerEnd', $e->getMessage());

            return;
        }

        $this->reset([
            'showTripLogForm', 'tripLogFormVehicleId', 'tripLogFormDriverId', 'tripLogFormRouteId',
            'tripLogFormDate', 'tripLogFormOdometerStart', 'tripLogFormOdometerEnd', 'tripLogFormNotes',
        ]);
        $this->tab = 'logs';
        $this->logType = 'trips';
        $this->resetPage();
        session()->flash('status', 'Trip log recorded.');
    }

    public function toggleFuelLogForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showFuelLogForm = ! $this->showFuelLogForm;

        if ($this->showFuelLogForm && $this->fuelLogFormDate === '') {
            $this->fuelLogFormDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveFuelLog(RecordFuelLog $recordFuelLog): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'fuelLogFormVehicleId' => ['required', 'integer', 'min:1'],
            'fuelLogFormDate' => ['required', 'date'],
            'fuelLogFormLitres' => ['required', 'numeric', 'gt:0'],
            'fuelLogFormCostAmount' => ['required', 'integer', 'min:0'],
            'fuelLogFormOdometer' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'fuelLogFormVehicleId' => 'vehicle',
            'fuelLogFormDate' => 'date',
            'fuelLogFormLitres' => 'litres',
            'fuelLogFormCostAmount' => 'cost',
            'fuelLogFormOdometer' => 'odometer',
        ]);

        try {
            $recordFuelLog->handle([
                'vehicle_id' => (int) $this->fuelLogFormVehicleId,
                'date' => $this->fuelLogFormDate,
                'litres' => $this->fuelLogFormLitres,
                'cost_amount' => (int) $this->fuelLogFormCostAmount,
                'odometer' => $this->fuelLogFormOdometer !== '' ? (int) $this->fuelLogFormOdometer : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('fuelLogForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('fuelLogFormLitres', $e->getMessage());

            return;
        }

        $this->reset([
            'showFuelLogForm', 'fuelLogFormVehicleId', 'fuelLogFormDate',
            'fuelLogFormLitres', 'fuelLogFormCostAmount', 'fuelLogFormOdometer',
        ]);
        $this->tab = 'logs';
        $this->logType = 'fuel';
        $this->resetPage();
        session()->flash('status', 'Fuel log recorded.');
    }

    public function toggleMaintenanceLogForm(): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->showMaintenanceLogForm = ! $this->showMaintenanceLogForm;

        if ($this->showMaintenanceLogForm && $this->maintenanceLogFormDate === '') {
            $this->maintenanceLogFormDate = Carbon::now()->format('Y-m-d');
        }
    }

    public function saveMaintenanceLog(RecordMaintenanceLog $recordMaintenanceLog): void
    {
        Gate::authorize(TransportPermission::MANAGE);

        $this->validate([
            'maintenanceLogFormVehicleId' => ['required', 'integer', 'min:1'],
            'maintenanceLogFormType' => ['required', 'string', 'in:service,repair,inspection,other'],
            'maintenanceLogFormDate' => ['required', 'date'],
            'maintenanceLogFormDescription' => ['required', 'string', 'max:500'],
            'maintenanceLogFormCostAmount' => ['nullable', 'integer', 'min:0'],
        ], [], [
            'maintenanceLogFormVehicleId' => 'vehicle',
            'maintenanceLogFormType' => 'type',
            'maintenanceLogFormDate' => 'date',
            'maintenanceLogFormDescription' => 'description',
            'maintenanceLogFormCostAmount' => 'cost',
        ]);

        try {
            $recordMaintenanceLog->handle([
                'vehicle_id' => (int) $this->maintenanceLogFormVehicleId,
                'type' => VehicleMaintenanceType::from($this->maintenanceLogFormType),
                'date' => $this->maintenanceLogFormDate,
                'description' => $this->maintenanceLogFormDescription,
                'cost_amount' => $this->maintenanceLogFormCostAmount !== '' ? (int) $this->maintenanceLogFormCostAmount : null,
            ], $this->actor());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('maintenanceLogForm'.str_replace(' ', '', ucwords(str_replace('_', ' ', $field))), (string) ($messages[0] ?? 'Invalid value.'));
            }

            return;
        } catch (DomainException $e) {
            $this->addError('maintenanceLogFormDescription', $e->getMessage());

            return;
        }

        $this->reset([
            'showMaintenanceLogForm', 'maintenanceLogFormVehicleId', 'maintenanceLogFormType',
            'maintenanceLogFormDescription', 'maintenanceLogFormCostAmount',
            'maintenanceLogFormDate',
        ]);
        $this->maintenanceLogFormType = 'service';
        $this->tab = 'logs';
        $this->logType = 'maintenance';
        $this->resetPage();
        session()->flash('status', 'Maintenance log recorded.');
    }

    private function actor(): \App\Support\Audit\Actor
    {
        /** @var \App\Modules\Identity\Models\User $user */
        $user = auth()->user();

        return $user->toAuditActor();
    }

    /**
     * @return list<array{id: int, route_id: int, name: string}>
     */
    private function stopOptions(): array
    {
        $options = [];

        foreach (DB::table('transport_stops')->orderBy('route_id')->orderBy('sequence')->get(['id', 'route_id', 'name']) as $row) {
            /** @var object{id: int|string, route_id: int|string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'route_id' => (int) $row->route_id, 'name' => $row->name];
        }

        return $options;
    }

    private function resetPage(): void
    {
        $this->page = 1;
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function rows(): LengthAwarePaginator
    {
        return match ($this->tab) {
            'vehicles' => $this->vehicleRows(),
            'allocations' => $this->allocationRows(),
            'logs' => $this->logRows(),
            default => $this->routeRows(),
        };
    }

    /**
     * Routes with their stop count, active rider count and first pickup
     * time - the "Active Routes Overview" table of the mockup.
     *
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function routeRows(): LengthAwarePaginator
    {
        $query = DB::table('transport_routes as tr')
            ->when($this->route !== '', fn ($q) => $q->where('tr.id', (int) $this->route))
            ->when($this->status !== '', function ($q): void {
                $q->where('tr.is_active', $this->status === 'active');
            })
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('tr.name', 'like', '%'.$this->search.'%')
                        ->orWhere('tr.code', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('tr.code')
            ->select([
                'tr.id', 'tr.code', 'tr.name', 'tr.is_active',
            ])
            ->selectSub(
                DB::table('transport_stops')->whereColumn('route_id', 'tr.id')->selectRaw('COUNT(*)'),
                'stops_count'
            )
            ->selectSub(
                DB::table('transport_allocations')
                    ->whereColumn('route_id', 'tr.id')
                    ->where('status', 'active')
                    ->selectRaw('COUNT(*)'),
                'riders_count'
            )
            ->selectSub(
                DB::table('transport_stops')->whereColumn('route_id', 'tr.id')->selectRaw('MIN(pickup_time)'),
                'first_pickup'
            );

        return $query->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function vehicleRows(): LengthAwarePaginator
    {
        return DB::table('vehicles as v')
            ->when($this->vehicle !== '', fn ($q) => $q->where('v.id', (int) $this->vehicle))
            ->when($this->status !== '', fn ($q) => $q->where('v.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('v.registration_no', 'like', '%'.$this->search.'%')
                        ->orWhere('v.make', 'like', '%'.$this->search.'%')
                        ->orWhere('v.model', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('v.registration_no')
            ->select([
                'v.id', 'v.registration_no', 'v.make', 'v.model', 'v.capacity',
                'v.status', 'v.insurance_expires_on', 'v.inspection_expires_on',
            ])
            ->selectSub(
                DB::table('vehicle_drivers')
                    ->whereColumn('vehicle_id', 'v.id')
                    ->whereNull('active_to')
                    ->orderByDesc('active_from')
                    ->limit(1)
                    ->select('name'),
                'driver_name'
            )
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function allocationRows(): LengthAwarePaginator
    {
        return DB::table('transport_allocations as ta')
            ->join('transport_routes as tr', 'tr.id', '=', 'ta.route_id')
            ->join('transport_stops as ts', 'ts.id', '=', 'ta.stop_id')
            ->join('enrollments as e', 'e.id', '=', 'ta.enrollment_id')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->when($this->route !== '', fn ($q) => $q->where('ta.route_id', (int) $this->route))
            ->when($this->status !== '', fn ($q) => $q->where('ta.status', $this->status))
            ->when($this->search !== '', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where('s.first_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.last_name', 'like', '%'.$this->search.'%')
                        ->orWhere('s.matricule', 'like', '%'.$this->search.'%');
                });
            })
            ->orderBy('tr.code')
            ->orderBy('ts.sequence')
            ->orderBy('s.last_name')
            ->select([
                'ta.id', 'ta.direction', 'ta.starts_on', 'ta.ends_on', 'ta.status',
                'tr.code as route_code', 'tr.name as route_name',
                'ts.name as stop_name', 'ts.sequence as stop_sequence',
                's.first_name', 's.last_name', 's.matricule',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * @return LengthAwarePaginator<int, \stdClass>
     */
    private function logRows(): LengthAwarePaginator
    {
        if ($this->logType === 'fuel') {
            return DB::table('vehicle_fuel_logs as l')
                ->join('vehicles as v', 'v.id', '=', 'l.vehicle_id')
                ->when($this->vehicle !== '', fn ($q) => $q->where('l.vehicle_id', (int) $this->vehicle))
                ->orderByDesc('l.date')->orderByDesc('l.id')
                ->select([
                    'l.id', 'l.date', 'l.litres', 'l.cost_amount', 'l.odometer',
                    'v.registration_no',
                ])
                ->paginate($this->perPage, page: $this->page);
        }

        if ($this->logType === 'maintenance') {
            return DB::table('vehicle_maintenance_logs as l')
                ->join('vehicles as v', 'v.id', '=', 'l.vehicle_id')
                ->when($this->vehicle !== '', fn ($q) => $q->where('l.vehicle_id', (int) $this->vehicle))
                ->orderByDesc('l.date')->orderByDesc('l.id')
                ->select([
                    'l.id', 'l.date', 'l.type', 'l.description', 'l.cost_amount',
                    'v.registration_no',
                ])
                ->paginate($this->perPage, page: $this->page);
        }

        return DB::table('vehicle_trip_logs as l')
            ->join('vehicles as v', 'v.id', '=', 'l.vehicle_id')
            ->leftJoin('transport_routes as tr', 'tr.id', '=', 'l.route_id')
            ->leftJoin('vehicle_drivers as d', 'd.id', '=', 'l.driver_id')
            ->when($this->vehicle !== '', fn ($q) => $q->where('l.vehicle_id', (int) $this->vehicle))
            ->when($this->route !== '', fn ($q) => $q->where('l.route_id', (int) $this->route))
            ->orderByDesc('l.date')->orderByDesc('l.id')
            ->select([
                'l.id', 'l.date', 'l.odometer_start', 'l.odometer_end', 'l.notes',
                'v.registration_no', 'tr.name as route_name', 'd.name as driver_name',
            ])
            ->paginate($this->perPage, page: $this->page);
    }

    /**
     * The mockup's five KPI cards, dataset-wide (never filter-dependent
     * inventions).
     *
     * @return array{buses: int, active_routes: int, students: int, trips: int, maintenance_due: int}
     */
    private function kpis(): array
    {
        $soon = Carbon::today()->addDays(30)->toDateString();

        return [
            'buses' => (int) DB::table('vehicles')->count(),
            'active_routes' => (int) DB::table('transport_routes')->where('is_active', true)->count(),
            'students' => (int) DB::table('transport_allocations')->where('status', 'active')->count(),
            'trips' => (int) DB::table('vehicle_trip_logs')->count(),
            'maintenance_due' => (int) DB::table('vehicles')
                ->where(function ($q) use ($soon): void {
                    $q->where('status', VehicleStatus::UnderMaintenance->value)
                        ->orWhere('insurance_expires_on', '<=', $soon)
                        ->orWhere('inspection_expires_on', '<=', $soon);
                })
                ->count(),
        ];
    }

    /**
     * The rail's "Vehicle Status" breakdown.
     *
     * @return list<array{status: string, count: int, share: int}>
     */
    private function vehicleStatusBreakdown(): array
    {
        $counts = [];
        $total = 0;

        $rows = DB::table('vehicles')
            ->groupBy('status')
            ->orderBy('status')
            ->get([DB::raw('status'), DB::raw('COUNT(*) as n')]);

        foreach ($rows as $row) {
            /** @var object{status: string, n: int|string} $row */
            $counts[$row->status] = (int) $row->n;
            $total += (int) $row->n;
        }

        $breakdown = [];

        foreach (VehicleStatus::cases() as $case) {
            $count = $counts[$case->value] ?? 0;
            $breakdown[] = [
                'status' => $case->value,
                'count' => $count,
                'share' => $total > 0 ? (int) round($count * 100 / $total) : 0,
            ];
        }

        return $breakdown;
    }

    /**
     * The rail's "Upcoming Maintenance": nearest insurance/inspection
     * expiries in the next 60 days.
     *
     * @return list<array{registration_no: string, kind: string, due_on: string}>
     */
    private function upcomingMaintenance(): array
    {
        $horizon = Carbon::today()->addDays(60)->toDateString();

        $rows = DB::table('vehicles')
            ->where(function ($q) use ($horizon): void {
                $q->where('insurance_expires_on', '<=', $horizon)
                    ->orWhere('inspection_expires_on', '<=', $horizon);
            })
            ->orderByRaw('LEAST(COALESCE(insurance_expires_on, "9999-12-31"), COALESCE(inspection_expires_on, "9999-12-31"))')
            ->limit(5)
            ->get(['registration_no', 'insurance_expires_on', 'inspection_expires_on']);

        $due = [];

        foreach ($rows as $row) {
            /** @var object{registration_no: string, insurance_expires_on: string|null, inspection_expires_on: string|null} $row */
            $insurance = $row->insurance_expires_on;
            $inspection = $row->inspection_expires_on;

            if ($insurance !== null && $insurance <= $horizon) {
                $due[] = ['registration_no' => $row->registration_no, 'kind' => 'Insurance renewal', 'due_on' => $insurance];
            }

            if ($inspection !== null && $inspection <= $horizon) {
                $due[] = ['registration_no' => $row->registration_no, 'kind' => 'Technical inspection', 'due_on' => $inspection];
            }
        }

        usort($due, static fn (array $a, array $b): int => $a['due_on'] <=> $b['due_on']);

        return array_slice($due, 0, 5);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function routeOptions(): array
    {
        $options = [];

        foreach (DB::table('transport_routes')->orderBy('code')->get(['id', 'code', 'name']) as $row) {
            /** @var object{id: int|string, code: string, name: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->code.' - '.$row->name];
        }

        return $options;
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function vehicleOptions(): array
    {
        $options = [];

        foreach (DB::table('vehicles')->orderBy('registration_no')->get(['id', 'registration_no']) as $row) {
            /** @var object{id: int|string, registration_no: string} $row */
            $options[] = ['id' => (int) $row->id, 'name' => $row->registration_no];
        }

        return $options;
    }

    /**
     * Per-tab status filter choices (the WORD carries the meaning, 09-ui 10).
     *
     * @return list<array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return match ($this->tab) {
            'vehicles' => [
                ['value' => 'operational', 'label' => 'Operational'],
                ['value' => 'under_maintenance', 'label' => 'Under maintenance'],
                ['value' => 'out_of_service', 'label' => 'Out of service'],
            ],
            'allocations' => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'ended', 'label' => 'Ended'],
            ],
            'logs' => [],
            default => [
                ['value' => 'active', 'label' => 'Active'],
                ['value' => 'inactive', 'label' => 'Inactive'],
            ],
        };
    }

    public function render(): mixed
    {
        $tabCounts = [
            'routes' => (int) DB::table('transport_routes')->count(),
            'vehicles' => (int) DB::table('vehicles')->count(),
            'allocations' => (int) DB::table('transport_allocations')->where('status', 'active')->count(),
            'logs' => (int) DB::table('vehicle_trip_logs')->count()
                + (int) DB::table('vehicle_fuel_logs')->count()
                + (int) DB::table('vehicle_maintenance_logs')->count(),
        ];

        return view('livewire.welfare.transport.index', [
            'rows' => $this->rows(),
            'kpis' => $this->kpis(),
            'tabCounts' => $tabCounts,
            'routeOptions' => $this->routeOptions(),
            'vehicleOptions' => $this->vehicleOptions(),
            'stopOptions' => $this->stopOptions(),
            'statusOptions' => $this->statusOptions(),
            'vehicleStatusBreakdown' => $this->vehicleStatusBreakdown(),
            'upcomingMaintenance' => $this->upcomingMaintenance(),
            // Every toggle*/save*/endAllocation method authorizes MANAGE, so a
            // view-only user was being shown buttons that 403 on click. The
            // hostel screen already guards its actions this way.
            'canManage' => Gate::allows(TransportPermission::MANAGE),
        ]);
    }
}
