<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\SetUserPassword;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

function adminUser(): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $admin = User::factory()->create(['name' => 'Admin One']);
    $admin->assignRole(Role::Administrator->value);

    return $admin->fresh() ?? $admin;
}

it('sets a password without needing an email server', function () {
    $admin = adminUser();
    $target = User::factory()->create(['name' => 'Target User']);

    app(SetUserPassword::class)->handle($target, 'NewPassw0rd!', $admin);

    expect(Hash::check('NewPassw0rd!', $target->fresh()->password ?? ''))->toBeTrue();
});

it('hashes with argon2id', function () {
    $admin = adminUser();
    $target = User::factory()->create();

    app(SetUserPassword::class)->handle($target, 'NewPassw0rd!', $admin);

    expect($target->fresh()?->password)->toStartWith('$argon2id$');
});

it('forces a change on next login', function () {
    $admin = adminUser();
    $target = User::factory()->create();

    app(SetUserPassword::class)->handle($target, 'NewPassw0rd!', $admin);

    expect($target->fresh()?->must_change_password_at)->not->toBeNull();
});

it('audits the reset without recording either password', function () {
    $admin = adminUser();
    $target = User::factory()->create();

    app(SetUserPassword::class)->handle($target, 'NewPassw0rd!', $admin);

    $entry = AuditLog::query()->where('action', 'password_set')->firstOrFail();

    expect($entry->actor_name_at_time)->toBe('Admin One');

    $blob = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
    expect($blob)->not->toContain('NewPassw0rd!');
});

it('refuses when the actor lacks the permission', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $nobody = User::factory()->create();
    $nobody->assignRole(Role::Teacher->value);
    $target = User::factory()->create();

    app(SetUserPassword::class)->handle($target, 'NewPassw0rd!', $nobody->fresh() ?? $nobody);
})->throws(\Illuminate\Auth\Access\AuthorizationException::class);

it('promotes a user to administrator from the command line', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $target = User::factory()->create();

    \Illuminate\Support\Facades\Artisan::call('opes:promote-admin', ['email' => $target->email]);

    expect(\Illuminate\Support\Facades\Artisan::output())->toContain('Granted Administrator');
    expect($target->fresh()?->hasRole(Role::Administrator->value))->toBeTrue();
});

it('fails clearly when the email is unknown', function () {
    (new \Database\Seeders\RolePermissionSeeder())->run();

    $exit = \Illuminate\Support\Facades\Artisan::call('opes:promote-admin', ['email' => 'nobody@example.test']);

    expect($exit)->toBe(1);
});
