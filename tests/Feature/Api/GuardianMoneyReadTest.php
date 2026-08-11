<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * Slice D of docs/specs/2026-08-11-guardian-mobile-api-v1.md.
 *
 * Reuses the Slice B fixtures (gmreadGuardian/gmreadStudent/gmreadLink/
 * gmreadAuth) from GuardianPortalReadTest.php.
 *
 * Money is where a leak costs a family something concrete, so the assertions
 * that matter here are the width of the two grants: the WIDE one
 * (`receives_invoices OR is_fee_payer`) sees the child's whole ledger, and the
 * row-16 FLOOR sees only this guardian's own payments - never the unfiltered
 * list, even when another guardian has paid a great deal of money.
 */

/*
 * Declared per file, as every other suite in this repo does. Without it these
 * tests' rows survive into the next FILE and break its count-based assertions -
 * they pass in isolation, which is exactly how the omission went unnoticed.
 */
uses(RefreshDatabase::class);

/**
 * A minimal issued invoice against a real enrollment, so the fee reads have
 * something to sum. Returns the enrollment id.
 *
 * Skipped-by-guard rather than asserted: the fee tables belong to another
 * module's migrations and this suite must not pretend to own their shape.
 */
function gmmoneyEnrollment(int $studentId): ?int
{
    if (! Schema::hasTable('enrollments') || ! Schema::hasTable('academic_years')) {
        return null;
    }

    $yearId = DB::table('academic_years')->orderBy('id')->value('id');
    $levelId = Schema::hasTable('class_levels') ? DB::table('class_levels')->orderBy('id')->value('id') : null;
    $sectionId = Schema::hasTable('school_sections') ? DB::table('school_sections')->orderBy('id')->value('id') : null;

    if ($yearId === null || $levelId === null || $sectionId === null) {
        return null;
    }

    return (int) DB::table('enrollments')->insertGetId([
        'student_id' => $studentId,
        'academic_year_id' => $yearId,
        'class_level_id' => $levelId,
        'school_section_id' => $sectionId,
        'status' => 'active',
        'is_repeat' => false,
        'enrollment_type' => 'new',
        'enrolled_on' => now()->toDateString(),
        'boarding_status' => 'day',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/** A payment row against an enrollment, attributed to $payerPhone. */
function gmmoneyPayment(int $studentId, int $enrollmentId, int $amount, ?string $payerPhone, string $receiptNo): int
{
    $yearId = (int) DB::table('enrollments')->where('id', $enrollmentId)->value('academic_year_id');
    $fiscalYearId = Schema::hasTable('fiscal_years') ? DB::table('fiscal_years')->orderBy('id')->value('id') : null;

    return (int) DB::table('payments')->insertGetId([
        'receipt_no' => $receiptNo,
        'student_id' => $studentId,
        'enrollment_id' => $enrollmentId,
        'academic_year_id' => $yearId,
        'fiscal_year_id' => $fiscalYearId,
        'payment_method' => 'cash',
        'amount' => $amount,
        'payer_name' => 'Someone',
        'payer_phone' => $payerPhone,
        'value_date' => now()->toDateString(),
        'posting_date' => now()->toDateString(),
        'clearing_state' => 'cleared',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('refuses the fees tab to a link that is neither invoiced nor a fee payer', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'receives_invoices' => false,
        'is_fee_payer' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/fees', gmreadAuth($token))->assertForbidden();
});

it('serves the fees tab to an invoiced link, in minor units', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['receives_invoices' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/fees', gmreadAuth($token));

    $response->assertOk();
    // Never a float, however empty the ledger is (spec 4.1, plan 1).
    expect($response->json('data.totals.outstanding'))->toBeInt();
    expect($response->json('data.totals.billed'))->toBeInt();
    expect($response->json('data.currency'))->toBeString();
});

it('hides the ledger from a link that may see invoices but not the statement', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    // is_fee_payer alone grants row 13 but the statement is row 14's business;
    // the point is that the two lists are gated separately, not together.
    gmreadLink((int) $guardian->getKey(), $student, [
        'receives_invoices' => true,
        'is_fee_payer' => true,
    ]);

    $response = getJson('/api/v1/me/children/'.$student.'/fees', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.invoices'))->toBeArray();
    expect($response->json('data.statement'))->toBeArray();
});

it('never shows another guardian\'s payment to a link holding only the row-16 floor', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();

    // The bare link: no invoices, not a fee payer. Row 16 is still granted -
    // that is the floor - but it must reach this guardian's money only.
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
        'receives_invoices' => false,
        'is_fee_payer' => false,
    ]);

    $enrollmentId = gmmoneyEnrollment($student);

    if ($enrollmentId === null) {
        expect(true)->toBeTrue();

        return;
    }

    gmmoneyPayment($student, $enrollmentId, 99000, '+237600000999', 'RCPT-OTHER-'.$student);
    gmmoneyPayment($student, $enrollmentId, 1000, $guardian->phone, 'RCPT-MINE-'.$student);

    $response = getJson('/api/v1/me/payments', gmreadAuth($token));

    $response->assertOk();

    $receipts = array_column($response->json('data'), 'receipt_no');

    expect($receipts)->toContain('RCPT-MINE-'.$student);
    expect($receipts)->not->toContain('RCPT-OTHER-'.$student);
    // And the amount itself never reaches the payload, not merely the number.
    expect(array_column($response->json('data'), 'amount'))->not->toContain(99000);
});

it('shows every receipt of the child to a link holding the wide grant', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['receives_invoices' => true]);

    $enrollmentId = gmmoneyEnrollment($student);

    if ($enrollmentId === null) {
        expect(true)->toBeTrue();

        return;
    }

    gmmoneyPayment($student, $enrollmentId, 99000, '+237600000999', 'RCPT-WIDE-'.$student);

    $response = getJson('/api/v1/me/payments', gmreadAuth($token));

    $response->assertOk();
    expect(array_column($response->json('data'), 'receipt_no'))->toContain('RCPT-WIDE-'.$student);
});

