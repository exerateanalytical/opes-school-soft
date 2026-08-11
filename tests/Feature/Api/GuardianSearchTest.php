<?php

declare(strict_types=1);

use function Pest\Laravel\getJson;

/*
 * Slice F of docs/specs/2026-08-11-guardian-mobile-api-v1.md.
 *
 * Search leaks differently from a read: a COUNT, a snippet or an autocomplete
 * suggestion discloses that a record exists even when the record itself is
 * withheld. So the assertions here are about what never appears in results at
 * all - not about a filtered list being short.
 */

it('never returns another family\'s child, however exactly the name matches', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();

    $mine = gmreadStudent(['first_name' => 'Emmanuel', 'last_name' => 'Ngo']);
    // Same name, no link. The oldest search bug in the world.
    $notMine = gmreadStudent(['first_name' => 'Emmanuel', 'last_name' => 'Ngo']);

    gmreadLink((int) $guardian->getKey(), $mine);

    $response = getJson('/api/v1/me/search?q=Emmanuel', gmreadAuth($token));

    $response->assertOk();

    $ids = array_column($response->json('data.results'), 'student_id');

    expect($ids)->toContain($mine);
    expect($ids)->not->toContain($notMine);
});

it('returns nothing for an expired link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent(['first_name' => 'Solange']);

    gmreadLink((int) $guardian->getKey(), $student, [
        'valid_from' => now()->subYears(2)->toDateString(),
        'valid_to' => now()->subYear()->toDateString(),
    ]);

    $response = getJson('/api/v1/me/search?q=Solange', gmreadAuth($token));

    $response->assertOk();
    // 7.5 grants an expired link nothing - not even past periods, and not a
    // search hit that would confirm the child was ever theirs.
    expect($response->json('data.results'))->toBe([]);
});

it('refuses a one-character query rather than matching half the school', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    gmreadLink((int) $guardian->getKey(), gmreadStudent());

    $response = getJson('/api/v1/me/search?q=a', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.results'))->toBe([]);
    expect($response->json('meta.min_length'))->toBe(2);
});

it('treats a SQL wildcard as a literal, not as "everything"', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent(['first_name' => 'Bertrand']);
    gmreadLink((int) $guardian->getKey(), $student, ['receives_invoices' => true]);

    // Unescaped, `%` turns any LIKE into a match-all. It must match nothing
    // here, because no record actually contains a percent sign.
    $response = getJson('/api/v1/me/search?q=%25%25', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.results'))->toBe([]);
});

it('requires a query', function () {
    ['token' => $token] = gmreadGuardian();

    getJson('/api/v1/me/search', gmreadAuth($token))->assertStatus(422);
});

it('finds a child by matricule on a bare link', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    // Row 1 is the floor: identity is searchable however narrow the link is.
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
        'is_fee_payer' => false,
    ]);

    $matricule = (string) Illuminate\Support\Facades\DB::table('students')
        ->where('id', $student)->value('matricule');

    $response = getJson('/api/v1/me/search?q='.urlencode($matricule), gmreadAuth($token));

    $response->assertOk();
    expect(array_column($response->json('data.results'), 'student_id'))->toContain($student);
});

it('refuses search to a staff token', function () {
    $user = App\Modules\Identity\Models\User::factory()->create(['status' => 'active']);
    $staffToken = $user->createToken('integration', [
        App\Modules\Identity\Domain\Permission::StudentsView->value,
    ])->plainTextToken;

    // The isolation runs both ways: a staff integration token holds
    // students.view, answers no to abilities:portal.read, and cannot borrow
    // this surface.
    getJson('/api/v1/me/search?q=Emmanuel', gmreadAuth($staffToken))->assertForbidden();
});
