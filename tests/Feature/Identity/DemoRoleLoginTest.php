<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Auth\Login;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\Navigation;
use App\Modules\Operations\Livewire\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * The role-aware demo sign-in (the multi-identity generalisation of the single
 * demo button covered by DemoLoginTest, which still asserts the two safety
 * guards on their own).
 *
 * The point of these is not the buttons: it is that a demo identity is a REAL
 * user carrying a REAL Spatie role, so what it can then see is decided by the
 * product's own permission checks and nothing else.
 */
function enableRoleDemoLogin(): void
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    config()->set('opes.demo_login.enabled', true);
    app()->detectEnvironment(fn (): string => 'local');
}

it('offers one demo identity per configured role', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)
        ->assertSee('Accountant', false)
        ->assertSee('Bursar', false)
        ->assertSee('Teacher', false);
});

it('grants the chosen role through spatie, not a bypass', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)->call('demoLogin', 'accountant');

    $user = User::query()->where('email', 'demo.accountant@opeschool.test')->firstOrFail();

    expect($user->hasRole(Role::Accountant->value))->toBeTrue()
        // Real permission checks answer for it, in both directions.
        ->and($user->can('ledger.view'))->toBeTrue()
        ->and($user->can('user.view'))->toBeFalse()
        ->and($user->can('backup.run'))->toBeFalse();

    expect(auth()->id())->toBe($user->getKey());
});

it('records which role a demo sign-in used', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)->call('demoLogin', 'teacher');

    $entry = AuditLog::query()->latest('id')->firstOrFail();

    expect($entry->after['method'] ?? null)->toBe('demo_login')
        ->and($entry->after['role'] ?? null)->toBe(Role::Teacher->value);
});

it('refuses a role the login page never offered', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)->call('demoLogin', 'super_admin')->assertForbidden();

    expect(User::query()->where('email', 'like', 'demo.super%')->exists())->toBeFalse()
        ->and(auth()->check())->toBeFalse();
});

it('hides operator tiles from a role that cannot act on them', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)->call('demoLogin', 'accountant');

    Livewire::test(Dashboard::class)
        ->assertDontSee(__('opes.dashboard.tile_backup'))
        ->assertDontSee(__('opes.dashboard.tile_health'))
        ->assertDontSee(__('opes.dashboard.tile_users'));
});

it('leaves the administrator dashboard exactly as it was', function () {
    enableRoleDemoLogin();

    Livewire::test(Login::class)->call('demoLogin', 'administrator');

    Livewire::test(Dashboard::class)
        ->assertSee(__('opes.dashboard.tile_backup'))
        ->assertSee(__('opes.dashboard.tile_health'))
        ->assertSee(__('opes.dashboard.tile_users'))
        ->assertSee(__('opes.dashboard.tile_roles'));
});

it('keeps the sidebar filtered to what each role may open', function () {
    $keysFor = function (Role $role): array {
        $held = array_map(static fn ($p): string => $p->value, $role->defaultPermissions());

        return array_values(array_map(
            static fn (array $item): string => $item['key'],
            array_filter(
                Navigation::items(),
                static fn (array $item): bool => $item['permission'] === null
                    || in_array($item['permission']->value, $held, true),
            ),
        ));
    };

    $accountant = $keysFor(Role::Accountant);
    $teacher = $keysFor(Role::Teacher);

    // The finance offices see the money screens...
    expect($accountant)->toContain('ledger', 'finance', 'tax', 'procurement')
        // ...and never the identity/operator ones.
        ->and($accountant)->not->toContain('users', 'settings', 'operations', 'staff');

    // The teacher sees the classroom, and no part of the money chain.
    expect($teacher)->toContain('students', 'classes', 'attendance', 'timetable')
        ->and($teacher)->not->toContain('ledger', 'finance', 'users', 'settings');
});