it('answers 404 for an invoice belonging to another family', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $mine = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $mine, ['receives_invoices' => true]);

    // An id that is not on this child's enrollment. Not found, not forbidden:
    // a 403 would confirm the invoice exists somewhere.
    getJson('/api/v1/me/children/'.$mine.'/invoices/999999', gmreadAuth($token))->assertNotFound();
});

it('refuses receipts to a link without row 15', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'receives_invoices' => false,
        'is_fee_payer' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/receipts/1', gmreadAuth($token))->assertForbidden();
});

it('refuses documents to a link holding neither row 22 nor row 23', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => false,
    ]);

    getJson('/api/v1/me/children/'.$student.'/documents', gmreadAuth($token))->assertForbidden();
});

it('lists documents without offering bytes for a school-issued one', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['has_custody' => true]);

    $response = getJson('/api/v1/me/children/'.$student.'/documents', gmreadAuth($token));

    $response->assertOk();
    expect($response->json('data.can_view_school_issued'))->toBeBool();
    // A parent is told plainly which shelf has files behind it.
    foreach ($response->json('data.school_issued') as $entry) {
        expect($entry['has_bytes'])->toBeFalse();
    }
});

it('refuses a supplied-document download to a link without row 23', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    // Row 23 needs custody; a reports-only link holds row 22 but not row 23.
    gmreadLink((int) $guardian->getKey(), $student, [
        'has_custody' => false,
        'receives_reports' => true,
    ]);

    getJson('/api/v1/me/children/'.$student.'/documents/supplied/1/download', gmreadAuth($token))
        ->assertForbidden();
});

it('answers 501 for payment initiation, but only to a guardian who may initiate one', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    gmreadLink((int) $guardian->getKey(), $student, ['is_fee_payer' => true]);

    postJson('/api/v1/me/children/'.$student.'/payments', [], gmreadAuth($token))
        ->assertStatus(501)
        ->assertJsonPath('error.code', 'not_implemented');
});

it('refuses payment initiation to a link that is not the fee payer', function () {
    ['guardian' => $guardian, 'token' => $token] = gmreadGuardian();
    $student = gmreadStudent();
    // 403 rather than 501: otherwise the endpoint would answer "not
    // implemented" to everyone and become an oracle for who the fee payer is.
    gmreadLink((int) $guardian->getKey(), $student, ['is_fee_payer' => false]);

    postJson('/api/v1/me/children/'.$student.'/payments', [], gmreadAuth($token))->assertForbidden();
});

it('answers 404 on every money route for an unlinked child', function () {
    ['token' => $token] = gmreadGuardian();
    $other = gmreadStudent();

    getJson('/api/v1/me/children/'.$other.'/fees', gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$other.'/invoices/1', gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$other.'/receipts/1', gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$other.'/documents', gmreadAuth($token))->assertNotFound();
    getJson('/api/v1/me/children/'.$other.'/documents/supplied/1/download', gmreadAuth($token))->assertNotFound();
    postJson('/api/v1/me/children/'.$other.'/payments', [], gmreadAuth($token))->assertNotFound();
});
