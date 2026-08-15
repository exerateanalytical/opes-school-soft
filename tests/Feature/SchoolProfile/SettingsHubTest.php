<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

function settingsHubUserAs(Role ...$roles): User
{
    (new RolePermissionSeeder)->run();
    $user = User::factory()->create();

    foreach ($roles as $role) {
        $user->assignRole($role->value);
    }

    $user = $user->fresh() ?? $user;
    actingAs($user);

    return $user;
}

it('shows every settings card an administrator may open', function (): void {
    // Administrator, not Principal: the Proviseur holds setting.VIEW but not
    // setting.EDIT (Role::permissions()), so School Identity and Branding -
    // both setting.edit routes - are correctly absent for him. The card list
    // is only fully populated for a role that can open every screen.
    settingsHubUserAs(Role::Administrator);

    get('/settings')
        ->assertOk()
        ->assertSee('School Identity')
        ->assertSee('Branding');
});

it('never renders a card the role cannot open', function (): void {
    // A Principal holds setting.view (so the hub itself opens) but not
    // academics.manage: the Academic Year card must be ABSENT, not disabled -
    // offering a link the route would refuse is the one thing the nav
    // contract forbids.
    settingsHubUserAs(Role::Principal);

    get('/settings')
        ->assertOk()
        ->assertDontSee('Academic Year & Terms');
});

it('summarises how many document images are set', function (): void {
    settingsHubUserAs(Role::Administrator);

    DB::table('school_document_profiles')->updateOrInsert(['id' => 1], [
        'crest_path' => 'branding/crest-abc.png',
        'logo_path' => 'branding/logo-abc.png',
        'state_header_enabled' => false,
        'bilingual_documents' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    get('/settings')->assertOk()->assertSee('2 of 5 images set');
});
