<?php

declare(strict_types=1);

use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Guardians\Support\PortalContext;
use App\Modules\Identity\Domain\Permission as OpesPermission;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;

use function Pest\Laravel\getJson;

/*
 * Slice B of docs/specs/2026-08-11-guardian-mobile-api-v1.md.
 *
 * The assertions that matter are the refusals. A read API over a guardian
 * portal fails safe only if an ABSENT flag, an EXPIRED link and an UNLINKED
 * child each produce the right answer - and for the unlinked child the right
 * answer is 404, because row 32 makes the child's existence itself a guarded
 * fact and a 403 would confirm it.
 */

/**
 * @return array{user: User, guardian: Guardian, token: string}
 */
function gmreadGuardian(): array
{
    app()->make(PermissionRegistrar::class)->forgetCachedPermissions();
    $access = Permission::findOrCreate(OpesPermission::PortalAccess->value, 'web');
    SpatieRole::findOrCreate('guardian', 'web')->givePermissionTo($access);

    $user = User::factory()->create(['status' => 'active']);
    $user->assignRole('guardian');
    $user = $user->fresh() ?? $user;

    $guardian = Guardian::factory()->create(['portal_user_id' => $user->getKey()]);

    $token = $user->createToken('mobile:android:test-device', [
        OpesPermission::PortalRead->value,
        OpesPermission::PortalWrite->value,
    ]);

    return ['user' => $user, 'guardian' => $guardian, 'token' => $token->plainTextToken];
}

/** @param array<string, mixed> $overrides */
function gmreadStudent(array $overrides = []): int
{
    $suffix = Str::upper(Str::random(6));

    return (int) DB::table('students')->insertGetId(array_merge([
        'matricule' => 'GMR-'.$suffix,
        'admission_no' => 'ADM/GMR/'.$suffix,
        'first_name' => 'Emmanuel',
        'last_name' => 'Ngo',
        'date_of_birth' => '2013-03-12',
        'gender' => 'male',
        'nationality' => 'CM',
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/** @param array<string, mixed> $flags */
function gmreadLink(int $guardianId, int $studentId, array $flags = []): StudentGuardian
{
    return StudentGuardian::factory()->create(array_merge([
        'guardian_id' => $guardianId,
        'student_id' => $studentId,
    ], $flags));
}

/** @return array<string, string> */
function gmreadAuth(string $token): array
{
    return ['Authorization' => 'Bearer '.$token];
}

/**
 * Forget everything the CONTAINER remembers about who is signed in.
 *
 * Production never needs this - each HTTP request gets a fresh container - but
 * a test makes several requests through ONE container, and two things survive
 * between them: Sanctum's guard caches the user it resolved, and
 * PortalContext::current() memoises itself as a container instance by design
 * (7.3 wants one business-date evaluation per request).
 *
 * So a test that acts as two DIFFERENT principals must call this between them,
 * or the second request silently runs as the first one - which looks exactly
 * like an authorization bug in the code under test and is not one.
 */
function gmreadSwitchPrincipal(): void
{
    app()->forgetInstance(PortalContext::class);
    app('auth')->forgetGuards();
}

it('returns the signed-in guardian and the business date the request was evaluated against', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();

    $response = getJson('/api/v1/me', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.guardian.id'))->toBe((int) $guardian->getKey());
    expect($response->json('data.guardian.display_name'))->toBe($guardian->fullName());
    expect($response->json('data.as_of'))->toBeString();
});

it('lists every child of a valid link, whatever the link\'s other flags are', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();

    $withEverything = gmreadStudent();
    $bareLink = gmreadStudent(['first_name' => 'Daniela']);

    gmreadLink((int) $guardian->getKey(), $withEverything, [
        'has_custody' => true, 'receives_reports' => true, 'receives_invoices' => true,
    ]);
    // Row 1 is the floor: no custody, no reports, no invoices - still listed.
    gmreadLink((int) $guardian->getKey(), $bareLink, [
        'has_custody' => false, 'receives_reports' => false,
        'receives_invoices' => false, 'is_fee_payer' => false,
        'is_emergency_contact' => false,
    ]);

    $response = getJson('/api/v1/me/children', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $bare = collect($response->json('data'))->firstWhere('id', $bareLink);
    expect($bare['capabilities'])->toContain('child.identity.view');
    expect($bare['capabilities'])->not->toContain('child.profile_detail.view');
    expect($bare['capabilities'])->not->toContain('fees.invoices.view');
});

it('hides the profile detail block from a link without custody', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => false]);

    $response = getJson('/api/v1/me/children/'.$student, gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.id'))->toBe($student);
    expect($response->json('data.detail'))->toBeNull();
});

it('shows the profile detail block to a custodial link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $response = getJson('/api/v1/me/children/'.$student, gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.detail.admission_no'))->toBeString();
});

