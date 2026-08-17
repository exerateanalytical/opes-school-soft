<?php

declare(strict_types=1);

use App\Modules\HR\Livewire\Index;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

if (! function_exists('hrTestAdmin')) {
    /**
     * The `administrator` role only exists once RolePermissionSeeder has
     * run (mirrors DemoDataSeeder2::demoAdmin()) - a bare assignRole()
     * against an empty roles table throws RoleDoesNotExist.
     */
    function hrTestAdmin(): User
    {
        (new RolePermissionSeeder())->run();

        $admin = User::factory()->create();
        $admin->assignRole(Role::Administrator->value);

        return $admin->fresh() ?? $admin;
    }
}

it('hires a staff member through the Hire Staff Member modal with the full form filled in', function (): void {
    actingAs(hrTestAdmin());

    Livewire::test(Index::class)
        ->set('hireFirstName', 'Ngwa')
        ->set('hireLastName', 'Bertrand')
        ->set('hireOtherNames', 'Junior')
        ->set('hireGender', 'male')
        ->set('hireDateOfBirth', '1988-04-12')
        ->set('hirePlaceOfBirth', 'Buea')
        ->set('hireNationality', 'CM')
        ->set('hireMaritalStatus', 'married')
        ->set('hirePhone', '+237650000001')
        ->set('hireEmail', 'ngwa.bertrand@opeschool.test')
        ->set('hireHiredOn', '2026-09-01')
        ->set('hireNationalIdType', 'CNI')
        ->set('hireNationalIdNumber', 'CM-1234-5678')
        ->set('hireCnpsNumber', 'CNPS-778899')
        ->set('hireNiu', 'NIU-001122')
        ->set('hireBankName', 'Afriland First Bank')
        ->set('hireBankAccount', 'ACC-998877')
        ->set('hireMobileMoneyNumber', '+237650000001')
        ->set('hireNextOfKinName', 'Mbah Rose')
        ->set('hireNextOfKinRelationship', 'Spouse')
        ->set('hireNextOfKinPhone', '+237650000002')
        ->call('saveHire')
        ->assertHasNoErrors();

    $staff = StaffMember::query()->where('phone', '+237650000001')->firstOrFail();

    expect($staff->first_name)->toBe('Ngwa');
    expect($staff->last_name)->toBe('Bertrand');
    expect($staff->other_names)->toBe('Junior');
    expect($staff->place_of_birth)->toBe('Buea');
    expect($staff->status)->toBe('active');
});

it('rejects the hire form when a required field is missing', function (): void {
    actingAs(hrTestAdmin());

    Livewire::test(Index::class)
        ->set('hireFirstName', '')
        ->set('hireLastName', 'Bertrand')
        ->set('hireGender', 'male')
        ->set('hireDateOfBirth', '1988-04-12')
        ->set('hirePhone', '+237650000001')
        ->call('saveHire')
        ->assertHasErrors(['hireFirstName' => 'required']);

    expect(StaffMember::query()->where('last_name', 'Bertrand')->exists())->toBeFalse();
});
