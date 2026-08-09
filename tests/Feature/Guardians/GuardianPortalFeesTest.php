<?php

declare(strict_types=1);

// `/portal/children/{student}/fees` (docs/plans/phase-12-13.md 12.2,
// 07-students.md 7.5 rows 13-17). The wide grant (`receives_invoices OR
// is_fee_payer`) sees the full statement/invoices/receipts; a link carrying
// only row 16's floor sees receipts alone, best-effort-matched to the
// guardian's own phone (ChildFeeStatement's documented KNOWN GAP - no
// `payer_guardian_id` column exists on `payments` yet).

use App\Modules\Guardians\Livewire\Portal\Fees;
use App\Modules\Guardians\Models\StudentGuardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\get;

require_once __DIR__.'/P12PortalScreensHelpers.php';

uses(RefreshDatabase::class);

it('shows the full statement, invoices and receipts to a receives_invoices guardian', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, ['receives_invoices' => true]);

    $enrollment = p12scrEnrollmentFor($studentId);
    p12scrInvoice($enrollment->getKey(), 150_000);
    p12scrPayment($studentId, $enrollment->getKey(), 50_000, '+237600000001');

    get(route('portal.children.fees', $studentId))
        ->assertOk()
        ->assertSee(__('opes.guardian_portal.fees_tab_statement'))
        ->assertSee(__('opes.guardian_portal.fees_tab_invoices'));
});

it('grants the wide fee scope on is_fee_payer alone, without receives_invoices', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, ['is_fee_payer' => true, 'receives_invoices' => false]);

    $enrollment = p12scrEnrollmentFor($studentId);
    p12scrInvoice($enrollment->getKey(), 75_000);

    get(route('portal.children.fees', $studentId))->assertOk();

    $component = Livewire::test(Fees::class, ['student' => $studentId]);
    $component->assertSet('canWide', true);
});

it('restricts a link holding only row 16 to receipts, best-effort matched to the guardian\'s own phone', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $guardian->phone = '+237677000001';
    $guardian->save();

    $studentId = p12scrStudent();
    // Every flag off but the link is still VALID - row 16 is "any valid
    // link", the floor every guardian keeps.
    p12scrLink($guardian->getKey(), $studentId, [
        'has_custody' => false, 'receives_reports' => false, 'receives_invoices' => false,
        'is_fee_payer' => false, 'is_emergency_contact' => false,
    ]);

    $enrollment = p12scrEnrollmentFor($studentId);
    // This guardian's own payment (phone matches)...
    p12scrPayment($studentId, $enrollment->getKey(), 20_000, '+237677000001', '2026-09-12');
    // ...and someone else's payment for the SAME child (phone does not
    // match) - row 17 territory, which this link does NOT hold.
    p12scrPayment($studentId, $enrollment->getKey(), 99_000, '+237699999999', '2026-09-13');

    $response = get(route('portal.children.fees', $studentId))->assertOk();
    $response->assertDontSee(__('opes.guardian_portal.fees_tab_statement'));

    $component = Livewire::test(Fees::class, ['student' => $studentId]);
    $component->assertSet('canWide', false);

    $html = $component->html();
    expect($html)->toContain('20');
    expect($html)->not->toContain('99');
});

it('denies the fees screen for a child the guardian is not linked to - row 32', function () {
    p12scrPortalGuardian();
    $unlinkedStudentId = p12scrStudent();

    get(route('portal.children.fees', $unlinkedStudentId))->assertForbidden();
});

it('denies fees for an expired link - historic access does not survive, except the guardian\'s own row-16 records', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();

    StudentGuardian::factory()->expired()->create([
        'guardian_id' => $guardian->getKey(),
        'student_id' => $studentId,
        'receives_invoices' => true,
    ]);

    get(route('portal.children.fees', $studentId))->assertForbidden();
});

it('redirects an unauthenticated visitor to login', function () {
    $studentId = p12scrStudent();

    get(route('portal.children.fees', $studentId))->assertRedirect('/login');
});

it('degrades cleanly when the child has no enrolment on file', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, ['receives_invoices' => true]);

    get(route('portal.children.fees', $studentId))
        ->assertOk()
        ->assertSee(__('opes.guardian_portal.fees_no_enrollment'));

    expect(DB::table('enrollments')->where('student_id', $studentId)->exists())->toBeFalse();
});