it('answers 404 for a child the guardian has no link to', function () {
    ['token' => $token] = gmreadGuardian();
    $someoneElsesChild = gmreadStudent();

    getJson('/api/v1/me/children/'.$someoneElsesChild, gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$someoneElsesChild.'/guardians', gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$someoneElsesChild.'/medical', gmreadAuth($token))->assertNotFound();
});

it('answers 404 once the link has expired, not merely 403', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => true,
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subDay()->toDateString(),
    ]);

    // 7.5: an expired link grants NOTHING - including the periods it was
    // valid for, and including the child's existence.
    getJson('/api/v1/me/children/'.$student, gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children', gmreadAuth($token))->assertOk()->assertJsonCount(0, 'data');
});

it('gives an emergency contact the emergency records only', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'is_emergency_contact' => true,
    ]);

    $response = getJson('/api/v1/me/children/'.$student.'/medical', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.scope'))->toBe('emergency');
});

it('gives a custodial guardian the full medical record', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/medical', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.scope'))->toBe('full');
});

it('refuses medical to a link that is neither custodial nor an emergency contact', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'is_emergency_contact' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/medical', gmreadAuth($token))->assertForbidden();
});

it('lists other guardians by name and relationship only', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $other = Guardian::factory()->create(['id_number' => 'CNI-SECRET-0001']);
    gmreadLink((int) $other->getKey(), $student, ['has_custody' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/guardians', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.display_name'))->toBe($other->fullName());
    expect(array_keys($response->json('data.0')))->toBe(['display_name', 'relationship']);
    expect(json_encode($response->json()))->not->toContain('CNI-SECRET-0001');
    expect(json_encode($response->json()))->not->toContain((string) $other->phone);
});

it('refuses the other-guardians list to a non-custodial link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => false]);

    getJson('/api/v1/me/children/'.$student.'/guardians', gmreadAuth($token))->assertForbidden();
});

it('refuses the whole surface to a token without the portal.read ability', function () {
    ['user' => $user] = gmreadGuardian();
    $narrow = $user->createToken('mobile:android:narrow', [OpesPermission::PortalWrite->value]);

    getJson('/api/v1/me', gmreadAuth($narrow->plainTextToken))->assertForbidden();
});

it('refuses the whole surface to a staff user holding no portal gate', function () {
    $staff = User::factory()->create(['status' => 'active']);
    $token = $staff->createToken('integration', [OpesPermission::PortalRead->value]);

    getJson('/api/v1/me', gmreadAuth($token->plainTextToken))->assertForbidden();
});

it('refuses the whole surface once the guardian row is archived', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();

    $guardian->update(['is_archived' => true]);

    getJson('/api/v1/me', gmreadAuth($token))->assertForbidden();
});

it('refuses the whole surface once the user is suspended', function () {
    ['user' => $user, 'token' => $token] = gmreadGuardian();

    $user->update(['status' => 'suspended']);

    getJson('/api/v1/me', gmreadAuth($token))->assertForbidden();
});
