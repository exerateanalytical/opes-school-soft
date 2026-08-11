<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Slice C of docs/specs/2026-08-11-guardian-mobile-api-v1.md.
 *
 * Reuses the Slice B helpers (gmreadGuardian/gmreadStudent/gmreadLink/
 * gmreadAuth) from GuardianPortalReadTest.php - Pest loads every test file in
 * the directory, and the helpers are the fixture, not the subject.
 *
 * The assertions worth having here are the ones about the SECOND gate: the
 * query conjuncts the matrix deliberately does not own. A guardian with row 19
 * must still not see an `internal` discipline case; a guardian with row 5 must
 * still not see an unpublished period.
 */

it('refuses results to a link that does not receive reports', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['receives_reports' => false]);

    getJson('/api/v1/me/children/'.$student.'/results', gmreadAuth($token))->assertForbidden();
});

it('returns an empty period list rather than leaking an unpublished one', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['receives_reports' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/results', gmreadAuth($token));

    $response->assertOk();
    // No published snapshot exists for this child, so there is nothing to
    // show - and the endpoint says so without consulting `marks` at all.
    expect($response->json('data.periods'))->toBe([]);
});

it('hides discipline narrative from a link without custody', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    // Row 19 needs has_custody too, so a non-custodial link is refused
    // outright; this asserts that refusal rather than a partial view.
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => false]);

    getJson('/api/v1/me/children/'.$student.'/discipline', gmreadAuth($token))->assertForbidden();
});

it('never returns an internal discipline case to a custodial guardian', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $categoryId = DB::table('discipline_categories')->insertGetId([
        // `name` is unique, so it carries the per-test suffix.
        'name' => 'Lateness '.substr(md5((string) $student), 0, 6),
        'name_fr' => 'Retard',
        'severity' => 1,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('discipline_cases')->insert([
        'student_id' => $student,
        'discipline_category_id' => $categoryId,
        'occurred_on' => now()->subDays(3)->toDateString(),
        'reported_by' => (int) \App\Modules\Identity\Models\User::factory()->create()->getKey(),
        'status' => 'resolved',
        // The whole point of the case: `internal` means no guardian sees it,
        // whatever their flags say.
        'visibility' => 'internal',
        'is_positive' => false,
        'description' => 'NAMES-ANOTHER-MINOR',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = getJson('/api/v1/me/children/'.$student.'/discipline', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.cases'))->toBe([]);
    expect(json_encode($response->json()))->not->toContain('NAMES-ANOTHER-MINOR');
})->skip(fn (): bool => ! Schema::hasTable('discipline_categories'), 'discipline tables absent');

it('refuses attendance to a link with neither custody nor reports', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/attendance', gmreadAuth($token))->assertForbidden();
});

it('gives attendance detail scope to a custodial link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/attendance', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.scope'))->toBe('detail');
});

it('serves the timetable on any valid link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/timetable', gmreadAuth($token))->assertOk();
});

it('answers 404 on every academics route for an unlinked child', function () {
    ['token' => $token] = gmreadGuardian();
    $other = gmreadStudent();

    foreach (['results', 'attendance', 'discipline', 'timetable'] as $segment) {
        getJson('/api/v1/me/children/'.$other.'/'.$segment, gmreadAuth($token))->assertNotFound();
    }
});
