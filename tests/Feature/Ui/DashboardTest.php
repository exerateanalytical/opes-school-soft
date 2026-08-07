<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Operations\Livewire\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

// Authentication is established with Pest's actingAs() rather than
// Livewire::actingAs()->test(). The facade declares test() non-generically, so
// Livewire::test() analyses cleanly, but LivewireManager::test() is
// @template TComponent over a union that already contains plain `string` - a
// class-string argument matches both branches and PHPStan cannot resolve the
// template. The two forms authenticate identically; only the first survives
// level 8 without a suppression.
function dashUser(Role $role = Role::Administrator): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('shows the number of active users', function () {
    $user = dashUser();
    User::factory()->count(4)->create();

    actingAs($user);

    Livewire::test(Dashboard::class)->assertSee('5');
});

it('surfaces a red health check with the command that fixes it', function () {
    // A fresh install has no backup, so backup.recency is red. That must reach
    // the operator rather than sitting in a CLI command nobody runs (09-ui 3.4).
    actingAs(dashUser());

    Livewire::test(Dashboard::class)->assertSee('opes:backup:run');
});

it('renders a dash rather than zero for a metric with no data', function () {
    // 09-ui 3.3: zero and "not yet recorded" are different facts, and
    // conflating them is how a dashboard starts lying.
    actingAs(dashUser());

    Livewire::test(Dashboard::class)->assertSee('—');
});

it('only shows alerts the user can act on', function () {
    actingAs(dashUser(Role::Bursar));

    Livewire::test(Dashboard::class)->assertDontSee('opes:backup:run');
});

it('renders through the real route, not just the component', function () {
    // Livewire::test() never renders layouts/app.blade.php - a broken shell
    // would leave every other test in this file green.
    actingAs(dashUser())->get('/dashboard')->assertOk()->assertSee('OPES');
});
