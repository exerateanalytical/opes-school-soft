<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

function shellUser(Role $role = Role::Administrator): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['name' => 'Ngwa Bertrand']);
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('requires authentication for the dashboard', function () {
    get('/dashboard')->assertRedirect('/login');
});

it('shows the product name and the signed-in user', function () {
    $user = shellUser();

    actingAs($user)->get('/dashboard')
        ->assertOk()
        ->assertSee('OPES')
        ->assertSee('Ngwa Bertrand');
});

it('shows the signed-in role in the status strip', function () {
    actingAs(shellUser())->get('/dashboard')->assertSee('Administrator');
});

it('shows a nav item the user has permission for', function () {
    actingAs(shellUser())->get('/dashboard')->assertSee('Users');
});

it('hides a nav item the user has no permission for', function () {
    // Hiding is courtesy, not protection - see the next test.
    actingAs(shellUser(Role::Bursar))->get('/dashboard')->assertDontSee('href="/users"', false);
});

it('blocks the route as well as hiding the link', function () {
    // 00-core 6.2: authorisation lives in the route and the Action. Hiding a
    // menu item is presentation and must never be mistaken for a control.
    actingAs(shellUser(Role::Bursar))->get('/users')->assertForbidden();
});

it('marks unbuilt nav items as disabled rather than hiding or faking them', function () {
    // A shell full of links that silently do nothing is worse than one that
    // admits what is not built yet.
    $html = (string) actingAs(shellUser())->get('/dashboard')->getContent();

    expect($html)->toContain('Students');
    expect($html)->toContain('aria-disabled="true"');
});

it('renders in French when the locale is set', function () {
    session(['locale' => 'fr']);

    actingAs(shellUser())->get('/dashboard')->assertSee('Tableau de bord');
});

it('switches locale and keeps it for the next request', function () {
    $user = shellUser();

    actingAs($user)->post('/locale', ['locale' => 'fr'])->assertRedirect();
    actingAs($user)->get('/dashboard')->assertSee('Tableau de bord');
});

it('rejects a locale that is not supported', function () {
    actingAs(shellUser())->post('/locale', ['locale' => 'xx'])->assertSessionHasErrors('locale');
});

it('sets a lang attribute matching the locale', function () {
    session(['locale' => 'fr']);

    expect((string) actingAs(shellUser())->get('/dashboard')->getContent())->toContain('lang="fr"');
});

it('lets a signed-in user sign out', function () {
    actingAs(shellUser())->post('/logout')->assertRedirect('/login');

    expect(auth()->check())->toBeFalse();
});
