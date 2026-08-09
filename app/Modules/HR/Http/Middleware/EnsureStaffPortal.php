<?php

declare(strict_types=1);

namespace App\Modules\HR\Http\Middleware;

use App\Modules\HR\Support\StaffPortalContext;
use App\Modules\Identity\Domain\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The staff portal door (docs/plans/phase-12-13.md 12.3), the mirror of
 * Guardians\Http\Middleware\EnsureGuardianPortal for the `staff_portal`
 * role. Two checks, both fail-closed with 403:
 *
 *   1. `portal.access` - the single permission the seeded `staff_portal`
 *      role holds (Identity\Domain\Role: "portal roles hold exactly one
 *      permission"). A staff member with a full admin role but no portal
 *      account activated still cannot open THIS shell on that role alone.
 *   2. StaffPortalContext resolution - `User.status = 'active'`, a
 *      `staff_members` row pointing at this user via `portal_user_id`,
 *      `StaffMember.status = 'active'`, not archived.
 */
final class EnsureStaffPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(403);
        }

        if (! Gate::forUser($user)->allows(Permission::PortalAccess->value)) {
            abort(403);
        }

        if (StaffPortalContext::current() === null) {
            abort(403);
        }

        return $next($request);
    }
}
