<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * The Visitors ability name, as a string constant.
 *
 * Phase 10's wiring package (W5) owns `Identity\Domain\Permission` and adds
 * the enum case + role seeds (FrontDesk, WelfareOfficer) + lang labels for
 * this value in one place (phase-10 plan §2); this class exists so the
 * Visitor Actions and screen built in the parallel W4 package gate on the
 * SAME string without editing that shared enum concurrently - the exact
 * pattern TransportPermission / HostelPermission / MedicalPermission set.
 * Value follows the enum's two-segment `module.action` convention.
 *
 * Spatie resolves abilities by name at runtime, so a permission row + grant
 * is all a holder needs; the enum case is the compile-time face W5 adds.
 */
final class VisitorPermission
{
    /** Run the gate desk: check visitors in and out, read the register. */
    public const MANAGE = 'visitor.manage';

    private function __construct() {}
}
