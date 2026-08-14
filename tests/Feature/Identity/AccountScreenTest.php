<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Livewire\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/*
 * The staff shell shipped no /account, /profile or /me: a teacher,
 * accountant or registrar could not read their own record or change their
 * own password - only an Administrator could, from /users. The guardian
 * portal ships five account screens; the staff shell shipped zero.
 */

it('is reachable by a teacher, who holds no administrative permission', function (): void {
    p13moneyUserAs(Role::Teacher);

    get('/account')->assertOk();
});

it('lets a teacher change their own password', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::test(Account::class)
        ->set('currentPassword', 'old-password-1')
        ->set('newPassword', 'new-password-2')
        ->set('newPasswordConfirmation', 'new-password-2')
        ->call('changePassword')
        ->assertHasNoErrors();

    $hash = (string) DB::table('users')->where('id', $user->id)->value('password');
    expect(Hash::check('new-password-2', $hash))->toBeTrue();
});

it('refuses a password change that gets the current password wrong', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::test(Account::class)
        ->set('currentPassword', 'not-it')
        ->set('newPassword', 'new-password-2')
        ->set('newPasswordConfirmation', 'new-password-2')
        ->call('changePassword')
        ->assertHasErrors('currentPassword');
});

it('offers the account link and a live settings gear in the shell', function (): void {
    p13moneyUserAs(Role::Administrator);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/account"', escape: false);
    $response->assertSee('href="'.route('settings.index').'"', escape: false);
    $response->assertDontSee('Settings has no route yet', escape: false);
});

it('does not offer the settings gear to a teacher, whose permissions refuse the route', function (): void {
    p13moneyUserAs(Role::Teacher);

    $response = get('/dashboard');

    $response->assertOk();
    $response->assertSee('href="/account"', escape: false);
    $response->assertDontSee('href="'.route('settings.index').'"', escape: false);
});

it('refuses a new password under eight characters', function (): void {
    $user = p13moneyUserAs(Role::Teacher);
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make('old-password-1')]);

    Livewire::test(Account::class)
        ->set('currentPassword', 'old-password-1')
        ->set('newPassword', 'short')
        ->set('newPasswordConfirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors('newPassword');
});
