<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Modules\Guardians\Models\Guardian;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Slice E of docs/specs/2026-08-11-guardian-mobile-api-v1.md - the first
 * WRITES on the guardian surface.
 *
 * Reuses the Slice B fixtures from GuardianPortalReadTest.php.
 *
 * A write API is judged by what it refuses, and the refusal that matters most
 * here is row 30: a guardian who could edit their own authorization flags could
 * grant themselves every other row in the matrix. That test is the reason this
 * file exists.
 */

/*
 * Declared per file, as every other suite in this repo does. Without it these
 * tests' rows survive into the next FILE and break its count-based assertions -
 * they pass in isolation, which is exactly how the omission went unnoticed.
 */
uses(RefreshDatabase::class);

it('lets a guardian correct their own contact details', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent());

    $response = patchJson('/api/v1/me/profile', [
        'city' => 'Bafoussam',
        'occupation' => 'Tailor',
    ], gmreadAuth($token));

    $response->assertOk();
    expect($guardian->refresh()->city)->toBe('Bafoussam');
    expect($guardian->occupation)->toBe('Tailor');
});

it('refuses - and audits - an attempt to edit an authorization flag', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent(), ['has_custody' => false]);

    $auditBefore = Schema::hasTable('audit_logs') ? DB::table('audit_logs')->count() : 0;

    // Row 30 is granted to NOBODY. This is the escalation the whole matrix
    // rests on: flip this flag and every other row follows.
    // NB the city value is deliberately NOT 'Douala' - GuardianFactory hard-codes
    // that, so asserting on it would pass whatever the endpoint did.
    patchJson('/api/v1/me/profile', [
        'city' => 'Ebolowa',
        'has_custody' => true,
    ], gmreadAuth($token))->assertForbidden();

    // The legitimate half of the request went with it: a refused request
    // changes nothing at all, rather than applying the parts it liked.
    expect($guardian->refresh()->city)->not->toBe('Ebolowa');

    if (Schema::hasTable('audit_logs')) {
        // A 403 the school never hears about teaches nobody anything.
        expect(DB::table('audit_logs')->count())->toBeGreaterThan($auditBefore);
    }
});

it('refuses a profile edit from a guardian whose every link has expired', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent(), [
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subYear()->toDateString(),
    ]);

    // Row 29 is granted on "any valid link". None is valid, so nothing is.
    patchJson('/api/v1/me/profile', ['city' => 'Kribi'], gmreadAuth($token))->assertForbidden();
});

it('normalises a phone number written a new way', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent());

    patchJson('/api/v1/me/profile', ['phone' => '+237 6 77 00 11 22'], gmreadAuth($token))->assertOk();

    // 7.7's duplicate detection is an EXACT match on this column, so a column
    // holding four spellings of one handset would never match.
    expect($guardian->refresh()->phone)->toBe(Guardian::normalisePhone('+237 6 77 00 11 22'));
});

