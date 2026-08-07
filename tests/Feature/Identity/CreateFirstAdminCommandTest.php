<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

it('creates the first administrator when no user exists', function () {
    $exit = Artisan::call('opes:create-admin', [
        '--name' => 'Bootstrap Admin',
        '--email' => 'bootstrap@example.test',
    ]);

    expect($exit)->toBe(0);

    $user = User::query()->where('email', 'bootstrap@example.test')->first();

    expect($user)->not->toBeNull();
    expect($user?->status)->toBe('active');
    expect($user?->hasRole(Role::Administrator->value))->toBeTrue();
});

it('refuses to run and creates no second user when a user already exists', function () {
    User::factory()->create();

    $exit = Artisan::call('opes:create-admin', [
        '--name' => 'Second Admin',
        '--email' => 'second@example.test',
    ]);

    expect($exit)->toBe(1);
    expect(User::query()->count())->toBe(1);
    expect(User::query()->where('email', 'second@example.test')->exists())->toBeFalse();
});

it('writes an audit entry for the bootstrap creation', function () {
    Artisan::call('opes:create-admin', [
        '--name' => 'Bootstrap Admin',
        '--email' => 'bootstrap@example.test',
    ]);

    $user = User::query()->where('email', 'bootstrap@example.test')->firstOrFail();

    $entry = AuditLog::query()
        ->where('auditable_type', User::class)
        ->where('auditable_id', $user->getKey())
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry?->action)->toBe('created');
    expect($entry?->module)->toBe('Identity');
});

it('prints a strong generated password exactly once', function () {
    Artisan::call('opes:create-admin', [
        '--name' => 'Bootstrap Admin',
        '--email' => 'bootstrap@example.test',
    ]);

    $output = Artisan::output();

    $matched = preg_match('/Generated password.*?:\s*\n(\S+)/s', $output, $matches);

    expect($matched)->toBe(1);

    if ($matched !== 1) {
        throw new \RuntimeException('Generated password was not found in the command output.');
    }

    $password = $matches[1];
    expect(strlen($password))->toBeGreaterThanOrEqual(16);
});
