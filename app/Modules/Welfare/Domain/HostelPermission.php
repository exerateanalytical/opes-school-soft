<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The Hostel ability names, as string constants.
 *
 * Phase 10's wiring package (W5) owns `Identity\Domain\Permission` and adds
 * the enum cases + role seeds + lang labels for these values in one place
 * (phase-10 plan §2); this class exists so the Hostel Actions and screen
 * built in the parallel W2 package gate on the SAME strings without editing
 * that shared enum concurrently - the exact pattern TransportPermission
 * (W1) and Phase 9's AssetPermission set. Values follow the enum's
 * two-segment `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face W5 adds.
 */
final class HostelPermission
{
    /** Read access to the hostel screens, occupancy and inspection lists. */
    public const VIEW = 'hostel.view';

    /** Hostels, rooms, beds, allocations, inspections. */
    public const MANAGE = 'hostel.manage';

    private function __construct() {}
}
