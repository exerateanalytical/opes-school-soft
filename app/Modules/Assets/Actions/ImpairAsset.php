<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\Asset;
use App\Modules\Assets\Models\AssetCategory;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §6.5 - impairment SHIPS DISABLED. The class-29
 * provision subdivision and the matching impairment expense account are
 * V9 NEEDS VERIFICATION: the seeder configures neither, and this Action's
 * only job until then is to refuse with a message naming exactly what is
 * missing (§11 discipline - a wrong seeded account is more dangerous than
 * an empty field). No impairment row, no posting, ever, until verified.
 */
final class ImpairAsset
{
    public function handle(int $assetId, Actor $actor): never
    {
        Gate::authorize(AssetPermission::MANAGE);

        /** @var Asset|null $asset */
        $asset = Asset::query()->find($assetId);

        if ($asset === null) {
            throw new DomainException("Asset {$assetId} does not exist.");
        }

        /** @var AssetCategory $category */
        $category = AssetCategory::query()->findOrFail($asset->asset_category_id);

        if ($category->impairment_expense_account_id === null
            || $category->impairment_provision_account_id === null) {
            throw new DomainException(
                "NEEDS VERIFICATION (V9): category '{$category->code}' has no impairment expense / "
                .'class-29 provision accounts configured. Impairment is disabled until 02-accounting.md '
                .'verifies the SYSCOHADA codes; configure impairment_expense_account_id and '
                .'impairment_provision_account_id after verification.'
            );
        }

        // Even a configured category cannot impair yet: the posting shape
        // itself is unverified (V9). Hard-disabled by design, not a TODO.
        throw new DomainException(
            'Impairment is hard-disabled pending 02-accounting.md verification of the class-29 '
            .'posting shape (V9). No impairment can be recorded in this release.'
        );
    }
}
