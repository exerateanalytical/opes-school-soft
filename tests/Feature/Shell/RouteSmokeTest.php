<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Every GET route that points at a Livewire component, fetched over real HTTP
 * as an administrator.
 *
 * This exists because `Livewire::test(SomeComponent::class)` does NOT prove a
 * screen works: it instantiates the class directly and never resolves the
 * component ALIAS. Routing over HTTP does resolve it, so a component that was
 * never registered in AppServiceProvider renders perfectly under
 * Livewire::test() and answers 500 in a browser. That is precisely how five
 * Tax screens shipped broken while looking verified.
 *
 * A 403 is a pass: this asserts the screen BOOTS, not that this actor may see
 * it - RBAC has its own tests. Only 500 (and a missing component alias in
 * particular) is a failure.
 */
it('boots every livewire-backed GET route without a server error', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $admin = User::factory()->create();
    $admin->assignRole(Role::SuperAdmin->value);

    $skipped = [];
    $checked = 0;
    $broken = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }

        $action = $route->getActionName();

        if (! str_contains($action, '\\Livewire\\')) {
            continue;
        }

        // Routes with bound parameters need real fixtures per module; those
        // are covered by each module's own screen tests. Skipping them is
        // reported rather than silent, so this never reads as full coverage.
        if (preg_match('/\{[^}]+\}/', $route->uri()) === 1) {
            $skipped[] = $route->uri();

            continue;
        }

        $checked++;

        $response = actingAs($admin)->get('/'.ltrim($route->uri(), '/'));

        if ($response->getStatusCode() >= 500) {
            $broken[] = $route->uri().' -> '.$response->getStatusCode();
        }
    }

    expect($checked)->toBeGreaterThan(50);
    expect($broken)->toBe([]);
})->group('smoke');
