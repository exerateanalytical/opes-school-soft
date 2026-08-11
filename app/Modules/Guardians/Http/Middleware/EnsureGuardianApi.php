<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Http\Middleware;

use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Identity\Domain\Permission;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * The guardian MOBILE door (docs/specs/2026-08-11-guardian-mobile-api-v1.md §3).
 *
 * EnsureGuardianPortal's counterpart for /api/v1/me, and deliberately its
 * mirror rather than its variation: the same two questions, in the same order,
 * fail-closed. What differs is only how the principal arrives - a Sanctum
 * personal access token instead of a session cookie - so the user is taken from
 * the request (which the `auth:sanctum` middleware has already resolved for the
 * API guard) and handed to PortalContext::resolveForUserId(), the SAME
 * resolution the session door uses.
 *
 *   1. `portal.access` - the outer gate the seeded guardian role holds. The
 *      token's own scope (`portal.read` / `portal.write`) is checked by the
 *      `abilities:` middleware on the route, not here: this class answers WHO,
 *      the ability answers WHAT THIS TOKEN WAS ISSUED FOR, and the two are
 *      different questions that must not be merged.
 *   2. PortalContext resolution - active user, active non-archived guardian
 *      behind `portal_user_id`. 7.3's business date is fixed here, once.
 *   3. Nothing else. What the guardian may see per child stays with
 *      GuardianScopeMatrix (07-students §7.5), called per link by the
 *      controllers through GuardianPortalPolicy.
 *
 * 403, never 401: by the time this runs the caller has authenticated. And
 * never a redirect - this middleware only ever guards JSON routes.
 */
final class EnsureGuardianApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // `auth:sanctum` should have refused already; belt and braces for
            // a route that forgot to stack it.
            abort(403);
        }

        if (! Gate::forUser($user)->allows(Permission::PortalAccess->value)) {
            abort(403);
        }

        $context = PortalContext::resolveForUserId((int) $user->getAuthIdentifier());

        if ($context === null) {
            abort(403);
        }

        // Bound for the rest of the request so every controller reads the same
        // guardian and the same `asOf` date - 7.3: evaluated once at
        // transaction start, passed down, never re-asked of the clock.
        app()->instance(PortalContext::class, $context);

        return $next($request);
    }
}
