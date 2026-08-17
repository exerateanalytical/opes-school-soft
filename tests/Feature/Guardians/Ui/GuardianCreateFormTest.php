<?php

declare(strict_types=1);

use App\Modules\Guardians\Livewire\Guardians\Index;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

/*
 * The create-guardian panel and CreateGuardian, joined up: the Action has
 * always taken `id_number` and used it for the tier-1 duplicate refusal
 * (07-students 7.1/7.7), but the form collected no such field, so the
 * refusal was unreachable from the UI. These cases exercise it through the
 * component, not the Action.
 */

if (! function_exists('guardianFormOperator')) {
    function guardianFormOperator(): User
    {
        app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(OpesPermission::GuardiansManage->value, 'web');

        $user = User::factory()->create();
        $user->givePermissionTo(OpesPermission::GuardiansManage->value);

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

it('sends the ID number typed into the form through to CreateGuardian', function (): void {
    guardianFormOperator();

    Livewire::test(Index::class)
        ->set('createFirstName', 'Bela')
        ->set('createLastName', 'Merceline')
        ->set('createPhone', '677112233')
        ->set('createIdNumber', 'CM-ID-4471209')
        ->call('saveGuardian')
        ->assertHasNoErrors();

    $guardian = Guardian::query()->where('last_name', 'Merceline')->firstOrFail();

    expect($guardian->id_number)->toBe('CM-ID-4471209')
        ->and($guardian->id_number_blind_index)->toBe(Guardian::blindIndexFor('CM-ID-4471209'));
});

it('blocks a second guardian bearing the same ID number, on the ID field', function (): void {
    guardianFormOperator();

    Livewire::test(Index::class)
        ->set('createFirstName', 'Bela')
        ->set('createLastName', 'Merceline')
        ->set('createPhone', '677112233')
        ->set('createIdNumber', 'CM-ID-4471209')
        ->call('saveGuardian')
        ->assertHasNoErrors();

    Livewire::test(Index::class)
        ->set('createFirstName', 'Bella')
        ->set('createLastName', 'Mercelin')
        ->set('createPhone', '677445566')
        ->set('createIdNumber', 'CM-ID-4471209')
        ->call('saveGuardian')
        ->assertHasErrors('createIdNumber');

    expect(Guardian::query()->count())->toBe(1);
});

it('still creates a guardian when the optional ID number is left blank', function (): void {
    guardianFormOperator();

    Livewire::test(Index::class)
        ->set('createFirstName', 'Ngwa')
        ->set('createLastName', 'Bertrand')
        ->set('createPhone', '699887766')
        ->call('saveGuardian')
        ->assertHasNoErrors();

    $guardian = Guardian::query()->where('last_name', 'Bertrand')->firstOrFail();

    expect($guardian->id_number)->toBeNull();
});
