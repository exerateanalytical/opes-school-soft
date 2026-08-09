<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportRoute;
use App\Modules\Welfare\Models\Vehicle;
use App\Modules\Welfare\Models\VehicleDriver;
use App\Modules\Welfare\Models\VehicleTripLog;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W1). Records one completed run. Operational
 * record only - never posts (fuel/maintenance money is Phase 5's).
 */
final class RecordTripLog
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): VehicleTripLog
    {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): VehicleTripLog {
            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->findOrFail((int) ($data['vehicle_id'] ?? 0));

            $start = (int) ($data['odometer_start'] ?? -1);
            $end = (int) ($data['odometer_end'] ?? -1);

            if ($start < 0 || $end < 0 || $end < $start) {
                throw ValidationException::withMessages([
                    'odometer_end' => 'The odometer only turns one way: end must be >= start, both non-negative.',
                ]);
            }

            // Sanity against the vehicle's own history: a trip may not start
            // behind the furthest odometer reading already logged.
            $furthest = (int) VehicleTripLog::query()
                ->where('vehicle_id', $vehicle->getKey())
                ->max('odometer_end');

            if ($start < $furthest) {
                throw ValidationException::withMessages([
                    'odometer_start' => "Odometer start {$start} is behind the last logged reading {$furthest} for this vehicle.",
                ]);
            }

            if (($data['route_id'] ?? null) !== null) {
                TransportRoute::query()->findOrFail((int) $data['route_id']);
            }

            if (($data['driver_id'] ?? null) !== null) {
                /** @var VehicleDriver $driver */
                $driver = VehicleDriver::query()->findOrFail((int) $data['driver_id']);

                if ($driver->vehicle_id !== (int) $vehicle->getKey()) {
                    throw ValidationException::withMessages([
                        'driver_id' => 'The driver is not assigned to this vehicle.',
                    ]);
                }
            }

            $log = VehicleTripLog::query()->create([
                'vehicle_id' => (int) $vehicle->getKey(),
                'route_id' => isset($data['route_id']) ? (int) $data['route_id'] : null,
                'driver_id' => isset($data['driver_id']) ? (int) $data['driver_id'] : null,
                'date' => $data['date'] ?? null,
                'odometer_start' => $start,
                'odometer_end' => $end,
                'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: VehicleTripLog::class,
                auditableId: (int) $log->getKey(),
                after: [
                    'vehicle' => $vehicle->registration_no,
                    'date' => (string) ($data['date'] ?? ''),
                    'odometer_start' => $start,
                    'odometer_end' => $end,
                ],
                actor: $actor,
            );

            return $log->refresh();
        });
    }
}
