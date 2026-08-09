<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/**
 * The Inventory ability names, as string constants.
 *
 * Phase 9's wiring package (F5) owns `Identity\Domain\Permission` and adds
 * the enum cases + role seeds + lang labels for these values in one place;
 * this class exists so the Inventory Actions built in the parallel F3
 * package gate on the SAME strings without editing that shared enum
 * concurrently (the exact pattern ProcurementPermission set in Phase 5).
 * Values follow the enum's two-segment `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face F5 adds.
 */
final class InventoryPermission
{
    /** Read access to the inventory screens and reports. */
    public const VIEW = 'inventory.view';

    /** Catalogue and receipt side: items, categories, locations, receipts, reservations. */
    public const MANAGE = 'inventory.manage';

    /** The value-moving side: issues, transfers, adjustments, stock-takes, sales, reversals. */
    public const POST = 'inventory.post';

    private function __construct() {}
}
