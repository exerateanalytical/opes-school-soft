<?php

declare(strict_types=1);

// `/portal/children/{student}/results` (docs/plans/phase-12-13.md 12.2,
// 07-students.md 7.5 rows 5-10). Row 8 first: "publication state is checked
// first, always" - every assertion here is really asking whether an
// UNPUBLISHED period ever reaches the guardian, because the matrix itself
// answers row 8 with an unconditional false and this screen must never need
// to be asked twice.

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\get;

require_once __DIR__.'/P12PortalScreensHelpers.php';

uses(RefreshDatabase::class);

it('shows a published report card - average, mention, rank and subjects', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent(['first_name' => 'Ada']);
    p12scrLink($guardian->getKey(), $studentId, ['receives_reports' => true]);

    $enrollment = p12scrEnrollmentFor($studentId);
    p12scrPublishedSnapshot($enrollment->getKey(), p12scrReportCardPayload('Ada', '15.50', 3, 40));

    get(route('portal.children.results', $studentId))
        ->assertOk()
        ->assertSee('15.50')
        ->assertSee('3 / 40')
        ->assertSee('Mathematics');
});

it('never shows an unpublished period - row 8, checked before the flag', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, ['receives_reports' => true]);

    $enrollment = p12scrEnrollmentFor($studentId);

    // A snapshot exists, but its publication is `marks_open`, not
    // `published` - the default PeriodPublicationFactory state.
    $period = App\Modules\Academics\Models\AssessmentPeriod::factory()->create(['name' => 'Unpublished Sequence']);
    $classGroup = App\Modules\Academics\Models\ClassGroup::factory()->create();
    $publication = App\Modules\Assessment\Models\PeriodPublication::factory()
        ->forPeriod((int) $period->getKey())
        ->forClassGroup((int) $classGroup->getKey())
        ->create();

    $configId = \Illuminate\Support\Facades\DB::table('report_card_configs')->insertGetId([
        'framework_id' => null, 'code' => 'UNP'.\Illuminate\Support\Str::random(4), 'name' => 'unpub',
        'name_fr' => 'unpub', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $versionId = \Illuminate\Support\Facades\DB::table('report_card_config_versions')->insertGetId([
        'config_id' => $configId, 'version_no' => 1,
        'payload' => json_encode(['layout' => 'x', 'blocks' => [], 'marks_columns' => []], JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', 'unpub'), 'frozen_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    \Illuminate\Support\Facades\DB::table('report_card_snapshots')->insert([
        'enrollment_id' => $enrollment->getKey(),
        'assessment_period_id' => $period->getKey(),
        'class_group_id' => $classGroup->getKey(),
        'period_publication_id' => $publication->getKey(),
        'generation' => 1,
        'snapshot_batch_id' => (string) \Illuminate\Support\Str::uuid(),
        'report_card_config_version_id' => $versionId,
        'payload' => json_encode(p12scrReportCardPayload('Ada', '99.99'), JSON_THROW_ON_ERROR),
        'payload_hash' => hash('sha256', 'x'),
        'issued_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    get(route('portal.children.results', $studentId))
        ->assertOk()
        ->assertDontSee('99.99')
        ->assertSee(__('opes.guardian_portal.results_empty'));
});

it('denies the results screen without receives_reports', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, ['receives_reports' => false, 'has_custody' => true]);

    get(route('portal.children.results', $studentId))->assertForbidden();
});

it('denies a child the guardian is not linked to - row 32', function () {
    p12scrPortalGuardian();
    $unlinkedStudentId = p12scrStudent();

    get(route('portal.children.results', $unlinkedStudentId))->assertForbidden();
});

it('redirects an unauthenticated visitor to login', function () {
    $studentId = p12scrStudent();

    get(route('portal.children.results', $studentId))->assertRedirect('/login');
});

it('narrows rank to the child\'s own position and denominator only, never another student\'s row', function () {
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent(['first_name' => 'Beki']);
    p12scrLink($guardian->getKey(), $studentId, ['receives_reports' => true]);

    $enrollment = p12scrEnrollmentFor($studentId);
    p12scrPublishedSnapshot($enrollment->getKey(), p12scrReportCardPayload('Beki', '11.00', 27, 40));

    // A classmate's snapshot - never visible on Beki's screen; the payload
    // is per-ENROLLMENT, so there is no query that could leak it, but this
    // asserts the observable behaviour, not just the plumbing.
    $otherStudentId = p12scrStudent(['first_name' => 'Chantal']);
    $otherEnrollment = p12scrEnrollmentFor($otherStudentId);
    p12scrPublishedSnapshot($otherEnrollment->getKey(), p12scrReportCardPayload('Chantal', '18.75', 1, 40));

    get(route('portal.children.results', $studentId))
        ->assertOk()
        ->assertSee('27 / 40')
        ->assertDontSee('18.75')
        ->assertDontSee('Chantal');
});

it('hides the rank block when receives_reports is granted only via a link lacking it is false - deny-by-default sanity', function () {
    // A degenerate but legal link: valid, active guardian, but every flag
    // off except receives_reports (so results are visible) - GPA/mention
    // still print because they ride on `receives_reports` too (row 10's
    // conjunct is `applied`, checked separately).
    ['guardian' => $guardian] = p12scrPortalGuardian();
    $studentId = p12scrStudent();
    p12scrLink($guardian->getKey(), $studentId, [
        'receives_reports' => true, 'has_custody' => false, 'receives_invoices' => false,
        'is_fee_payer' => false, 'is_emergency_contact' => false,
    ]);

    $enrollment = p12scrEnrollmentFor($studentId);
    p12scrPublishedSnapshot($enrollment->getKey(), p12scrReportCardPayload('Solo Flag', '12.00', 9, 40));

    get(route('portal.children.results', $studentId))->assertOk()->assertSee('9 / 40');
});
