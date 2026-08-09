<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetMaintenanceRequest;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.4 - opens a maintenance request. asset_id may be
 * NULL (a request can precede identification of the asset), and the
 * mockup's Inventory-screen entry point supplies inventory_item_id
 * instead. The accounting consequence is decided ONLY at close
 * (CloseMaintenanceRequest), never here.
 */
final class CreateMaintenanceRequest
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(array $data, Actor $actor): AssetMaintenanceRequest
    {
        Gate::authorize(AssetPermission::MANAGE);

        if (trim((string) ($data['title'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'title' => 'A maintenance request requires a title.',
            ]);
        }

        return DB::transaction(function () use ($data, $actor): AssetMaintenanceRequest {
            $idempotencyKey = isset($data['idempotency_key']) ? (string) $data['idempotency_key'] : null;

            if ($idempotencyKey !== null) {
                /** @var AssetMaintenanceRequest|null $existing */
                $existing = AssetMaintenanceRequest::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            if (($data['asset_id'] ?? null) !== null) {
                /** @var Asset $asset */
                $asset = Asset::query()->findOrFail((int) $data['asset_id']);

                if ($asset->status->isFrozen()) {
                    throw new DomainException(
                        "A12: asset '{$asset->tag_number}' is {$asset->status->value}; no maintenance can be requested."
                    );
                }
            }

            /** @var AssetMaintenanceRequest $request */
            $request = AssetMaintenanceRequest::query()->create([
                'asset_id' => $data['asset_id'] ?? null,
                'inventory_item_id' => $data['inventory_item_id'] ?? null,
                'title' => (string) $data['title'],
                'description' => $data['description'] ?? null,
                'reported_by' => $actor->id,
                'reported_at' => $data['reported_at'] ?? now(),
                'priority' => (string) ($data['priority'] ?? 'medium'),
                'status' => 'open',
                'assigned_to_staff_id' => $data['assigned_to_staff_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'estimated_cost' => $data['estimated_cost'] ?? null,
                'idempotency_key' => $idempotencyKey,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Assets',
                auditableType: AssetMaintenanceRequest::class,
                auditableId: (int) $request->getKey(),
                after: [
                    'title' => (string) $data['title'],
                    'asset_id' => $data['asset_id'] ?? null,
                    'priority' => (string) ($data['priority'] ?? 'medium'),
                ],
                actor: $actor,
            );

            return $request;
        });
    }
}
