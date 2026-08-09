<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Domain\VehicleMaintenanceType;
use App\Modules\Welfare\Models\Vehicle;
use App\Modules\Welfare\Models\VehicleMaintenanceLog;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W1). Records maintenance work on a vehicle.
 * `supplier_id` is verified against the Phase 5 suppliers table via
 * DB::table (cross-module READ only). Cost is informational; the payable
 * posts through the supplier invoice, never from here.
 */
final class RecordMaintenanceLog
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): VehicleMaintenanceLog
    {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): VehicleMaintenanceLog {
            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->findOrFail((int) ($data['vehicle_id'] ?? 0));

            $typeRaw = $data['type'] ?? '';
            $type = $typeRaw instanceof VehicleMaintenanceType
                ? $typeRaw
                : VehicleMaintenanceType::tryFrom((string) $typeRaw);

            if ($type === null) {
                throw ValidationException::withMessages([
                    'type' => 'Unknown maintenance type.',
                ]);
            }

            if (trim((string) ($data['description'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'description' => 'A maintenance log requires a description of the work.',
                ]);
            }

            $cost = $data['cost_amount'] ?? null;

            if ($cost !== null && (! is_numeric($cost) || (int) $cost < 0)) {
                throw ValidationException::withMessages([
                    'cost_amount' => 'The maintenance cost must be zero or more (XAF integer).',
                ]);
            }

            if (($data['supplier_id'] ?? null) !== null) {
                $exists = DB::table('suppliers')->where('id', (int) $data['supplier_id'])->exists();

                if (! $exists) {
                    throw ValidationException::withMessages([
                        'supplier_id' => 'The referenced supplier does not exist.',
                    ]);
                }
            }

            $log = VehicleMaintenanceLog::query()->create([
                'vehicle_id' => (int) $vehicle->getKey(),
                'date' => $data['date'] ?? null,
                'type' => $type,
                'description' => (string) $data['description'],
                'cost_amount' => $cost === null ? null : (int) $cost,
                'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: VehicleMaintenanceLog::class,
                auditableId: (int) $log->getKey(),
                after: [
                    'vehicle' => $vehicle->registration_no,
                    'date' => (string) ($data['date'] ?? ''),
                    'type' => $type->value,
                    'cost_amount' => $cost === null ? null : (int) $cost,
                ],
                actor: $actor,
            );

            return $log->refresh();
        });
    }
}
