<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The Insurance ability names, as string constants.
 *
 * Phase 10's wiring package owns `Identity\Domain\Permission` and adds the
 * enum cases + role seeds + lang labels for these values in one place
 * (phase-10 plan §2); this class exists so the Insurance Actions and screen
 * built in the parallel W5 domain package gate on the SAME strings without
 * editing that shared enum concurrently - the exact pattern
 * TransportPermission (W1) and HostelPermission (W2) set. Values follow the
 * enum's two-segment `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face the wiring
 * pass adds.
 */
final class InsurancePermission
{
    /** Read access to policies, certificates, claims and the uninsured report. */
    public const VIEW = 'insurance.view';

    /** Policies, bulk enrolment, claims recording and settlement. */
    public const MANAGE = 'insurance.manage';

    private function __construct() {}
}
