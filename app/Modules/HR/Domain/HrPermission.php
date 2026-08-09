<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * The HR ability names, as string constants.
 *
 * Same contract as Procurement's ProcurementPermission and Assets'
 * AssetPermission: the Phase 11 wiring package (F5) adds the
 * `Identity\Domain\Permission` enum cases + role seeds + lang labels for
 * these values in ONE place; this class exists so the HR Actions and screens
 * gate on the SAME strings without the parallel packages editing that shared
 * enum concurrently. Values follow the two-segment `module.action`
 * convention (docs/plans/phase-11.md).
 */
final class HrPermission
{
    /** Read access to the staff register and dossiers. */
    public const VIEW = 'staff.view';

    /** Hire, contracts, dependants, assignments, cost allocations, termination. */
    public const MANAGE = 'staff.manage';

    /** Approve leave requests (F4's Actions gate on this). */
    public const LEAVE_APPROVE = 'leave.approve';

    /** Validate teaching-hours logs and timesheets (F4's Actions gate on this). */
    public const TIMESHEET_VALIDATE = 'timesheet.validate';

    private function __construct() {}
}
