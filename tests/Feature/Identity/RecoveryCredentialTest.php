<?php

declare(strict_types=1);

use App\Modules\Identity\Actions\ConsumeRecoveryCredential;
use App\Modules\Identity\Actions\GenerateRecoveryCredential;
use App\Modules\Identity\Domain\RecoveryCode;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use App\Modules\Identity\Models\RecoveryCredential;
use App\Modules\Identity\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// A helper rather than beforeEach() + $this->admin: inside a Pest closure
// PHPStan resolves $this to Pest\PendingCalls\TestCall, which has neither a
// seed() method nor an $admin property, and level 8 rejects both. The same
// reasoning as the Artisan::call note in AuditChainTest - a typed helper
// gives the identical fixture without a suppression.
function recoveryAdmin(): User
{
    app(RolePermissionSeeder::class)->run();

    $admin = User::factory()->create(['name' => 'Admin One']);
    $admin->assignRole(Role::Administrator->value);

    return $admin;
}

it('stores only a hash, never the code itself', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());

    $stored = RecoveryCredential::query()->firstOrFail();
    $normalised = RecoveryCode::normalise($plain);

    expect($stored->code_hash)->not->toBe($plain);
    expect($stored->code_hash)->not->toBe($normalised);
    expect($stored->code_hash)->toStartWith('$argon2id$');
});

it('keeps exactly one active credential, revoking the previous', function () {
    $admin = recoveryAdmin();

    app(GenerateRecoveryCredential::class)->handle($admin);
    app(GenerateRecoveryCredential::class)->handle($admin);

    expect(RecoveryCredential::query()->whereNull('revoked_at')->whereNull('used_at')->count())->toBe(1);
    expect(RecoveryCredential::query()->count())->toBe(2);
});

it('expires after twelve months', function () {
    app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());

    $credential = RecoveryCredential::query()->firstOrFail();

    expect($credential->expires_at->greaterThan(now()->addMonths(11)))->toBeTrue();
    expect($credential->expires_at->lessThan(now()->addMonths(13)))->toBeTrue();
});

it('authenticates the earliest active administrator', function () {
    $admin = recoveryAdmin();
    $plain = app(GenerateRecoveryCredential::class)->handle($admin);

    $user = app(ConsumeRecoveryCredential::class)->handle($plain);

    expect($user?->is($admin))->toBeTrue();
});

it('is single use', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());

    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->not->toBeNull();
    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->toBeNull();
});

it('rejects an expired credential', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());

    RecoveryCredential::query()->update(['expires_at' => now()->subDay()]);

    expect(app(ConsumeRecoveryCredential::class)->handle($plain))->toBeNull();
});

it('rejects a wrong code', function () {
    app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());

    expect(app(ConsumeRecoveryCredential::class)->handle('AAAAA-BBBBB-CCCCC-DDDDD'))->toBeNull();
});

it('accepts the code however the operator writes it down', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());
    $messy = strtolower(str_replace('-', ' ', $plain));

    expect(app(ConsumeRecoveryCredential::class)->handle($messy))->not->toBeNull();
});

it('audits both generation and use', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());
    app(ConsumeRecoveryCredential::class)->handle($plain);

    expect(AuditLog::query()->where('action', 'recovery_generated')->count())->toBe(1);
    expect(AuditLog::query()->where('action', 'recovery_used')->count())->toBe(1);
});

it('never writes the plaintext code into the audit log', function () {
    $plain = app(GenerateRecoveryCredential::class)->handle(recoveryAdmin());
    app(ConsumeRecoveryCredential::class)->handle($plain);

    $normalised = RecoveryCode::normalise($plain);

    foreach (AuditLog::query()->get() as $entry) {
        $blob = json_encode($entry->getAttributes(), JSON_THROW_ON_ERROR);
        expect($blob)->not->toContain($normalised);
        expect($blob)->not->toContain($plain);
    }
});
