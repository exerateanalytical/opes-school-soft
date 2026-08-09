<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedRoles(): void
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
}

function userWithRole(Role $role): User
{
    seedRoles();
    $user = User::factory()->create();
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

it('seeds every role and every permission', function () {
    seedRoles();

    expect(\Spatie\Permission\Models\Role::query()->count())->toBe(count(Role::cases()));
    expect(\Spatie\Permission\Models\Permission::query()->count())->toBe(count(Permission::cases()));
});

it('gives super admin every permission', function () {
    $user = userWithRole(Role::SuperAdmin);

    foreach (Permission::cases() as $permission) {
        expect($user->can($permission->value))->toBeTrue("super admin lacks {$permission->value}");
    }
});

it('withholds licence and restore from a plain administrator', function () {
    $user = userWithRole(Role::Administrator);

    expect($user->can(Permission::LicenceManage->value))->toBeFalse();
    expect($user->can(Permission::BackupRestore->value))->toBeFalse();
    expect($user->can(Permission::UserManage->value))->toBeTrue();
});

it('lets a bursar collect fees but not post to the ledger', function () {
    $user = userWithRole(Role::Bursar);

    expect($user->can(Permission::FeeCollect->value))->toBeTrue();
    expect($user->can(Permission::LedgerPost->value))->toBeFalse();
});

it('gives portal roles no operational permission at all', function () {
    // Deny-by-default. This is the first expression of the principle that
    // 00-core 9.2 requires for the guardian boundary: a guardian's access is
    // decided per-child by GuardianScopeMatrix, never by a role.
    //
    // Phase 12 (docs/plans/phase-12-13.md 12.5) grants portal roles exactly
    // ONE permission - portal.access, the outer gate to the /portal shell.
    // It is not an operational right: what a guardian may actually see per
    // child is still the matrix's decision alone. Everything else remains
    // denied, and this test is what keeps that list at exactly one.
    foreach ([Role::Guardian, Role::StaffPortal] as $role) {
        $user = userWithRole($role);

        foreach (Permission::cases() as $permission) {
            if ($permission === Permission::PortalAccess) {
                expect($user->can($permission->value))->toBeTrue(
                    "{$role->value} should hold the portal gate {$permission->value}"
                );

                continue;
            }

            expect($user->can($permission->value))->toBeFalse(
                "{$role->value} should not hold {$permission->value}"
            );
        }
    }
});

it('scopes the academic permissions per the 00-core 9.1 matrix', function () {
    // The Censeur (Vice-Principal) shapes the academic structure; the roles
    // that read it - Principal, Registrar, Exams Officer, Class Master,
    // Teacher - view without managing.
    $censeur = userWithRole(Role::VicePrincipal);
    expect($censeur->can(Permission::AcademicsManage->value))->toBeTrue();
    expect($censeur->can(Permission::AcademicsView->value))->toBeTrue();

    foreach ([Role::Principal, Role::Registrar, Role::ExamsOfficer, Role::ClassMaster, Role::Teacher] as $role) {
        $user = userWithRole($role);

        expect($user->can(Permission::AcademicsView->value))->toBeTrue("{$role->value} should view academics");
        expect($user->can(Permission::AcademicsManage->value))->toBeFalse("{$role->value} should not manage academics");
    }

    // A role outside the matrix holds neither.
    $bursar = userWithRole(Role::Bursar);
    expect($bursar->can(Permission::AcademicsView->value))->toBeFalse();
    expect($bursar->can(Permission::AcademicsManage->value))->toBeFalse();
});

it('supports granting a single permission on top of a role baseline', function () {
    $user = userWithRole(Role::Bursar);

    expect($user->can(Permission::LedgerView->value))->toBeFalse();

    $user->givePermissionTo(Permission::LedgerView->value);

    expect($user->fresh()?->can(Permission::LedgerView->value))->toBeTrue();
    expect($user->fresh()?->can(Permission::LedgerPost->value))->toBeFalse();
});
