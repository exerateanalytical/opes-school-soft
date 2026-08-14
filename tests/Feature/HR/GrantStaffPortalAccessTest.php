<?php

declare(strict_types=1);

use App\Modules\HR\Actions\GrantStaffPortalAccess;
use App\Modules\HR\Actions\HireStaffMember;
use App\Modules\Identity\Actions\FindUserIdByEmail;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
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
        $admin->toAuditActor(),
    );

    // The action hands back an ID rather than a model now (00-core §6.2 rule
    // 2), so the assertion re-reads the account instead of being handed one.
    // Same three facts checked as before, plus the forced password change
    // that used to be HR's own forceFill and is now ProvisionPortalUser's.
    $granted = User::query()->findOrFail($result['user_id']);

    expect($granted->hasRole(Role::StaffPortal->value))->toBeTrue();
    expect($granted->must_change_password_at)->not->toBeNull();
    expect(DB::table('staff_members')->where('id', $staffMember->id)->value('portal_user_id'))
        ->toBe($result['user_id']);
});

it('refuses to grant portal access twice to the same staff member', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Awa', lastName: 'Bello', gender: 'female', dateOfBirth: '1988-05-05',
        phone: '677000001', hiredOn: '2026-01-01', email: null,
    );

    app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa.bello@opeschool.test', $admin->toAuditActor());

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'awa2@opeschool.test', $admin->toAuditActor()))
        ->toThrow(DomainException::class, 'already has portal access');
});

it('refuses when the staff member has no email on file and none is supplied', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Paul', lastName: 'Mbarga', gender: 'male', dateOfBirth: '1992-03-03',
        phone: '677000002', hiredOn: '2026-01-01', email: null,
    );

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, null, $admin->toAuditActor()))
        ->toThrow(ValidationException::class);
});

it('refuses an actor who does not hold user.manage', function (): void {
    // This is the assertion the whole design turns on. Before this change HR
    // called Identity\Actions\CreateUser, which gates on `user.manage`.
    // Routing through ProvisionPortalUser to get HR off Identity's User model
    // would have SILENTLY DROPPED that gate, because ProvisionPortalUser's
    // only authority is an invitation code. The optional $authorisedBy actor
    // is what carries the check across the boundary, and this test is what
    // stops it being quietly removed again.
    //
    // Authenticated as the admin so HR's own Gate::authorize(hr.manage)
    // passes and the ONLY thing left to refuse the call is the user.manage
    // check inside Identity.
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    $unprivileged = User::factory()->create();

    expect($unprivileged->can(Permission::UserManage->value))->toBeFalse();

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Ngassa', lastName: 'Tchoua', gender: 'male', dateOfBirth: '1993-09-09',
        phone: '677000004', hiredOn: '2026-01-01', email: null,
    );

    expect(fn () => app(GrantStaffPortalAccess::class)->handle(
        (int) $staffMember->id,
        'ngassa.tchoua@opeschool.test',
        $unprivileged->toAuditActor(),
    ))->toThrow(AuthorizationException::class);

    // And it refused before writing anything: no account, no link.
    expect(app(FindUserIdByEmail::class)->handle('ngassa.tchoua@opeschool.test'))->toBeNull();
    expect(DB::table('staff_members')->where('id', $staffMember->id)->value('portal_user_id'))
        ->toBeNull();
});

it('refuses when the requested email already belongs to another user', function (): void {
    $admin = hrTestAdmin();
    $this->actingAs($admin);

    User::factory()->create(['email' => 'taken@opeschool.test']);

    $staffMember = app(HireStaffMember::class)->handle(
        firstName: 'Sonia', lastName: 'Eyenga', gender: 'female', dateOfBirth: '1991-07-07',
        phone: '677000003', hiredOn: '2026-01-01', email: null,
    );

    expect(fn () => app(GrantStaffPortalAccess::class)->handle((int) $staffMember->id, 'taken@opeschool.test', $admin->toAuditActor()))
        ->toThrow(ValidationException::class);
});
