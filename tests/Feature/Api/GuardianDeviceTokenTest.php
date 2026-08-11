<?php

declare(strict_types=1);

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Identity\Actions\IssueDeviceToken;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Slice A of docs/specs/2026-08-11-guardian-mobile-api-v1.md: the mobile door.
 *
 * The interesting assertions here are the negative ones. A sign-in endpoint
 * that answers differently for "no such account" and "wrong password" is an
 * account-enumeration oracle, and for a school portal the fact being leaked is
 * "is this person a parent here" - so every failure mode is asserted to look
 * identical from outside.
 */

/**
 * @return array{user: User, guardian: Guardian}
 */
function gmapiGuardian(string $password = 'correct-horse-battery', bool $active = true): array
{
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
    SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);

    $user = User::factory()->create([
        'password' => $password,
        'status' => $active ? 'active' : 'suspended',
    ]);
    $user->assignRole('guardian');
    $user = $user->fresh() ?? $user;

    $guardian = Guardian::factory()->create(['portal_user_id' => $user->getKey()]);

    return ['user' => $user, 'guardian' => $guardian];
}

/**
 * @return array<string, string>
 */
function gmapiCredentials(User $user, string $password = 'correct-horse-battery'): array
{
    return [
        'identifier' => (string) $user->email,
        'password' => $password,
        'device_id' => 'device-abc-123',
        'device_name' => 'Pixel 8',
        'platform' => 'android',
    ];
}

it('issues a device token scoped to portal.read and portal.write only', function () {
    ['user' => $user, 'guardian' => $guardian] = gmapiGuardian();

    $response = postJson('/api/v1/auth/token', gmapiCredentials($user));

    $response->assertCreated();
    expect($response->json('data.abilities'))->toBe(['portal.read', 'portal.write']);
    expect($response->json('data.guardian.id'))->toBe((int) $guardian->getKey());
    expect($response->json('data.token'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.expires_at'))->toBeString();

    $token = $user->tokens()->firstOrFail();
    expect($token->name)->toBe(IssueDeviceToken::tokenName('android', 'device-abc-123'));
    expect($token->abilities)->toBe(['portal.read', 'portal.write']);
});

it('answers every credential failure identically', function () {
    ['user' => $user] = gmapiGuardian();

    $wrongPassword = postJson('/api/v1/auth/token', gmapiCredentials($user, 'not-the-password'));

    // A different IP-and-identifier key, so the 5/min sign-in budget of the
    // first call does not mask the second's real answer.
    $unknown = postJson('/api/v1/auth/token', [
        'identifier' => 'nobody@example.test',
        'password' => 'whatever',
        'device_id' => 'device-abc-123',
        'device_name' => 'Pixel 8',
        'platform' => 'android',
    ]);

    $wrongPassword->assertStatus(422);
    $unknown->assertStatus(422);
    expect($unknown->json('errors.identifier'))->toBe($wrongPassword->json('errors.identifier'));
});

it('refuses a suspended guardian account', function () {
    ['user' => $user] = gmapiGuardian(active: false);

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertStatus(422);
    expect($user->tokens()->count())->toBe(0);
});

it('refuses a user with no guardian row behind it', function () {
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
    SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);

    $user = User::factory()->create(['password' => 'correct-horse-battery', 'status' => 'active']);
    $user->assignRole('guardian');
    $user = $user->fresh() ?? $user;

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertStatus(422);
    expect($user->tokens()->count())->toBe(0);
});

it('refuses a staff account that holds no portal gate', function () {
    $user = User::factory()->create(['password' => 'correct-horse-battery', 'status' => 'active']);

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertStatus(422);
    expect($user->tokens()->count())->toBe(0);
});

it('replaces the previous token when the same device signs in again', function () {
    ['user' => $user] = gmapiGuardian();

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertCreated();
    $first = $user->tokens()->firstOrFail()->getKey();

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertCreated();

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->firstOrFail()->getKey())->not->toBe($first);
});

it('keeps a second device signed in', function () {
    ['user' => $user] = gmapiGuardian();

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertCreated();
    postJson('/api/v1/auth/token', array_merge(
        gmapiCredentials($user),
        ['device_id' => 'device-xyz-999', 'platform' => 'ios', 'device_name' => 'iPhone 15'],
    ))->assertCreated();

    expect($user->tokens()->count())->toBe(2);
});

it('lists devices without ever returning a token value', function () {
    ['user' => $user] = gmapiGuardian();
    $plain = postJson('/api/v1/auth/token', gmapiCredentials($user))->json('data.token');

    $response = getJson('/api/v1/auth/devices', ['Authorization' => 'Bearer '.$plain]);

    $response->assertOk();
    expect($response->json('data.0.platform'))->toBe('android');
    expect($response->json('data.0.is_current'))->toBeTrue();
    expect(json_encode($response->json()))->not->toContain(explode('|', (string) $plain)[1]);
});

it('revokes only the presented token on logout', function () {
    ['user' => $user] = gmapiGuardian();
    $phone = postJson('/api/v1/auth/token', gmapiCredentials($user))->json('data.token');
    postJson('/api/v1/auth/token', array_merge(
        gmapiCredentials($user),
        ['device_id' => 'device-xyz-999', 'platform' => 'ios', 'device_name' => 'iPhone 15'],
    ))->assertCreated();

    postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$phone])->assertOk();

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->firstOrFail()->name)->toContain('ios');
});

it('leaves staff integration tokens alone when signing out of every device', function () {
    ['user' => $user] = gmapiGuardian();
    $plain = postJson('/api/v1/auth/token', gmapiCredentials($user))->json('data.token');

    // An integration token hanging off the same user row.
    $user->createToken('MINESEC export', [OpesPermission::StudentsView->value]);

    postJson('/api/v1/auth/logout-all', [], ['Authorization' => 'Bearer '.$plain])->assertOk();

    expect($user->tokens()->count())->toBe(1);
    expect($user->tokens()->firstOrFail()->name)->toBe('MINESEC export');
});

it('refuses to rotate a staff integration token into a device token', function () {
    ['user' => $user] = gmapiGuardian();
    $staff = $user->createToken('MINESEC export', [
        OpesPermission::PortalRead->value,
        OpesPermission::StudentsView->value,
    ]);

    postJson('/api/v1/auth/refresh', [], ['Authorization' => 'Bearer '.$staff->plainTextToken])
        ->assertForbidden();
});

it('rotates a device token into a new secret for the same device', function () {
    ['user' => $user] = gmapiGuardian();
    $first = postJson('/api/v1/auth/token', gmapiCredentials($user))->json('data.token');

    $response = postJson('/api/v1/auth/refresh', [], ['Authorization' => 'Bearer '.$first]);

    $response->assertOk();
    expect($response->json('data.token'))->not->toBe($first);
    expect($user->tokens()->count())->toBe(1);
});

it('will not let a device token reach the staff integration surface', function () {
    ['user' => $user] = gmapiGuardian();
    $plain = postJson('/api/v1/auth/token', gmapiCredentials($user))->json('data.token');

    // students.view is neither held by the guardian role nor granted to the
    // token: both gates refuse, and either alone would be enough.
    getJson('/api/v1/students', ['Authorization' => 'Bearer '.$plain])->assertForbidden();
});

it('throttles the sign-in endpoint after five attempts', function () {
    ['user' => $user] = gmapiGuardian();

    for ($i = 0; $i < 5; $i++) {
        postJson('/api/v1/auth/token', gmapiCredentials($user, 'wrong'))->assertStatus(422);
    }

    postJson('/api/v1/auth/token', gmapiCredentials($user))->assertStatus(429);
});
