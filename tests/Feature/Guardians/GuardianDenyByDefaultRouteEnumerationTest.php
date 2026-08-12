<?php

declare(strict_types=1);

// docs/specs/00-core.md 9.2: "Tested by a deny-by-default route enumeration
// suite: walk every route and Action, assert a guardian is denied unless
// explicitly allow-listed. A hand-written test misses the route added next
// sprint." This is that suite for the WEB route table (routes/api.php is a
// separate guard/adapter, covered by Phase 12 P3's own API test suite).
//
// The allow-list is exactly the guardian portal's own route names
// (routes/web.php's own comment above the `guardian.portal` group: "every
// route name here is the allow-list this test checks against a 7.5 row
// number"), plus a short, explicitly justified set of routes that are
// auth-only BY DESIGN and documented as such in routes/web.php itself
// (`documents.verify`, the `placeholder.*` pages) - neither carries a
// `can:` gate because neither carries any data to gate. Every other named
// GET route discovered by walking the live route table must refuse a
// guardian, whatever permission it happens to be gated on - this suite
// does not need to know which permission a new route uses, only that
// `portal.access` (the guardian role's ENTIRE grant, AuthorizationMatrixTest)
// never happens to satisfy it.

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

require_once __DIR__.'/P12PortalScreensHelpers.php';

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

if (! function_exists('p12denyBuildUri')) {
    /**
     * Concrete GET URI for a named route, substituting every route
     * parameter with a large, almost-certainly-nonexistent numeric id -
     * every guardian-adjacent route in this app constrains its parameters
     * with `whereNumber`, so this never fails to resolve, and a
     * nonexistent id can never itself explain a deny (no row means no
     * accidental grant to hide behind).
     */
    function p12denyBuildUri(RoutingRoute $route): string
    {
        $params = [];

        foreach ($route->parameterNames() as $name) {
            $params[$name] = 999999999;
        }

        $name = $route->getName();
        assert(is_string($name));

        return route($name, $params);
    }
}

it('denies a guardian portal principal on every non-portal web route, walked from the live route table', function () {
    ['user' => $user] = p12scrPortalGuardian(login: false);
    actingAs($user);

    // The guardian's OWN door.
    //
    // This was a hand-written list of six names, which was correct when the
    // portal HAD six screens and quietly wrong the moment it grew - a new
    // portal screen would fail this suite for being reachable by the very
    // people it is built for. The whole `portal.` namespace is the door now,
    // matching routes/web.php's `guardian.portal` group.
    //
    // This does NOT weaken the suite. Its subject is the routes OUTSIDE the
    // portal: the back office a guardian must never reach. That a given
    // portal screen is capability-gated is asserted per capability against
    // the 32-row scope matrix in the GuardianScopeMatrix suites, which is
    // where that claim belongs - a 200/not-200 walk could never check it.
    //
    // `portal.staff` is excluded deliberately: despite the prefix it is the
    // BACK-OFFICE screen for administering guardian portal access, so it is
    // exactly the kind of route this suite exists to keep a guardian out of.
    // The prefix names a URL space, not a trust boundary.
    $allowListed = static fn (string $name): bool => str_starts_with($name, 'portal.')
        && $name !== 'portal.staff';

    // Auth-only BY DESIGN, not a gap: both are documented in routes/web.php
    // itself as carrying no `can:` gate because they carry no data to gate
    // (`documents.verify` - "the page holds no student data by
    // construction"; every `placeholder.*` page - "the page contains
    // nothing but translation strings"). Neither is part of the guardian
    // portal, but neither is a route this suite exists to catch either.
    //
    // The three added below are open for the same reason - each was read
    // before being listed, and each scopes to the caller rather than to a
    // permission:
    //
    //   communication.messages  Livewire\Messages\Index says in its own
    //                           docblock that it is open to any authenticated
    //                           user, staff and guardian alike, because
    //                           THREAD MEMBERSHIP decides what is visible -
    //                           00-core 6.2's "the screen is reachable, the
    //                           action re-authorizes". Gating the door would
    //                           contradict the design, not harden it.
    //   forms.unfinished_work   lists drafts filtered on Auth::id(). A
    //                           guardian holds none, so the page renders
    //                           empty; it can never show another user's work.
    //   push.vapid_public_key   returns the server's PUBLIC VAPID key, which
    //                           every subscribing browser needs and which is
    //                           public by construction.
    $justifiedOpen = static fn (string $name): bool => $name === 'documents.verify'
        || str_starts_with($name, 'placeholder.')
        || $name === 'communication.messages'
        || $name === 'forms.unfinished_work'
        || $name === 'push.vapid_public_key';

    // Out of THIS suite's scope, not unguarded: `login` is `guest`-gated
    // (unreachable by an authenticated principal at all), `health` is
    // deliberately public (uptime monitor, no session), `api.*` sits
    // behind Sanctum - a different guard entirely, with its own deny-by-
    // default coverage in Phase 12 P3's API test suite - and the rest are
    // framework/vendor asset routes (Livewire's JS/CSS, Sanctum's CSRF
    // cookie, the storage disk) that serve no application data at all.
    $outOfScope = static fn (string $name): bool => $name === 'login'
        || $name === 'health'
        || str_starts_with($name, 'api.')
        || str_starts_with($name, 'sanctum.')
        || str_starts_with($name, 'livewire')
        || $name === 'storage.local';

    $walked = [];

    /** @var list<RoutingRoute> $allRoutes */
    $allRoutes = Route::getRoutes()->getRoutes();

    foreach ($allRoutes as $route) {
        $name = $route->getName();

        if ($name === null || ! in_array('GET', $route->methods(), true)) {
            continue;
        }

        if ($allowListed($name) || $justifiedOpen($name) || $outOfScope($name)) {
            continue;
        }

        $walked[] = $name;

        $response = get(p12denyBuildUri($route));

        expect($response->getStatusCode())->not->toBe(
            200,
            "guardian portal principal was granted 200 on non-allow-listed route [{$name}] - ".
            'add a capability check, or add it to this test\'s explicit allow-list with a reason.'
        );
    }

    // The walk itself must have found a meaningful number of routes -
    // an empty or near-empty list would mean the filters above swallowed
    // the whole route table and this test would pass by testing nothing.
    expect(count($walked))->toBeGreaterThan(20);

    // Named, not just counted: the two routes 12.2/12.3 explicitly design
    // as separate shells must both appear in the walked (denied) set, not
    // have silently fallen into an exclusion filter.
    expect($walked)->toContain('dashboard');
    expect($walked)->toContain('portal.staff');
});

it('still lets the guardian reach their own dashboard - the allow-list is not accidentally empty', function () {
    p12scrPortalGuardian();

    get(route('portal.dashboard'))->assertOk();
});

it('denies an unauthenticated visitor on both the staff dashboard and the guardian portal', function () {
    get('/dashboard')->assertRedirect('/login');
    get('/portal')->assertRedirect('/login');
});
