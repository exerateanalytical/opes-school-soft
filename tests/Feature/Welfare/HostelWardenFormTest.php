<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Livewire\Hostel\Index;
use App\Modules\Welfare\Models\Hostel;
use Database\Factories\StaffMemberFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/HostelTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * SaveHostel has always validated an optional `warden_user_id` against the
 * users table, but the add-hostel panel collected no such field - so a
 * building could never be given a warden from the screen.
 */

if (! function_exists('p10WardenStaffUser')) {
    /** An active staff member with a portal account - a warden candidate. */
    function p10WardenStaffUser(string $staffNo = 'STF-WARDEN1'): User
    {
        $user = User::factory()->create(['name' => 'Warden Ngwa']);

        (new StaffMemberFactory())->create([
            'staff_no' => $staffNo,
            'portal_user_id' => $user->getKey(),
        ]);

        return $user;
    }
}

it('lists staff portal users as warden candidates and not everyone else', function (): void {
    p10HostelManager();

    $warden = p10WardenStaffUser();
    $stranger = User::factory()->create(['name' => 'Ordinary Guardian Account']);

    Livewire::test(Index::class)
        ->set('showHostelForm', true)
        ->assertSee($warden->name)
        ->assertDontSee($stranger->name);
});

it('saves the warden chosen on the add-hostel form', function (): void {
    p10HostelManager();

    $warden = p10WardenStaffUser();

    Livewire::test(Index::class)
        ->set('showHostelForm', true)
        ->set('hostelCode', 'HBW01')
        ->set('hostelName', 'Warden Hostel')
        ->set('hostelGender', 'boys')
        ->set('hostelWardenUserId', (string) $warden->getKey())
        ->call('saveHostel')
        ->assertHasNoErrors();

    $hostel = Hostel::query()->where('code', 'HBW01')->firstOrFail();

    expect($hostel->warden_user_id)->toBe((int) $warden->getKey());
});

it('leaves the warden unset when none is chosen', function (): void {
    p10HostelManager();

    Livewire::test(Index::class)
        ->set('showHostelForm', true)
        ->set('hostelCode', 'HBW02')
        ->set('hostelName', 'Unwardened Hostel')
        ->call('saveHostel')
        ->assertHasNoErrors();

    expect(Hostel::query()->where('code', 'HBW02')->firstOrFail()->warden_user_id)->toBeNull();
});
