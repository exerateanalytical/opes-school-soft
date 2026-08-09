<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\Vehicle;
use App\Modules\Welfare\Models\VehicleFuelLog;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W1). Records a fuel purchase. The cost is
 * INFORMATIONAL - the payable posts through the Phase 5 supplier invoice
 * flow; writing the ledger from here would be a second posting path and a
 * review-blocking defect (phase-10 plan §1).
 */
final class RecordFuelLog
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): VehicleFuelLog
    {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): VehicleFuelLog {
            /** @var Vehicle $vehicle */
            $vehicle = Vehicle::query()->findOrFail((int) ($data['vehicle_id'] ?? 0));

            $litres = $data['litres'] ?? null;

            if (! is_numeric($litres) || (float) $litres <= 0) {
                throw ValidationException::withMessages([
                    'litres' => 'A fuel log requires a positive number of litres.',
                ]);
            }

            $cost = $data['cost_amount'] ?? null;

            if (! is_numeric($cost) || (int) $cost < 0) {
                throw ValidationException::withMessages([
                    'cost_amount' => 'The fuel cost must be zero or more (XAF integer).',
                ]);
            }

            $log = VehicleFuelLog::query()->create([
                'vehicle_id' => (int) $vehicle->getKey(),
                'date' => $data['date'] ?? null,
                'litres' => (string) $litres,
                'cost_amount' => (int) $cost,
                'odometer' => isset($data['odometer']) ? (int) $data['odometer'] : null,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: VehicleFuelLog::class,
                auditableId: (int) $log->getKey(),
                after: [
                    'vehicle' => $vehicle->registration_no,
                    'date' => (string) ($data['date'] ?? ''),
                    'litres' => (string) $litres,
                    'cost_amount' => (int) $cost,
                ],
                actor: $actor,
            );

            return $log->refresh();
        });
    }
}
