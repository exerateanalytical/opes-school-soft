<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * The asset-register ability names, as string constants.
 *
 * Same contract as Procurement's SupplierInvoicePermission: the Phase 9
 * wiring package (F5) adds the `Identity\Domain\Permission` enum cases +
 * role seeds + lang labels for these values in ONE place; this class exists
 * so the Assets Actions and screens gate on the SAME strings without the
 * parallel packages editing that shared enum concurrently. Values follow
 * the two-segment `module.action` convention (docs/plans/phase-09.md §5).
 */
final class AssetPermission
{
    /** Read access to the asset register and reports. */
    public const VIEW = 'asset.view';

    /** Create/edit categories, register, capitalise, commission, split, custody, maintenance. */
    public const MANAGE = 'asset.manage';

    /** Run/approve/post depreciation (F2's Actions gate on this). */
    public const DEPRECIATE = 'asset.depreciate';

    /** Dispose or write off an asset (F2's Actions gate on this). */
    public const DISPOSE = 'asset.dispose';

    private function __construct() {}
}
