<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
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
 * 06-assets-stores.md §3 - CommissionAsset. Requires an in_service_date,
 * transfers the accumulated construction balance from the category's
 * in-progress account to its gross asset account (`asset.commissioned`,
 * one entry: Dr class-2 gross / Cr in-progress), flips status to
 * in_service, and derives depreciation_start_date (§5.1: =
 * in_service_date). Until this runs, invariant A14 holds by construction -
 * an in_progress asset is excluded from every depreciation run's WHERE.
 *
 * The commissioned cost = initial capitalised cost + Σ construction cost
 * rows; the A7 residual snapshot is re-taken against the FINAL cost, since
 * commissioning is the first moment the depreciable base is known.
 */
final class CommissionAsset
{
    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $assetId, string $inServiceDate, Actor $actor): Asset
    {
        Gate::authorize(AssetPermission::MANAGE);

        return DB::transaction(function () use ($assetId, $inServiceDate, $actor): Asset {
            /** @var Asset $asset */
            $asset = Asset::query()->lockForUpdate()->findOrFail($assetId);

            if ($asset->status !== AssetStatus::InProgress) {
                throw new DomainException(
                    "Only an in_progress asset can be commissioned; '{$asset->tag_number}' is {$asset->status->value}."
                );
            }

            if ($inServiceDate < $asset->acquisition_date) {
                throw ValidationException::withMessages([
                    'in_service_date' => 'A6: in_service_date must be on or after acquisition_date.',
                ]);
            }

            /** @var AssetCategory $category */
            $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

            if ($category->in_progress_account_id === null) {
                throw new DomainException(
                    "Category '{$category->code}' has no in-progress account configured; commissioning refused."
                );
            }

            // MySQL SUM() comes back as a string; cast (00-core test discipline).
            $constructionTotal = (int) DB::table('asset_construction_costs')
                ->where('asset_id', $asset->getKey())
                ->sum('amount');

            $total = $asset->acquisition_cost + $constructionTotal;

            if ($total <= 0) {
                throw new DomainException(
                    "Asset '{$asset->tag_number}' has accumulated no construction cost; nothing to commission."
                );
            }

            // A7 against the final, known cost.
            $residual = intdiv($total * $category->default_residual_rate_bp, Rate::SCALE);

            $asset->forceFill([
                'acquisition_cost' => $total,
                'residual_value' => $residual,
                'useful_life_months' => $category->useful_life_months,
                'depreciation_method' => $category->depreciation_method,
                'prorata_convention' => $category->prorata_convention,
                'in_service_date' => $inServiceDate,
                'depreciation_start_date' => $inServiceDate,
                'status' => AssetStatus::InService,
            ]);

            // The transfer entry: Dr gross / Cr in-progress for the whole
            // accumulated balance. Rule configuration owns the credit side.
            $entry = $this->post->handle(
                PostingEvent::AssetCommissioned->value,
                [
                    'asset' => [
                        'cost' => $total,
                        'accumulated_depreciation' => 0,
                        'net_book_value' => $total,
                        'proceeds' => 0,
                        'reference' => $asset->tag_number,
                        'partner' => null,
                        'asset_account_id' => $category->asset_account_id,
                        'depreciation_account_id' => $category->accumulated_depreciation_account_id,
                        'disposal_value_account_id' => $category->disposal_nbv_account_id,
                        'disposal_proceeds_account_id' => $category->disposal_proceeds_account_id,
                    ],
                ],
                $inServiceDate,
                $actor,
                $asset->tag_number,
            );

            $asset->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: Asset::class,
                auditableId: (int) $asset->getKey(),
                after: [
                    'event' => 'commissioned',
                    'in_service_date' => $inServiceDate,
                    'commissioned_cost' => $total,
                    'construction_total' => $constructionTotal,
                    'journal_entry_id' => (int) $entry->getKey(),
                ],
                actor: $actor,
            );

            return $asset->refresh();
        });
    }
}
