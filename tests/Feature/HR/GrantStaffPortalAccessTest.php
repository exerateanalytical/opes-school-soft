<?php

declare(strict_types=1);

use App\Modules\HR\Actions\GrantStaffPortalAccess;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

if (! function_exists('hrTestAdmin')) {
    /**
     * The `administrator` and `staff_portal` roles only exist once
     * RolePermissionSeeder has run (mirrors DemoDataSeeder2::demoAdmin()) -
     * a bare `assignRole()` against an empty roles table throws
     * RoleDoesNotExist.
     */
    function hrTestAdmin(): User
    {
        (new RolePermissionSeeder())->run();

        $admin = User::factory()->create();
        $admin->assignRole(Role::Administrator->value);

        return $admin;
    }
}

it('grants a staff member their own portal login and links staff_members.portal_user_id', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Jean',
        lastName: 'Fotso',
        gender: 'male',
        dateOfBirth: '1990-01-01',
        phone: '677000000',
        hiredOn: '2026-01-01',
        email: null,
    );

    $result = app(GrantStaffPortalAccess::class)->handle(
        (int) $staffMember->id,
        'jean.fotso@opeschool.test',
        $admin,
    );

    expect($result['user']->hasRole(Role::StaffPortal->value))->toBeTrue();
    expect(DB::table('staff_members')->where('id', $staffMember->id)->value('portal_user_id'))
        ->toBe($result['user']->id);
});

it('refuses to grant portal access twice to the same staff member', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Awa', lastName: 'Bello', gender: 'female', dateOfBirth: '1988-05-05',
        phone: '677000001', hiredOn: '2026-01-01', email: null,
    );

    app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa.bello@opeschool.test', $admin);

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa2@opeschool.test', $admin))
        ->toThrow(DomainException::class, 'already has portal access');
});
