<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Support\Navigation;
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

it('shows the academic links to a role with academics.view', function () {
    $html = (string) actingAs(shellUser(Role::Teacher))->get('/dashboard')->getContent();

    expect($html)->toContain('href="/classes"');
    expect($html)->toContain('href="/subjects"');
    // A Teacher holds academics.view but not academics.manage, and the
    // settings route is gated on manage - so no link that would answer 403.
    expect($html)->not->toContain('href="/academics/settings"');
});

it('shows the academic settings link to a role with academics.manage', function () {
    expect((string) actingAs(shellUser())->get('/dashboard')->getContent())
        ->toContain('href="/academics/settings"');
});

it('hides the academic links from a role without academics.view', function () {
    $html = (string) actingAs(shellUser(Role::Bursar))->get('/dashboard')->getContent();

    expect($html)->not->toContain('href="/classes"');
    expect($html)->not->toContain('href="/subjects"');
    expect($html)->not->toContain('href="/academics/settings"');
});

it('blocks the academic routes as well as hiding the links', function () {
    // The `can:` middleware refuses before the Livewire component renders.
    actingAs(shellUser(Role::Bursar))->get('/classes')->assertForbidden();
    actingAs(shellUser(Role::Bursar))->get('/subjects')->assertForbidden();
    actingAs(shellUser(Role::Teacher))->get('/academics/settings')->assertForbidden();
})->skip(
    fn (): bool => ! class_exists('App\Modules\Academics\Livewire\ClassGroups\Index'),
    'The Academics Livewire components are not built yet, so routes/web.php has not registered these routes.',
);

it('shows the students link to a role with students.view', function () {
    // A Teacher reads the roll but does not run admissions, and the
    // admissions route is gated on admissions.manage - so no link that would
    // answer 403.
    $html = (string) actingAs(shellUser(Role::Teacher))->get('/dashboard')->getContent();

    expect($html)->toContain('href="/students"');
    expect($html)->not->toContain('href="/admissions"');
});

it('shows the admissions link to a role with admissions.manage', function () {
    $html = (string) actingAs(shellUser(Role::Registrar))->get('/dashboard')->getContent();

    expect($html)->toContain('href="/admissions"');
    expect($html)->toContain('href="/students"');
});

it('hides the people links from a role without students.view', function () {
    $html = (string) actingAs(shellUser(Role::Librarian))->get('/dashboard')->getContent();

    expect($html)->not->toContain('href="/students"');
    expect($html)->not->toContain('href="/admissions"');
});

it('links guardians from the sidebar to the placeholder that will become the list', function () {
    // The guardian LIST screen is not built, but a dead grey label read as a
    // defect to a real operator. The item now links to /guardians, which
    // serves the scheduled-module page until the real list takes over the
    // same URL.
    $html = (string) actingAs(shellUser())->get('/dashboard')->getContent();

    expect($html)->toContain('href="/guardians"');
});

it('blocks the people routes as well as hiding the links', function () {
    actingAs(shellUser(Role::Librarian))->get('/students')->assertForbidden();
    actingAs(shellUser(Role::Teacher))->get('/admissions')->assertForbidden();
})->skip(
    fn (): bool => ! class_exists('App\Modules\Students\Livewire\Students\Index'),
    'The Students/Admissions Livewire components are not built yet, so routes/web.php has not registered these routes.',
);

it('blocks the route as well as hiding the link', function () {
    // 00-core 6.2: authorisation lives in the route and the Action. Hiding a
    // menu item is presentation and must never be mistaken for a control.
    actingAs(shellUser(Role::Bursar))->get('/users')->assertForbidden();
});

it('links every unbuilt module to its placeholder and labels it as coming', function () {
    // Every sidebar item is a real link. Unbuilt modules carry a "soon" chip
    // and lead to the scheduled-module page at the URL the real module will
    // later occupy - honest about what is not built, without a single dead
    // click anywhere in the sidebar.
    $html = (string) actingAs(shellUser())->get('/dashboard')->getContent();

    expect($html)->toContain('href="/library"');
    expect($html)->toContain('href="/timetable"');
    expect($html)->toContain('href="/settings"');

    /*
     * The "soon" chip is asserted only when there IS an unbuilt module.
     *
     * Every module in Navigation::items() now carries built => true, so
     * placeholderKeys() is empty and the chip cannot render however
     * correct the sidebar is. The flat assertion had therefore been
     * failing since the last placeholder shipped - describing a state the
     * product has grown out of, not catching a regression.
     *
     * The contract it protects is kept: the moment a module is added with
     * built => false, this asserts it is labelled as coming.
     */
    $unbuilt = Navigation::placeholderKeys();

    if ($unbuilt !== []) {
        expect($html)->toContain(__('opes.placeholder.chip_short'));
    }
});

it('serves the scheduled-module page on every placeholder route', function () {
    // Generated from the same source the routes are (Navigation), so a new
    // placeholder key cannot ship without a working page behind it.
    actingAs(shellUser());

    foreach (\App\Modules\Identity\Support\Navigation::placeholderRoutes() as $key => $path) {
        get($path)
            ->assertOk()
            ->assertSee(__('opes.nav.'.$key))
            ->assertSee(__('opes.placeholder.chip'));
    }
});

it('redirects a guest away from every placeholder route', function () {
    foreach (\App\Modules\Identity\Support\Navigation::placeholderRoutes() as $path) {
        get($path)->assertRedirect('/login');
    }
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