it('returns an empty notification feed rather than another user\'s', function () {
    ['token' => $token] = gmreadGuardian();
    ['user' => $stranger] = gmreadGuardian();

    if (Schema::hasTable('notifications')) {
        DB::table('notifications')->insert([
            'user_id' => (int) $stranger->getKey(),
            'kind' => 'test',
            'title' => 'SOMEONE-ELSES-NOTIFICATION',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    $response = getJson('/api/v1/me/notifications', gmreadAuth($token));

    $response->assertOk();
    expect(json_encode($response->json()))->not->toContain('SOMEONE-ELSES-NOTIFICATION');
    expect($response->json('meta.unread'))->toBe(0);
});

it('answers 404 when marking another user\'s notification read', function () {
    ['token' => $token] = gmreadGuardian();
    ['user' => $stranger] = gmreadGuardian();

    if (! Schema::hasTable('notifications')) {
        expect(true)->toBeTrue();

        return;
    }

    $id = DB::table('notifications')->insertGetId([
        'user_id' => (int) $stranger->getKey(),
        'kind' => 'test',
        'title' => 'Not yours',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 404 rather than 403: whether a notification id exists is not a
    // guardian's business either.
    postJson('/api/v1/me/notifications/'.$id.'/read', [], gmreadAuth($token))->assertNotFound();

    expect(DB::table('notifications')->where('id', $id)->value('read_at'))->toBeNull();
});

it('answers 404 for a thread this guardian is not in', function () {
    ['token' => $token] = gmreadGuardian();

    getJson('/api/v1/me/threads/999999/messages', gmreadAuth($token))->assertNotFound();
    postJson('/api/v1/me/threads/999999/messages', ['body' => 'hello'], gmreadAuth($token))->assertNotFound();
});

it('lists an empty inbox without failing', function () {
    ['token' => $token] = gmreadGuardian();

    $response = getJson('/api/v1/me/threads', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('refuses announcements to a guardian with no valid link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent(), [
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subYear()->toDateString(),
    ]);

    getJson('/api/v1/me/announcements', gmreadAuth($token))->assertForbidden();
});

it('serves announcements on any valid link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent(), [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
    ]);

    getJson('/api/v1/me/announcements', gmreadAuth($token))->assertOk();
});

it('registers and unregisters a push device', function () {
    ['token' => $token] = gmreadGuardian();

    $endpoint = 'https://push.example.test/'.uniqid();

    postJson('/api/v1/me/devices/push', [
        'endpoint' => $endpoint,
        'p256dh' => 'test-p256dh-key',
        'auth' => 'test-auth-key',
        'platform' => 'android',
    ], gmreadAuth($token))->assertCreated();

    if (Schema::hasTable('push_subscriptions')) {
        expect(DB::table('push_subscriptions')->where('endpoint', $endpoint)->exists())->toBeTrue();
    }

    deleteJson('/api/v1/me/devices/push', ['endpoint' => $endpoint], gmreadAuth($token))->assertOk();

    if (Schema::hasTable('push_subscriptions')) {
        expect(DB::table('push_subscriptions')->where('endpoint', $endpoint)->exists())->toBeFalse();
    }

    // Idempotent: an app signing out must not be able to fail here.
    deleteJson('/api/v1/me/devices/push', ['endpoint' => $endpoint], gmreadAuth($token))->assertOk();
});

it('never lets one guardian unregister another\'s device', function () {
    ['token' => $mine] = gmreadGuardian();
    ['token' => $theirs] = gmreadGuardian();

    $endpoint = 'https://push.example.test/'.uniqid();

    postJson('/api/v1/me/devices/push', [
        'endpoint' => $endpoint,
        'p256dh' => 'k', 'auth' => 'a',
    ], gmreadAuth($theirs))->assertCreated();

    // Without this the second request runs as the FIRST guardian - one
    // container, one cached Sanctum guard - and the test would "pass" for
    // entirely the wrong reason. See gmreadSwitchPrincipal().
    gmreadSwitchPrincipal();

    // `endpoint` is unique table-wide, so an unscoped delete would be a hole.
    deleteJson('/api/v1/me/devices/push', ['endpoint' => $endpoint], gmreadAuth($mine))->assertOk();

    if (Schema::hasTable('push_subscriptions')) {
        expect(DB::table('push_subscriptions')->where('endpoint', $endpoint)->exists())->toBeTrue();
    }
});

it('refuses a meeting request from a link without custody', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => false]);

    postJson('/api/v1/me/children/'.$student.'/meetings', [
        'preferred_at' => now()->addWeek()->toDateTimeString(),
    ], gmreadAuth($token))->assertForbidden();
});

it('records a meeting request as requested by the guardian', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $response = postJson('/api/v1/me/children/'.$student.'/meetings', [
        'preferred_at' => now()->addWeek()->toDateTimeString(),
        'agenda' => 'Discuss term results',
    ], gmreadAuth($token));

    $response->assertCreated();
    // The distinction the office needs: an ask, not a booking.
    expect($response->json('data.requested_by'))->toBe('guardian');
    expect(DB::table('guardian_meetings')
        ->where('id', $response->json('data.id'))
        ->value('requested_by'))->toBe('guardian');
});

it('answers 404 on every write route for an unlinked child', function () {
    ['token' => $token] = gmreadGuardian();
    $other = gmreadStudent();

    postJson('/api/v1/me/children/'.$other.'/meetings', [
        'preferred_at' => now()->addWeek()->toDateTimeString(),
    ], gmreadAuth($token))->assertNotFound();

    postJson('/api/v1/me/children/'.$other.'/sanctions/1/ack', [], gmreadAuth($token))->assertNotFound();
});

it('refuses a write route to a read-only token', function () {
    // A token minted for reading must not be able to post, even though its
    // owner would be allowed to. That is the whole point of abilities.
    ['user' => $user, 'guardian' => $guardian] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $readOnly = $user->createToken('mobile:android:read-only', [
        App\Modules\Identity\Domain\Permission::PortalRead->value,
    ])->plainTextToken;

    postJson('/api/v1/me/children/'.$student.'/meetings', [
        'preferred_at' => now()->addWeek()->toDateTimeString(),
    ], gmreadAuth($readOnly))->assertForbidden();

    patchJson('/api/v1/me/profile', ['city' => 'Limbe'], gmreadAuth($readOnly))->assertForbidden();
});
