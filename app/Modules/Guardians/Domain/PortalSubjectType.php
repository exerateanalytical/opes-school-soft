<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Domain;

use App\Modules\Identity\Domain\Role;

/**
 * Who a portal invitation is FOR (docs/plans/phase-12-13.md 12.2): a guardian
 * or a staff member. The `portal_invitations` migration stores the morph
 * target as the owning model's class name, and this enum is the closed list
 * of the two classes that are ever legal there - an invitation for any other
 * subject type cannot be constructed.
 *
 * The Staff case names the HR model by STRING, deliberately. Importing
 * `App\Modules\HR\Models\StaffMember` here would violate
 * tests/Architecture/ModuleBoundaryTest.php; a string value does not, and the
 * invitation flow never instantiates the class - it reads and updates
 * `staff_members` through the query builder, which is the sanctioned
 * cross-module read path.
 */
enum PortalSubjectType: string
{
    case Guardian = 'App\Modules\Guardians\Models\Guardian';
    case Staff = 'App\Modules\HR\Models\StaffMember';

    /**
     * The portal role activation assigns - and the ONLY roles it may ever
     * assign. Both hold exactly `portal.access` (Identity\Domain\Role), so a
     * stolen invitation code can never mint an operational account.
     */
    public function portalRole(): Role
    {
        return match ($this) {
            self::Guardian => Role::Guardian,
            self::Staff => Role::StaffPortal,
        };
    }

    /** The table holding the subject's `portal_user_id` pointer. */
    public function table(): string
    {
        return match ($this) {
            self::Guardian => 'guardians',
            self::Staff => 'staff_members',
        };
    }
}
