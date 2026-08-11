<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Every `route('portal.…')` reference in a portal Blade file must name a route
 * that exists, and every portal route must point at a component whose view
 * exists.
 *
 * This is the "all routes wired" check, and it earns its place: a typo'd route
 * name in a Blade file is a RuntimeException at render time, on a page a parent
 * reached - not at deploy time, and not in any test that does not happen to
 * render that exact partial. Eighteen screens cross-linking each other is well
 * past the point where eyeballing it is reliable.
 */

it('resolves every portal route referenced from a portal view', function () {
    $viewPath = resource_path('views/livewire/guardians/portal');
    $layout = resource_path('views/layouts/portal.blade.php');

    $files = array_merge(
        glob($viewPath.DIRECTORY_SEPARATOR.'*.blade.php') ?: [],
        file_exists($layout) ? [$layout] : [],
    );

    expect($files)->not->toBeEmpty('no portal views were found to check');

    $missing = [];

    foreach ($files as $file) {
        $contents = (string) file_get_contents($file);

        /*
         * Every `'portal.…'` STRING LITERAL, not just the ones inside a
         * `route(...)` call.
         *
         * The tab strip and the bottom nav both build a list of names in an
         * array and then call `route($routeName, …)`, so a regex anchored on
         * `route('` would miss precisely the two files that link to the most
         * screens - which is the opposite of useful.
         */
        preg_match_all("/'(portal\.[a-z0-9_.]+)'/i", $contents, $matches);

        foreach (array_unique($matches[1]) as $name) {
            if (! Route::has($name)) {
                $missing[] = basename($file).' -> '.$name;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('points every portal route at a component whose view exists', function () {
    $missing = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();

        if ($name === null || ! str_starts_with($name, 'portal.')) {
            continue;
        }

        $action = $route->getActionName();

        // Livewire full-page components are registered as the class itself.
        if (! class_exists($action)) {
            continue;
        }

        $reflection = new ReflectionClass($action);
        $source = (string) file_get_contents((string) $reflection->getFileName());

        if (! preg_match("/view\(\s*'([a-z0-9_.\-]+)'/i", $source, $match)) {
            continue;
        }

        if (! view()->exists($match[1])) {
            $missing[] = $name.' -> '.$match[1];
        }
    }

    expect($missing)->toBe([]);
});

it('registers every screen the portal navigation offers', function () {
    // The six bottom-nav destinations, named here so removing one from the
    // layout without removing it here is a failure rather than a silent
    // regression in what a parent can reach.
    foreach ([
        'portal.dashboard',
        'portal.payments',
        'portal.messages',
        'portal.announcements',
        'portal.search',
        'portal.account',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("nav route {$name} is missing");
    }
});

it('registers every child-scoped tab', function () {
    foreach ([
        'portal.children.profile',
        'portal.children.results',
        'portal.children.attendance',
        'portal.children.timetable',
        'portal.children.fees',
        'portal.children.discipline',
        'portal.children.health',
        'portal.children.documents',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("child tab route {$name} is missing");
    }
});

it('registers every account-area screen', function () {
    foreach ([
        'portal.account',
        'portal.account.settings',
        'portal.account.edit',
        'portal.account.notifications',
        'portal.account.security',
        'portal.help',
    ] as $name) {
        expect(Route::has($name))->toBeTrue("account route {$name} is missing");
    }
});
