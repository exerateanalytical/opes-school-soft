<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;

/*
 * docs/plans/phase-12-13.md 12.4: docs/api/openapi.yaml is hand-maintained
 * (no network doc generators on a school LAN), so a test - not discipline -
 * keeps it honest. Enforced in BOTH directions: an undocumented live route
 * fails, and a documented-but-gone path fails. Either failure names the
 * offender.
 */

if (! function_exists('p12apiDocumentedOperations')) {
    /**
     * Parse the (path, method) pairs out of the spec's `paths:` section.
     *
     * A deliberate micro-parser rather than a YAML dependency: it reads only
     * the two indentation levels this test needs - path keys at two spaces,
     * HTTP verb keys at four - and symfony/yaml is not installed (checked:
     * vendor/symfony has no yaml package). If the spec's layout changes
     * beyond that shape, this test fails loudly rather than passing quietly.
     *
     * @return list<string> e.g. ["GET api/v1/students"]
     */
    function p12apiDocumentedOperations(string $specPath): array
    {
        $lines = file($specPath);
        expect($lines)->toBeArray();
        assert(is_array($lines));

        $operations = [];
        $inPaths = false;
        $currentPath = null;

        foreach ($lines as $line) {
            if (preg_match('/^paths:\s*$/', $line) === 1) {
                $inPaths = true;

                continue;
            }

            // Any other top-level key ends the paths section.
            if ($inPaths && preg_match('/^\S/', $line) === 1) {
                break;
            }

            if (! $inPaths) {
                continue;
            }

            if (preg_match('#^ {2}(/\S*):\s*$#', $line, $m) === 1) {
                $currentPath = $m[1];

                continue;
            }

            if ($currentPath !== null
                && preg_match('/^ {4}(get|post|put|patch|delete|options|head):\s*$/', $line, $m) === 1) {
                // The spec's server url is /api, so the full uri is api + path.
                $operations[] = strtoupper($m[1]).' api'.$currentPath;
            }
        }

        return $operations;
    }
}

it('documents every registered api route in docs/api/openapi.yaml', function () {
    $spec = dirname(__DIR__, 3).'/docs/api/openapi.yaml';
    expect(file_exists($spec))->toBeTrue();

    $documented = p12apiDocumentedOperations($spec);
    expect($documented)->not->toBeEmpty();

    $registered = [];

    /** @var list<RoutingRoute> $allRoutes */
    $allRoutes = Route::getRoutes()->getRoutes();

    foreach ($allRoutes as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue; // implied by GET, never documented separately
            }

            $registered[] = $method.' '.$route->uri();
        }
    }

    expect($registered)->not->toBeEmpty();

    $undocumented = array_values(array_diff($registered, $documented));
    expect($undocumented)->toBe([], 'Undocumented api routes: '.implode(', ', $undocumented));

    $stale = array_values(array_diff($documented, $registered));
    expect($stale)->toBe([], 'Documented but unregistered operations: '.implode(', ', $stale));
});
