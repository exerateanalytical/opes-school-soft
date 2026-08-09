<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Livewire\Transport;

use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Domain\VehicleStatus;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            'statusOptions' => $this->statusOptions(),
            'vehicleStatusBreakdown' => $this->vehicleStatusBreakdown(),
            'upcomingMaintenance' => $this->upcomingMaintenance(),
        ]);
    }
}
