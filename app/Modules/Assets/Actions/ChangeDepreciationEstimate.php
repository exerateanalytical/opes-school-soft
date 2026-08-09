<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §5.5 - change in accounting estimate, PROSPECTIVE.
 * No restatement, no reversal of prior charges, and mechanically no
 * special code: §4.3's `entitlement − Σ posted` produces the corrected
 * go-forward charge in the next run automatically. When the new
 * entitlement is BELOW what history already posted, the next charge is
 * negative - a credit to 681x - which is correct and why `charge` is
 * BIGINT SIGNED.
 *
 * Requires a reason and writes the AuditLog trail; the snapshots on the
 * Asset row (never the category) are what change.
 */
final class ChangeDepreciationEstimate
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $assetId,
        ?int $usefulLifeMonths,
        ?int $residualValue,
        string $reason,
        Actor $actor,
    ): Asset {
        Gate::authorize(AssetPermission::DEPRECIATE);

        return DB::transaction(function () use ($assetId, $usefulLifeMonths, $residualValue, $reason, $actor): Asset {
            if (trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => 'A change in estimate requires a stated reason (§5.5).',
                ]);
            }

            if ($usefulLifeMonths === null && $residualValue === null) {
                throw ValidationException::withMessages([
                    'useful_life_months' => 'Nothing to change: provide a new useful life and/or residual value.',
                ]);
            }

            /** @var Asset|null $asset */
            $asset = Asset::query()->lockForUpdate()->find($assetId);

            if ($asset === null) {
                throw new DomainException("Asset {$assetId} does not exist.");
            }

            if ($asset->status->isFrozen()) {
                throw new DomainException(
                    "Asset '{$asset->tag_number}' is {$asset->status->value} (A12); its estimates are history now."
                );
            }

            if ($asset->depreciation_method === null || ! $asset->depreciation_method->depreciates()) {
                throw new DomainException(
                    "Asset '{$asset->tag_number}' does not depreciate; there is no estimate to change."
                );
            }

            if ($usefulLifeMonths !== null && $usefulLifeMonths <= 0) {
                throw ValidationException::withMessages([
                    'useful_life_months' => 'The useful life must be a positive number of months.',
                ]);
            }

            if ($residualValue !== null
                && ($residualValue < 0 || $residualValue >= $asset->acquisition_cost)) {
                throw ValidationException::withMessages([
                    'residual_value' => 'The residual value must stay within [0, acquisition_cost) (A8).',
                ]);
            }

            $before = [
                'useful_life_months' => $asset->useful_life_months,
                'residual_value' => $asset->residual_value,
            ];

            $asset->forceFill(array_filter([
                'useful_life_months' => $usefulLifeMonths,
                'residual_value' => $residualValue,
            ], static fn (?int $value): bool => $value !== null))->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: Asset::class,
                auditableId: (int) $asset->getKey(),
                before: $before,
                after: [
                    'event' => 'estimate_changed',
                    'useful_life_months' => $asset->useful_life_months,
                    'residual_value' => $asset->residual_value,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            return $asset->refresh();
        });
    }
}
