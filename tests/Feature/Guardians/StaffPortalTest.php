<?php

declare(strict_types=1);

// `/portal/staff` (docs/plans/phase-12-13.md 12.3) - the staff portal shell.
// Mirrors GuardianPortalFeesTest's shape for the `staff_portal` role's own
// door: `EnsureStaffPortal`'s two checks (portal.access + a `staff_members`
// row wired back via `portal_user_id`, both active) and the self-service
// password change, which is deliberately NOT `SetUserPassword`
// (Identity\Actions - the admin-resets-SOMEONE-ELSE's-password door) because
// the authenticated principal changing their OWN password needs no staff
// permission at all.

use App\Modules\HR\Livewire\Portal\Show;
use App\Modules\HR\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/P12PortalScreensHelpers.php';

uses(RefreshDatabase::class);

it('shows the activated staff portal principal their own profile', function () {
    ['staffId' => $staffId] = p12scrStaffPortalUser();

    DB::table('staff_members')->where('id', $staffId)->update([
        'first_name' => 'Adamou', 'last_name' => 'Njoya', 'staff_no' => 'STF-P12SP',
    ]);

    get(route('portal.staff'))
        ->assertOk()
        ->assertSee('Adamou')
        ->assertSee('Njoya')
        ->assertSee('STF-P12SP');
});

it('changes the principal\'s own password given the correct current password', function () {
    ['user' => $user] = p12scrStaffPortalUser();

    DB::table('users')->where('id', $user->getKey())->update([
        'password' => Hash::make('correct-horse-battery'),
        'must_change_password_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('currentPassword', 'correct-horse-battery')
        ->set('newPassword', 'a-brand-new-secret')
        ->set('newPasswordConfirmation', 'a-brand-new-secret')
        ->call('changePassword')
        ->assertHasNoErrors()
        ->assertDispatched('opes-portal-password-changed');

    $row = DB::table('users')->where('id', $user->getKey())->first(['password', 'must_change_password_at']);
    expect(Hash::check('a-brand-new-secret', (string) $row->password))->toBeTrue();
    expect($row->must_change_password_at)->toBeNull();
});

it('refuses the password change when the current password is wrong', function () {
    ['user' => $user] = p12scrStaffPortalUser();

    DB::table('users')->where('id', $user->getKey())->update(['password' => Hash::make('correct-horse-battery')]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('currentPassword', 'not-the-right-password')
        ->set('newPassword', 'a-brand-new-secret')
        ->set('newPasswordConfirmation', 'a-brand-new-secret')
        ->call('changePassword')
        ->assertHasErrors(['currentPassword']);

    $row = DB::table('users')->where('id', $user->getKey())->first(['password']);
    expect(Hash::check('correct-horse-battery', (string) $row->password))->toBeTrue();
});

it('refuses the password change when the confirmation does not match', function () {
    ['user' => $user] = p12scrStaffPortalUser();

    DB::table('users')->where('id', $user->getKey())->update(['password' => Hash::make('correct-horse-battery')]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('currentPassword', 'correct-horse-battery')
        ->set('newPassword', 'a-brand-new-secret')
        ->set('newPasswordConfirmation', 'a-different-secret')
        ->call('changePassword')
        ->assertHasErrors(['newPasswordConfirmation']);
});

it('refuses a new password shorter than 8 characters', function () {
    ['user' => $user] = p12scrStaffPortalUser();

    DB::table('users')->where('id', $user->getKey())->update(['password' => Hash::make('correct-horse-battery')]);

    Livewire::actingAs($user)
        ->test(Show::class)
        ->set('currentPassword', 'correct-horse-battery')
        ->set('newPassword', 'short')
        ->set('newPasswordConfirmation', 'short')
        ->call('changePassword')
        ->assertHasErrors(['newPassword']);
});

it('denies the staff portal shell to an authenticated user with no staff_members link', function () {
    p12scrGrantPortalAccess('staff_portal');
    $user = \App\Modules\Identity\Models\User::factory()->create();
    $user->assignRole('staff_portal');

    $this->actingAs($user->fresh() ?? $user);

    get(route('portal.staff'))->assertForbidden();
});

it('denies the staff portal shell when the linked staff_members row is not active', function () {
    ['user' => $user, 'staffId' => $staffId] = p12scrStaffPortalUser(login: false);

    DB::table('staff_members')->where('id', $staffId)->update(['status' => 'inactive']);

    $this->actingAs($user);

    get(route('portal.staff'))->assertForbidden();
});

it('denies the staff portal shell to a plain guardian portal principal - the two doors are independent', function () {
    ['user' => $user] = p12scrPortalGuardian();

    get(route('portal.staff'))->assertForbidden();
});

it('denies the guardian portal to a plain staff portal principal - the two doors are independent', function () {
    ['user' => $user] = p12scrStaffPortalUser();

    get(route('portal.dashboard'))->assertForbidden();
});

it('redirects an unauthenticated visitor to login', function () {
    get(route('portal.staff'))->assertRedirect('/login');
});

it('shows the scheduled panels for timetable, leave and payslips - the shell brief, not the wiring', function () {
    p12scrStaffPortalUser();

    get(route('portal.staff'))
        ->assertOk()
        ->assertSee(__('opes.staff_portal.panel_scheduled'));
});
