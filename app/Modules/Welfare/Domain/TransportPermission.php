<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The Transport ability names, as string constants.
 *
 * Phase 10's wiring package (W5) owns `Identity\Domain\Permission` and adds
 * the enum cases + role seeds + lang labels for these values in one place
 * (phase-10 plan §2); this class exists so the Transport Actions and screen
 * built in the parallel W1 package gate on the SAME strings without editing
 * that shared enum concurrently - the exact pattern AssetPermission and
 * InventoryPermission set in Phase 9. Values follow the enum's two-segment
 * `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face W5 adds.
 */
final class TransportPermission
{
    /** Read access to the transport screens, roster and reports. */
    public const VIEW = 'transport.view';

    /** Routes, stops, vehicles, drivers, allocations, trip/fuel/maintenance logs. */
    public const MANAGE = 'transport.manage';

    private function __construct() {}
}
