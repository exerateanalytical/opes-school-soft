<?php

declare(strict_types=1);

use App\Modules\Communication\Actions\Messaging\StartThread;
use App\Modules\Communication\Livewire\Messages\Index as MessagesIndex;
use App\Modules\Identity\Actions\FindUserIdByUsername;
use App\Modules\Identity\Actions\MarkUserOfficial;
use App\Modules\Identity\Actions\SetUsername;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Account;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
 * Handles and the official-account tick.
 *
 * Three things under test, and they are one feature: a user can be ADDRESSED
 * by a handle rather than an email address, and a reader can tell whether the
 * name attached to a message is the school's own account or somebody who
 * merely typed the school's name into their profile. The tick is only worth
 * anything if its subject cannot award it to themselves, so that refusal is
 * tested as hard as the happy path.
 */

function usernameUser(?Role $role = null): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role->value);
    }

    return $user->fresh();
}

it('lets a user claim a handle', function (): void {
    $user = usernameUser();

    app(SetUsername::class)->handle($user, 'amina.n', $user);

    expect($user->fresh()->username)->toBe('amina.n');
});

it('lower-cases a handle so case cannot be used to impersonate', function (): void {
    $user = usernameUser();

    app(SetUsername::class)->handle($user, '  Amina.N  ', $user);

    expect($user->fresh()->username)->toBe('amina.n')
        ->and(app(FindUserIdByUsername::class)->handle('AMINA.N'))->toBe((int) $user->getKey());
});

it('refuses a handle already taken, whatever its case', function (): void {
    $first = usernameUser();
    $second = usernameUser();

    app(SetUsername::class)->handle($first, 'bursar.office', $first);

    expect(fn () => app(SetUsername::class)->handle($second, 'Bursar.Office', $second))
        ->toThrow(DomainException::class);

    expect($second->fresh()->username)->toBeNull();
});

it('lets a user re-save their own handle unchanged', function (): void {
    // The uniqueness check excludes the target's own row; without that, the
    // account screen would refuse to save a form the user did not edit.
    $user = usernameUser();

    app(SetUsername::class)->handle($user, 'amina.n', $user);
    app(SetUsername::class)->handle($user, 'amina.n', $user);

    expect($user->fresh()->username)->toBe('amina.n');
});

it('refuses malformed handles', function (string $candidate): void {
    $user = usernameUser();

    expect(fn () => app(SetUsername::class)->handle($user, $candidate, $user))
        ->toThrow(DomainException::class);
})->with([
    'starts with a digit' => '1amina',
    'starts with a dot' => '.amina',
    'starts with an underscore' => '_amina',
    'consecutive dots' => 'amina..n',
    'trailing dot' => 'amina.',
    'contains an at sign' => 'amina@school',
    'contains a space' => 'amina n',
    'contains a hyphen' => 'amina-n',
    'too short' => 'am',
    'too long' => 'aminaaminaaminaaminaaminaaminaamina',
    'empty' => '',
]);

it('refuses a user setting somebody else\'s handle', function (): void {
    $user = usernameUser(Role::Teacher);
    $victim = usernameUser(Role::Teacher);

    expect(fn () => app(SetUsername::class)->handle($victim, 'stolen.handle', $user))
        ->toThrow(AuthorizationException::class);
});

it('lets a user set their own handle from the account screen', function (): void {
    $user = usernameUser(Role::Teacher);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('username', 'amina.n')
        ->call('saveUsername')
        ->assertHasNoErrors();

    expect($user->fresh()->username)->toBe('amina.n');
});

it('shows the format error on the account screen rather than throwing', function (): void {
    $user = usernameUser(Role::Teacher);

    Livewire::actingAs($user)
        ->test(Account::class)
        ->set('username', '..nope')
        ->call('saveUsername')
        ->assertHasErrors('username');

    expect($user->fresh()->username)->toBeNull();
});

/*
 * The tick.
 */

it('refuses a non-administrator marking an account official', function (): void {
    $teacher = usernameUser(Role::Teacher);

    expect(fn () => app(MarkUserOfficial::class)->handle($teacher, true, $teacher))
        ->toThrow(AuthorizationException::class);

    expect((bool) $teacher->fresh()->is_official)->toBeFalse();
});

it('lets an administrator mark an account official, and withdraw it', function (): void {
    $admin = usernameUser(Role::Administrator);
    $office = usernameUser();

    app(MarkUserOfficial::class)->handle($office, true, $admin);
    expect((bool) $office->fresh()->is_official)->toBeTrue();

    app(MarkUserOfficial::class)->handle($office, false, $admin);
    expect((bool) $office->fresh()->is_official)->toBeFalse();
});

it('does not let is_official be mass-assigned', function (): void {
    $user = User::factory()->create();

    $user->fill(['is_official' => true]);
    $user->save();

    expect((bool) $user->fresh()->is_official)->toBeFalse();
});

it('renders the tick beside an official sender and not beside a regular one', function (): void {
    $admin = usernameUser(Role::Administrator);
    $bursar = usernameUser();
    $bursar->name = 'Bursar Office';
    $bursar->save();
    app(MarkUserOfficial::class)->handle($bursar, true, $admin);

    $parent = usernameUser();
    $parent->name = 'Ordinary Parent';
    $parent->save();

    $thread = app(StartThread::class)->handle(
        (int) $bursar->getKey(),
        'Fees',
        [(int) $parent->getKey()],
        'Second instalment is due on Friday.',
    );

    // The parent reads the thread: the school's message carries the tick.
    Livewire::actingAs($parent)
        ->test(MessagesIndex::class)
        ->call('selectThread', (int) $thread->getKey())
        ->assertSee('Bursar Office')
        ->assertSee(__('opes.messages_screen.official_account'));

    // The parent replies, and their own name does not.
    Livewire::actingAs($parent)
        ->test(MessagesIndex::class)
        ->call('selectThread', (int) $thread->getKey())
        ->set('reply', 'Noted, thank you.')
        ->call('send')
        ->assertSee('Ordinary Parent');

    // With no official participant at all, the tick appears nowhere.
    $other = usernameUser();
    $other->name = 'Another Parent';
    $other->save();

    $plain = app(StartThread::class)->handle(
        (int) $other->getKey(),
        'Carpool',
        [(int) $parent->getKey()],
        'Can you take the children on Tuesday?',
    );

    Livewire::actingAs($parent)
        ->test(MessagesIndex::class)
        ->call('selectThread', (int) $plain->getKey())
        ->assertSee('Another Parent')
        ->assertDontSee(__('opes.messages_screen.official_account'));
});
