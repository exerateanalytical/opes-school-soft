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

it('gives every role a bilingual label', function () {
    foreach (Role::cases() as $role) {
        expect($role->label('en'))->not->toBe('');
        expect($role->label('fr'))->not->toBe('');
    }
});

it('uses the Cameroonian title where one exists', function () {
    expect(Role::Principal->label('fr'))->toBe('Proviseur');
    expect(Role::VicePrincipal->label('fr'))->toBe('Censeur');
});

it('marks the two portal roles as portal roles', function () {
    expect(Role::Guardian->isPortal())->toBeTrue();
    expect(Role::StaffPortal->isPortal())->toBeTrue();
    expect(Role::Bursar->isPortal())->toBeFalse();
});

it('grants every permission to super admin and nothing to portal roles by default', function () {
    expect(Role::SuperAdmin->defaultPermissions())->toBe(Permission::cases());
    expect(Role::Guardian->defaultPermissions())->toBe([]);
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
