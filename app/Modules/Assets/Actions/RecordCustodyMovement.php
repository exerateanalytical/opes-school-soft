<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCustodyMovement;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.3 - appends a custody movement and rolls the
 * asset's current custodian/location forward. No accounting effect. The
 * from-side is ALWAYS what the register currently says, never
 * caller-supplied - the trail must join up.
 *
 * A12: a disposed/written-off/lost asset refuses new movements.
 */
final class RecordCustodyMovement
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(int $assetId, array $data, Actor $actor): AssetCustodyMovement
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($assetId, $data, $actor): AssetCustodyMovement {
            $idempotencyKey = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var AssetCustodyMovement|null $existing */
                $existing = AssetCustodyMovement::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Asset $asset */
            $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);

            if ($asset->status->isFrozen()) {
                throw new DomainException(
                    "A12: asset '{$asset->tag_number}' is {$asset->status->value} and refuses every mutating action."
                );
            }

            $toStaffId = isset($data['to_staff_id']) ? (int) $data['to_staff_id'] : null;
            $toLocationId = isset($data['to_location_id']) ? (int) $data['to_location_id'] : null;

            if ($toStaffId === null && $toLocationId === null) {
                throw ValidationException::withMessages([
                    'to_staff_id' => 'A custody movement must name a new custodian, a new location, or both.',
                ]);
            }

            if (trim((string) ($data['reason'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A custody movement requires a reason.',
                ]);
            }

            /** @var AssetCustodyMovement $movement */
            $movement = AssetCustodyMovement::query()->create([
                'asset_id' => (int) $asset->getKey(),
                'from_staff_id' => $asset->custodian_staff_id,
                'to_staff_id' => $toStaffId,
                'from_location_id' => $asset->location_id,
                'to_location_id' => $toLocationId,
                'moved_on' => (string) $data['moved_on'],
                'reason' => (string) $data['reason'],
                'document_ref' => $data['document_ref'] ?? null,
                'recorded_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
            ]);

            $asset->forceFill([
                'custodian_staff_id' => $toStaffId ?? $asset->custodian_staff_id,
                'location_id' => $toLocationId ?? $asset->location_id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: AssetCustodyMovement::class,
                auditableId: (int) $movement->getKey(),
                after: [
                    'asset_id' => (int) $asset->getKey(),
                    'to_staff_id' => $toStaffId,
                    'to_location_id' => $toLocationId,
                    'moved_on' => (string) $data['moved_on'],
                ],
                actor: $actor,
            );

            return $movement;
        });
    }

    /**
     * The single legal update on the append-only trail: acknowledging
     * receipt. The schema trigger enforces that nothing else changes and
     * that an acknowledgement is never overwritten.
     */
    public function acknowledge(int $movementId, Actor $actor): AssetCustodyMovement
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($movementId, $actor): AssetCustodyMovement {
            /** @var AssetCustodyMovement $movement */
            $movement = AssetCustodyMovement::query()->lockForUpdate()->findOrFail($movementId);

            if ($movement->acknowledged_at !== null) {
                return $movement;
            }

            $movement->forceFill([
                'acknowledged_at' => now(),
                'acknowledged_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: AssetCustodyMovement::class,
                auditableId: (int) $movement->getKey(),
                after: ['event' => 'acknowledged'],
                actor: $actor,
            );

            return $movement;
        });
    }
}
