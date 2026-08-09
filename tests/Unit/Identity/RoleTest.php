<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;

it('has exactly the twenty roles the spec names', function () {
    expect(Role::cases())->toHaveCount(20);
});

it('exposes stable string values usable as database keys', function () {
    expect(Role::SuperAdmin->value)->toBe('super_admin');
    expect(Role::Bursar->value)->toBe('bursar');
    expect(Role::ClassMaster->value)->toBe('class_master');
    expect(Role::Guardian->value)->toBe('guardian');
});

// Label assertions deliberately live in tests/Feature/LocalisationTest.php,
// not here. label() calls __(), which needs the translator from the container,
// and tests/Unit does not boot the application. Asserting them here failed with
// "Target class [translator] does not exist" regardless of whether the lang
// files existed - a red test that no later task could turn green.

it('marks the two portal roles as portal roles', function () {
    expect(Role::Guardian->isPortal())->toBeTrue();
    expect(Role::StaffPortal->isPortal())->toBeTrue();
    expect(Role::Bursar->isPortal())->toBeFalse();
});

it('grants every permission to super admin and only the portal gate to portal roles', function () {
    expect(Role::SuperAdmin->defaultPermissions())->toBe(Permission::cases());

    // Phase 12 (docs/plans/phase-12-13.md 12.5): portal roles hold exactly
    // ONE permission - portal.access, the outer gate to the /portal shell.
    // Everything else stays denied; AuthorizationMatrixTest asserts the
    // deny-by-default across the whole enum, this pins the exact list.
    expect(Role::Guardian->defaultPermissions())->toBe([Permission::PortalAccess]);
    expect(Role::StaffPortal->defaultPermissions())->toBe([Permission::PortalAccess]);
});

it('gives the bursar fee permissions but not ledger permissions', function () {
    $bursar = Role::Bursar->defaultPermissions();

    expect($bursar)->toContain(Permission::FeeCollect);
    expect($bursar)->not->toContain(Permission::LedgerPost);
});

it('names every permission with a module.action shape', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->value)->toMatch('/^[a-z_]+\.[a-z_]+$/');
    }
});
