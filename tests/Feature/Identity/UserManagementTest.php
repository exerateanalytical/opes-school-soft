<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Users\Form;
use App\Modules\Identity\Livewire\Users\Index;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function userAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['name' => 'Admin One']);
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('lists users', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->create(['name' => 'Ngwa Bertrand']);

    Livewire::test(Index::class)->assertSee('Ngwa Bertrand');
});

it('paginates rather than loading every user', function () {
    // 00-core 6.2 rule 8: no unbounded collection query reaches a view.
    actingAs(userAs(Role::Administrator));
    User::factory()->count(40)->create();

    Livewire::test(Index::class)
        ->assertViewHas('users', fn ($users) => $users->perPage() <= 25 && $users->total() === 41);
});

it('filters by search term', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->create(['name' => 'Ngwa Bertrand']);
    User::factory()->create(['name' => 'Mballa Chantal']);

    Livewire::test(Index::class)
        ->set('search', 'Ngwa')
        ->assertSee('Ngwa Bertrand')
        ->assertDontSee('Mballa Chantal');
});

it('filters by role', function () {
    actingAs(userAs(Role::Administrator));
    $bursar = User::factory()->create(['name' => 'Mballa Chantal']);
    $bursar->assignRole(Role::Bursar->value);

    Livewire::test(Index::class)
        ->set('role', Role::Bursar->value)
        ->assertSee('Mballa Chantal')
        ->assertDontSee('Admin One');
});

it('filters by status', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->create(['name' => 'Gone Away', 'status' => 'suspended']);

    Livewire::test(Index::class)
        ->set('status', 'suspended')
        ->assertSee('Gone Away')
        ->assertDontSee('Admin One');
});

it('resets filters back to page one', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->count(40)->create();

    Livewire::test(Index::class)
        ->set('search', 'zzz')
        ->call('resetFilters')
        ->assertSet('search', '')
        ->assertSet('page', 1);
});

it('creates a user and audits it', function () {
    actingAs(userAs(Role::Administrator));

    Livewire::test(Form::class)
        ->set('name', 'Njoya Paul')
        ->set('email', 'njoya@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertRedirect('/users');

    $created = User::query()->where('email', 'njoya@school.test')->firstOrFail();

    expect($created->hasRole(Role::Teacher->value))->toBeTrue();
    expect($created->status)->toBe('active');
    expect(AuditLog::query()->where('module', 'Identity')->where('action', 'created')->count())->toBe(1);
});

it('never writes the new password into the audit log', function () {
    actingAs(userAs(Role::Administrator));

    Livewire::test(Form::class)
        ->set('name', 'Njoya Paul')
        ->set('email', 'njoya@school.test')
        ->set('role', Role::Teacher->value)
        ->set('password', 'Str0ng-Passw0rd')
        ->call('save');

    foreach (AuditLog::query()->get() as $entry) {
        expect(json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR))
            ->not->toContain('Str0ng-Passw0rd');
    }
});

it('stores the password hashed with argon2id', function () {
    actingAs(userAs(Role::Administrator));

    Livewire::test(Form::class)
        ->set('name', 'Njoya Paul')->set('email', 'njoya@school.test')
        ->set('role', Role::Teacher->value)->set('password', 'Str0ng-Passw0rd')
        ->call('save');

    $created = User::query()->where('email', 'njoya@school.test')->firstOrFail();

    expect($created->password)->toStartWith('$argon2id$');
});

it('rejects a duplicate email', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->create(['email' => 'taken@school.test']);

    Livewire::test(Form::class)
        ->set('name', 'Someone')->set('email', 'taken@school.test')
        ->set('role', Role::Teacher->value)->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertHasErrors('email');
});

it('rejects a role that is not in the enum', function () {
    actingAs(userAs(Role::Administrator));

    Livewire::test(Form::class)
        ->set('name', 'Someone')->set('email', 'someone@school.test')
        ->set('role', 'wizard')->set('password', 'Str0ng-Passw0rd')
        ->call('save')
        ->assertHasErrors('role');
});

it('rejects an unprivileged actor even if the form component were bypassed entirely', function () {
    // Defense in depth: CreateUser carries its own authorization check,
    // independent of the Livewire component's mount()/save() checks. This is
    // what actually protects the system if a future caller invokes the
    // Action directly - a queued job, an API endpoint, a console command.
    //
    // This replaces an earlier version of this test that chained ->set() and
    // ->call('save') onto a component after asserting mount() already 403s.
    // That chain is structurally invalid in Livewire 4: when mount() throws,
    // the initial render has no wire:snapshot for a later request to attach
    // to, and every ->set() call after it fails with "Invalid Livewire
    // snapshot structure" - a framework limitation, not a security gap. The
    // sibling test below already proves mount() alone blocks access; this
    // test proves the Action underneath does too.
    $bursar = userAs(Role::Bursar);

    app(\App\Modules\Identity\Actions\CreateUser::class)->handle(
        name: 'Someone',
        email: 'someone@school.test',
        role: Role::Teacher,
        plainPassword: 'Str0ng-Passw0rd',
        actor: $bursar,
    );
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

it('forbids reaching the form component directly without permission', function () {
    // A component can be reached without going through its route, so mount()
    // must authorise too, not just save().
    actingAs(userAs(Role::Bursar));

    Livewire::test(Form::class)->assertForbidden();
});

it('shows suspended users distinctly', function () {
    actingAs(userAs(Role::Administrator));
    User::factory()->create(['name' => 'Gone Away', 'status' => 'suspended']);

    Livewire::test(Index::class)->assertSee('Suspended');
});

it('renders through the real route inside the shell', function () {
    actingAs(userAs(Role::Administrator));

    get('/users')->assertOk()->assertSee('OPES');
});

it('still 403s for a bursar on the real route', function () {
    actingAs(userAs(Role::Bursar));

    get('/users')->assertForbidden();
});
