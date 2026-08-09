<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Models\AssetCategory;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §6.6 - revaluation SHIPS DISABLED. Under OHADA a
 * revaluation is a regulated CAMPAIGN over whole categories with credit
 * to the écart de réévaluation equity account - 106, V8 NEEDS
 * VERIFICATION. Until verified this Action refuses, naming the missing
 * configuration; a school cannot revalue by accident.
 *
 * @see RevaluationCampaign - the schema is ready for the day V8 clears.
 */
final class RevalueAssets
{
    /**
     * @param  list<int>  $assetCategoryIds
     */
    public function handle(array $assetCategoryIds, Actor $actor): never
    {
        Gate::authorize(AssetPermission::MANAGE);

        if ($assetCategoryIds === []) {
            throw new DomainException('A revaluation campaign must name at least one asset category (§6.6).');
        }

        /** @var list<string> $unconfigured */
        $unconfigured = AssetCategory::query()
            ->whereIn('id', $assetCategoryIds)
            ->whereNull('revaluation_equity_account_id')
            ->orderBy('code')
            ->pluck('code')
            ->all();

        if ($unconfigured !== []) {
            throw new DomainException(
                'NEEDS VERIFICATION (V8): categories ['.implode(', ', $unconfigured).'] have no '
                .'écart de réévaluation equity account (106) configured. Revaluation is disabled '
                .'until 02-accounting.md verifies the code.'
            );
        }

        throw new DomainException(
            'Revaluation is hard-disabled pending 02-accounting.md verification of the 106 écart '
            .'de réévaluation account (V8). No campaign can be opened in this release.'
        );
    }
}
