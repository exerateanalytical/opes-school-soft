<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Assets\Domain\AcquisitionType;
use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\AssetStatus;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * 06-assets-stores.md §2.2/§4.4 - capitalises a draft asset:
 *
 *  - A7: residual_value = floor(cost x default_residual_rate_bp / SCALE),
 *    stored as an AMOUNT. The rate is a default generator, never a live
 *    divisor; all depreciation arithmetic reads the amount only.
 *  - §5.3: depreciation method, useful life and prorata convention are
 *    COPIED from the category - snapshots, so a later category edit cannot
 *    rewrite a posted schedule. prorata may legitimately be NULL here; F2's
 *    RunDepreciation is the V1 gate that refuses to run while it is.
 *  - Posts `asset.acquired` through PostFromEvent (the only ledger door).
 *    The §4.4 shape - Dr class-2 gross / Cr 481 Fournisseurs
 *    d'investissements with the supplier partner - is the posting rule's
 *    configuration; this Action's job is a truthful payload.
 *  - §3: `asInProgress` (implied for self_constructed) targets the
 *    category's in-progress account instead and sets status in_progress;
 *    with zero initial cost nothing posts - costs accumulate later through
 *    RecordConstructionCost and transfer at commissioning.
 *
 * Idempotent: an already-capitalised asset (journal_entry_id stamped or
 * status beyond draft) is returned unchanged - no second entry, ever.
 */
final class CapitaliseAsset
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(
        int $assetId,
        Actor $actor,
        ?string $inServiceDate = null,
        bool $asInProgress = false,
    ): Asset {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($assetId, $actor, $inServiceDate, $asInProgress): Asset {
            /** @var Asset $asset */
            $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);

            // Idempotency without a key: capitalisation is a one-way, once-
            // only transition; re-running it returns the asset unchanged.
            if ($asset->status !== AssetStatus::Draft) {
                return $asset;
            }

            /** @var AssetCategory $category */
            $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

            if ($category->is_archived) {
                throw new DomainException(
                    "Asset category '{$category->code}' is archived; capitalisation refused."
                );
            }

            $inProgress = $asInProgress || $asset->acquisition_type === AcquisitionType::SelfConstructed;

            if ($inProgress && $category->in_progress_account_id === null) {
                // §3 / NEEDS-VERIFICATION discipline: never guess an account.
                throw new DomainException(
                    "Category '{$category->code}' has no in-progress (assets under construction) account configured; "
                    .'configure in_progress_account_id before capitalising work in progress.'
                );
            }

            if (! $inProgress && $asset->acquisition_cost <= 0) {
                throw ValidationException::withMessages([
                    'acquisition_cost' => 'Only an asset with a positive cost can be capitalised (a work-in-progress shell may start at zero).',
                ]);
            }

            if ($inServiceDate !== null && $inServiceDate < $asset->acquisition_date) {
                throw ValidationException::withMessages([
                    'in_service_date' => 'A6: in_service_date must be on or after acquisition_date.',
                ]);
            }

            // A7 - the snapshot: floor of cost x rate on the house scale
            // (Rate::SCALE, 100 000 bp = 100%).
            $residual = intdiv(
                $asset->acquisition_cost * $category->default_residual_rate_bp,
                Rate::SCALE,
            );

            $asset->forceFill([
                'residual_value' => $residual,
                'useful_life_months' => $category->useful_life_months,
                'depreciation_method' => $category->depreciation_method,
                'prorata_convention' => $category->prorata_convention,
                'in_service_date' => $inProgress ? null : $inServiceDate,
                'depreciation_start_date' => $inProgress ? null : $inServiceDate,
                'status' => $inProgress
                    ? AssetStatus::InProgress
                    : ($inServiceDate !== null ? AssetStatus::InService : AssetStatus::Idle),
            ]);

            $entryId = null;

            if ($asset->acquisition_cost > 0) {
                $targetAccountId = $inProgress
                    ? (int) $category->in_progress_account_id
                    : $category->asset_account_id;

                $entry = $this->post->handle(
                    PostingEvent::AssetAcquired->value,
                    $this->payload($asset, $category, $targetAccountId),
                    $asset->acquisition_date,
                    $actor,
                    $asset->tag_number,
                );

                $entryId = (int) $entry->getKey();
                $asset->journal_entry_id = $entryId;
            }

            $asset->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: Asset::class,
                auditableId: (int) $asset->getKey(),
                after: [
                    'event' => 'capitalised',
                    'status' => $asset->status->value,
                    'acquisition_cost' => $asset->acquisition_cost,
                    'residual_value' => $residual,
                    'journal_entry_id' => $entryId,
                ],
                actor: $actor,
            );

            return $asset->refresh();
        });
    }

    /**
     * The §11.2 `asset.*` payload, truthful at acquisition: nothing
     * accumulated, NBV = cost, no proceeds.
     *
     * @return array<string, mixed>
     */
    private function payload(Asset $asset, AssetCategory $category, int $targetAccountId): array
    {
        $partnerId = $asset->supplier_id ?? $asset->donor_id;

        return [
            'asset' => [
                'cost' => $asset->acquisition_cost,
                'accumulated_depreciation' => 0,
                'net_book_value' => $asset->acquisition_cost,
                'proceeds' => 0,
                'reference' => $asset->tag_number,
                'partner' => $partnerId === null ? null : ['type' => 'supplier', 'id' => $partnerId],
                'asset_account_id' => $targetAccountId,
                'depreciation_account_id' => $category->accumulated_depreciation_account_id,
                'disposal_value_account_id' => $category->disposal_nbv_account_id,
                'disposal_proceeds_account_id' => $category->disposal_proceeds_account_id,
            ],
        ];
    }
}
