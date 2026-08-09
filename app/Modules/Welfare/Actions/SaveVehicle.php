<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Domain\VehicleStatus;
use App\Modules\Welfare\Models\Vehicle;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W1). Creates or updates a fleet vehicle.
 *
 * `asset_id` is accepted as a bare integer and, when the Phase 9 asset
 * register is present, verified to exist via DB::table - never through the
 * Assets Models (ModuleBoundaryTest). No ledger contact: buying, running
 * and depreciating the bus are Phase 5/9 concerns.
 */
final class SaveVehicle
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $vehicleId, array $data, Actor $actor): Vehicle
    {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use ($vehicleId, $data, $actor): Vehicle {
            $existing = null;

            if ($vehicleId !== null) {
                /** @var Vehicle $existing */
                $existing = Vehicle::query()->lockForUpdate()->findOrFail($vehicleId);
            }

            $this->validate($data, $existing);

            if ($existing !== null) {
                $existing->fill($data)->save();
                $vehicle = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $vehicle = Vehicle::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            // Hydrate DB defaults (status) before reading them for the audit
            // line: create() only knows the attributes it was given.
            $vehicle->refresh();

            $this->audit->handle(
                action: $auditAction,
                module: 'Welfare',
                auditableType: Vehicle::class,
                auditableId: (int) $vehicle->getKey(),
                after: [
                    'registration_no' => $vehicle->registration_no,
                    'capacity' => $vehicle->capacity,
                    'status' => $vehicle->status->value,
                ],
                actor: $actor,
            );

            return $vehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, ?Vehicle $existing): void
    {
        if ($existing === null && trim((string) ($data['registration_no'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'registration_no' => 'A vehicle requires a registration number.',
            ]);
        }

        if (array_key_exists('registration_no', $data)) {
            $clash = Vehicle::query()
                ->where('registration_no', (string) $data['registration_no'])
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'registration_no' => 'A vehicle with this registration number already exists.',
                ]);
            }
        }

        $capacity = $existing === null
            ? ($data['capacity'] ?? null)
            : ($data['capacity'] ?? $existing->capacity);

        if (! is_numeric($capacity) || (int) $capacity < 1) {
            throw ValidationException::withMessages([
                'capacity' => 'Vehicle capacity must be at least one seat.',
            ]);
        }

        $status = $data['status'] ?? null;

        if ($status !== null && ! $status instanceof VehicleStatus
            && VehicleStatus::tryFrom((string) $status) === null) {
            throw ValidationException::withMessages([
                'status' => 'Unknown vehicle status.',
            ]);
        }

        if (($data['asset_id'] ?? null) !== null) {
            // The FK is deliberately absent (phase-10 plan §1), so the
            // existence check lives here. Cross-module READ via DB::table.
            $exists = DB::table('assets')->where('id', (int) $data['asset_id'])->exists();

            if (! $exists) {
                throw ValidationException::withMessages([
                    'asset_id' => 'The referenced asset-register entry does not exist.',
                ]);
            }
        }
    }
}
