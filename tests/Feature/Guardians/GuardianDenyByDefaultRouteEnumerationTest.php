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

    // The guardian's OWN door - row 1 of the allow-list, and the ONLY
    // names this suite treats as "explicitly allow-listed" per
    // routes/web.php's own comment above the `guardian.portal` group.
    $allowListed = [
        'portal.dashboard',
        'portal.children.results',
        'portal.children.fees',
        'portal.children.profile',
        'portal.children.discipline',
        'portal.children.documents',
    ];

    // Auth-only BY DESIGN, not a gap: both are documented in routes/web.php
    // itself as carrying no `can:` gate because they carry no data to gate
    // (`documents.verify` - "the page holds no student data by
    // construction"; every `placeholder.*` page - "the page contains
    // nothing but translation strings"). Neither is part of the guardian
    // portal, but neither is a route this suite exists to catch either.
    $justifiedOpen = static fn (string $name): bool => $name === 'documents.verify'
        || str_starts_with($name, 'placeholder.');

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

        if (in_array($name, $allowListed, true) || $justifiedOpen($name) || $outOfScope($name)) {
            continue;
        }

        $walked[] = $name;

        $response = $this->actingAs($user)->get(p12denyBuildUri($route));

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

    $this->get(route('portal.dashboard'))->assertOk();
});

it('denies an unauthenticated visitor on both the staff dashboard and the guardian portal', function () {
    $this->get('/dashboard')->assertRedirect('/login');
    $this->get('/portal')->assertRedirect('/login');
});
