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
 * The guardian portal door (docs/plans/phase-12-13.md 12.2). Runs after
 * `auth` on every /portal route and answers exactly one question: is the
 * authenticated user an active guardian-portal principal?
 *
 * Three checks, all fail-closed with 403:
 *
 *   1. `portal.access` - the single permission the seeded `guardian` role
 *      holds. A staff account without it cannot open the portal shell even
 *      though it can open everything else; a staff user who is ALSO a
 *      guardian holds both roles and passes here on the guardian one
 *      (7.5: the two scopes are evaluated independently, and neither widens
 *      the other).
 *   2. PortalContext resolution - `User.status = 'active'`, a `guardians`
 *      row pointing at this user via `portal_user_id`, `Guardian.status =
 *      'active'`, not archived. These are the non-per-link conjunctive
 *      gates of 7.5's reading rules.
 *   3. Nothing else. WHAT the guardian may see per child is never decided
 *      here - that is GuardianScopeMatrix, called by GuardianPortalPolicy
 *      with the per-child link. This middleware only establishes WHO.
 *
 * The resolved context is bound into the container, which is also where the
 * business date gets fixed for the request (7.3: evaluated once, passed
 * down).
 */
final class EnsureGuardianPortal
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            // `auth` should have redirected already; belt and braces for a
            // route that forgot to stack it.
            abort(403);
        }

        if (! Gate::forUser($user)->allows(Permission::PortalAccess->value)) {
            abort(403);
        }

        if (PortalContext::current() === null) {
            abort(403);
        }

        return $next($request);
    }
}
