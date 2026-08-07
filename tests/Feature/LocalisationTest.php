<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;

it('has an English and a French label for every role', function () {
    foreach (Role::cases() as $role) {
        expect($role->label('en'))->not->toContain('opes.roles.');
        expect($role->label('fr'))->not->toContain('opes.roles.');
    }
});

it('has an English and a French label for every permission', function () {
    foreach (Permission::cases() as $permission) {
        expect($permission->label('en'))->not->toContain('opes.permissions.');
        expect($permission->label('fr'))->not->toContain('opes.permissions.');
    }
});

it('uses the Cameroonian titles rather than literal translations', function () {
    expect(Role::Principal->label('fr'))->toBe('Proviseur');
    expect(Role::VicePrincipal->label('fr'))->toBe('Censeur');
    expect(Role::ClassMaster->label('fr'))->toBe('Professeur Principal');
    expect(Role::DisciplineMaster->label('fr'))->toBe('Surveillant Général');
});

it('preserves accented characters through the translation layer', function () {
    // Guards against a file saved in the wrong encoding, which shows up as
    // mojibake on printed documents rather than as a test failure elsewhere.
    expect(Role::Bursar->label('fr'))->toBe('Économe');
    expect(mb_check_encoding(Role::Bursar->label('fr'), 'UTF-8'))->toBeTrue();
});

it('keeps the two language files structurally identical', function () {
    $en = require lang_path('en/opes.php');
    $fr = require lang_path('fr/opes.php');

    // A key present in one file and missing from the other surfaces in the UI
    // as a raw translation key, which is how half-translated products ship.
    expect(array_keys($en['roles']))->toBe(array_keys($fr['roles']));
    expect(array_keys($en['permissions']))->toBe(array_keys($fr['permissions']));
});

it('covers every enum case with a translation key', function () {
    $en = require lang_path('en/opes.php');

    foreach (Role::cases() as $role) {
        expect($en['roles'])->toHaveKey($role->value);
    }

    foreach (Permission::cases() as $permission) {
        expect($en['permissions'])->toHaveKey($permission->value);
    }
});
