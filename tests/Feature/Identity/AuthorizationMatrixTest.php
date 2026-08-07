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
    // decided per-child by GuardianScopeMatrix in Phase 2, never by a role.
    foreach ([Role::Guardian, Role::StaffPortal] as $role) {
        $user = userWithRole($role);

        foreach (Permission::cases() as $permission) {
            expect($user->can($permission->value))->toBeFalse(
                "{$role->value} should not hold {$permission->value}"
            );
        }
    }
});

it('supports granting a single permission on top of a role baseline', function () {
    $user = userWithRole(Role::Bursar);

    expect($user->can(Permission::LedgerView->value))->toBeFalse();

    $user->givePermissionTo(Permission::LedgerView->value);

    expect($user->fresh()?->can(Permission::LedgerView->value))->toBeTrue();
    expect($user->fresh()?->can(Permission::LedgerPost->value))->toBeFalse();
});
