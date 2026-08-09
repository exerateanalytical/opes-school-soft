<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\MaintenanceResolution;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetMaintenanceRequest;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.4 accounting rule - closing a maintenance request
 * demands the operator's EXPLICIT expense-vs-capitalise choice with a
 * recorded justification; the Action never infers from amount.
 *
 *  - `expense`: the operational default; the cost stays where the Phase 5
 *    supplier invoice put it. Nothing else changes.
 *  - `capitalise` + `increase_cost`: the work extended the asset's life or
 *    capacity - acquisition_cost rises by actual_cost, with a prospective
 *    useful-life review left to ChangeDepreciationEstimate (§5.5).
 *  - `capitalise` + `component`: the work created a distinct part - a
 *    child asset is registered under A10/A11 discipline.
 *
 * The ledger reclassification (6xx -> 2xx) itself belongs to the invoice
 * document flow; this Action owns the register's truth.
 */
final class CloseMaintenanceRequest
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $options  actual_cost, capitalise_as ('increase_cost'|'component'), component_name, useful_life_months, supplier_invoice_id
     */
    public function handle(
        int $requestId,
        MaintenanceResolution $resolution,
        string $justification,
        Actor $actor,
        array $options = [],
    ): AssetMaintenanceRequest {
        Gate::authorize(AssetPermission::MANAGE);

        if (trim($justification) === '') {
            throw ValidationException::withMessages([
                'justification' => 'Closing a maintenance request requires a recorded justification (§2.4).',
            ]);
        }

        return DB::transaction(function () use ($requestId, $resolution, $justification, $actor, $options): AssetMaintenanceRequest {
            /** @var AssetMaintenanceRequest $request */
            $request = AssetMaintenanceRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status->isClosed()) {
                return $request;
            }

            $actualCost = isset($options['actual_cost']) ? (int) $options['actual_cost'] : null;

            if ($resolution === MaintenanceResolution::Capitalise) {
                if ($request->asset_id === null) {
                    throw new DomainException(
                        'A capitalising close requires the request to name its asset.'
                    );
                }

                if ($actualCost === null || $actualCost <= 0) {
                    throw ValidationException::withMessages([
                        'actual_cost' => 'A capitalising close requires a positive actual cost.',
                    ]);
                }

                $mode = (string) ($options['capitalise_as'] ?? 'increase_cost');

                /** @var Asset $asset */
                $asset = Asset::query()->lockForUpdate()->findOrFail($request->asset_id);

                if ($asset->status->isFrozen()) {
                    throw new DomainException(
                        "A12: asset '{$asset->tag_number}' is {$asset->status->value} and refuses every mutating action."
                    );
                }

                if ($mode === 'component') {
                    $componentName = trim((string) ($options['component_name'] ?? ''));

                    if ($componentName === '') {
                        throw ValidationException::withMessages([
                            'component_name' => 'A component capitalisation requires the component name.',
                        ]);
                    }

                    Asset::query()->create([
                        'tag_number' => sprintf('AST/%06d', $this->sequences->allocate('asset_tag')),
                        'asset_category_id' => $asset->asset_category_id,
                        'parent_asset_id' => (int) $asset->getKey(),
                        'name' => $componentName,
                        'status' => $asset->status,
                        'acquisition_date' => now()->toDateString(),
                        'acquisition_cost' => $actualCost,
                        'cost_basis' => $asset->cost_basis,
                        'residual_value' => 0,
                        'in_service_date' => $asset->in_service_date,
                        'depreciation_start_date' => $asset->depreciation_start_date,
                        'useful_life_months' => $options['useful_life_months'] ?? $asset->useful_life_months,
                        'depreciation_method' => $asset->depreciation_method,
                        'prorata_convention' => $asset->prorata_convention,
                        'acquisition_type' => $asset->acquisition_type,
                        'supplier_id' => $request->supplier_id,
                        'supplier_invoice_id' => $options['supplier_invoice_id'] ?? null,
                        'location_id' => $asset->location_id,
                        'custodian_staff_id' => $asset->custodian_staff_id,
                        'school_section_id' => $asset->school_section_id,
                        'fiscal_year_id' => $asset->fiscal_year_id,
                        'academic_year_id' => $asset->academic_year_id,
                    ]);
                } elseif ($mode === 'increase_cost') {
                    $asset->acquisition_cost += $actualCost;
                    $asset->save();
                } else {
                    throw ValidationException::withMessages([
                        'capitalise_as' => "Unknown capitalisation mode '{$mode}'; use 'increase_cost' or 'component'.",
                    ]);
                }
            }

            $request->forceFill([
                'status' => 'done',
                'resolution' => $resolution,
                'resolution_justification' => $justification,
                'actual_cost' => $actualCost,
                'closed_at' => now(),
                'closed_by' => $actor->id,
                'supplier_invoice_id' => $options['supplier_invoice_id'] ?? $request->supplier_invoice_id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: AssetMaintenanceRequest::class,
                auditableId: (int) $request->getKey(),
                after: [
                    'event' => 'closed',
                    'resolution' => $resolution->value,
                    'actual_cost' => $actualCost,
                    'justification' => $justification,
                ],
                actor: $actor,
            );

            return $request->refresh();
        });
    }

    /** Cancels an open request - no accounting choice needed (§2.4). */
    public function cancel(int $requestId, string $reason, Actor $actor): AssetMaintenanceRequest
    {
        Gate::authorize(AssetPermission::MANAGE);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'Cancelling a maintenance request requires a reason.',
            ]);
        }

        return DB::transaction(function () use ($requestId, $reason, $actor): AssetMaintenanceRequest {
            /** @var AssetMaintenanceRequest $request */
            $request = AssetMaintenanceRequest::query()->lockForUpdate()->findOrFail($requestId);

            if ($request->status->isClosed()) {
                return $request;
            }

            $request->forceFill([
                'status' => 'cancelled',
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: AssetMaintenanceRequest::class,
                auditableId: (int) $request->getKey(),
                after: ['event' => 'cancelled', 'reason' => $reason],
                actor: $actor,
            );

            return $request;
        });
    }
}
