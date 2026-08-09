<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The Medical ability names, as string constants.
 *
 * Phase 10's wiring package (W5) owns `Identity\Domain\Permission` and adds
 * the enum cases + role seeds + lang labels for these values in one place
 * (phase-10 plan §2); this class exists so the Medical Actions and screen
 * built in the parallel W3 package gate on the SAME strings without editing
 * that shared enum concurrently - the exact pattern TransportPermission and
 * HostelPermission set. Values follow the enum's two-segment
 * `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face W5 adds.
 */
final class MedicalPermission
{
    /** Read access to the medical screen, dashboard stats and summaries. */
    public const VIEW = 'medical.view';

    /** Record consultations, open and close referrals - Nurse work. */
    public const MANAGE = 'medical.manage';

    private function __construct() {}
}
