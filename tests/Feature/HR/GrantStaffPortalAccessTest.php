<?php

declare(strict_types=1);

use App\Modules\HR\Actions\GrantStaffPortalAccess;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

it('refuses when the staff member has no email on file and none is supplied', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Paul', lastName: 'Mbarga', gender: 'male', dateOfBirth: '1992-03-03',
        phone: '677000002', hiredOn: '2026-01-01', email: null,
    );

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, null, $admin))
        ->toThrow(ValidationException::class);
});

it('refuses when the requested email already belongs to another user', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    User::factory()->create(['email' => 'taken@opeschool.test']);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Sonia', lastName: 'Eyenga', gender: 'female', dateOfBirth: '1991-07-07',
        phone: '677000003', hiredOn: '2026-01-01', email: null,
    );

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'taken@opeschool.test', $admin))
        ->toThrow(ValidationException::class);
});
