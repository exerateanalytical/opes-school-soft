<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PrintReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AssessmentTestHelpers.php';
require_once __DIR__.'/../Reporting/P13MoneyHelpers.php';

uses(RefreshDatabase::class);

/*
 * A published report card that a later rename makes UNPRINTABLE is the only
 * defect in the 2026-08-13 audits that destroys a statutory record for good.
 * The reprint re-renders and compares hashes; the school chrome is re-derived
 * LIVE into those bytes for every card (the registered payload carries no
 * `school` block), and the subject label is re-derived live too - it lands in
 * the bytes whenever the payload carries no `student` block, which is exactly
 * the shape of the documents stranded in production (see
 * reportCardMinimalSnapshotId in AssessmentTestHelpers.php).
 */

beforeEach(function (): void {
    p13moneyDocumentProfile();
    p13moneyConfirmedFiscalIdentity();
});

it('reprints a report card after the assessment period is renamed', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    reportCardMinimalSnapshotId($fx, $enrollmentId);

    $original = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($original->html)->toContain('Sequence 1');

    // The exact production event that stranded SCH/2026/RPT/000001: an
    // administrator corrects the period's name.
    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'First Sequence']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->isDuplicate)->toBeTrue();
    expect($reprint->html)->toContain('Sequence 1');
    expect($reprint->html)->not->toContain('First Sequence');
});

it('reprints a report card after the school profile is edited', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // The latent second vector: the letterhead is re-derived live because the
    // report-card payload carries no `school` key. Phase 4 of this plan makes
    // an administrator do this, so it must be survivable before Phase 4 runs.
    p13moneyDocumentProfile(['address_line1' => 'BP 4000, Rue Manga Bell', 'phone' => '+237 233 000 000']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->isDuplicate)->toBeTrue();
    expect($reprint->html)->not->toContain('Rue Manga Bell');
});

it('freezes the envelope at issue and refuses to let it be rewritten', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    app(PrintReportCard::class)->handle($fx['enrollments'][$fx['class_group_ids'][0]][0], $fx['period_id']);

    /** @var IssuedDocument $issued */
    $issued = IssuedDocument::query()->where('subject_type', 'Enrollment')->firstOrFail();

    expect($issued->render_envelope)->not->toBeNull();
    expect($issued->render_envelope['subject_label'] ?? null)->toContain('Sequence 1');
    expect($issued->render_envelope['school'] ?? null)->toBeArray();

    // The whole fix depends on this being append-only, exactly like
    // content_hash and payload_snapshot.
    expect(fn () => $issued->update(['render_envelope' => ['subject_label' => 'tampered']]))
        ->toThrow(RuntimeException::class, 'append-only');
});

it('backfills a legacy document that still reproduces, so the next rename cannot strand it', function (): void {
    actingAs(reportCardPublisher());

    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);
    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    $enrollmentId = $fx['enrollments'][$fx['class_group_ids'][0]][0];
    reportCardMinimalSnapshotId($fx, $enrollmentId);
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    // Reproduce a document issued BEFORE envelope freezing existed. The query
    // builder is the only way past the model's append-only guard.
    $issuedId = (int) DB::table('issued_documents')->where('subject_type', 'Enrollment')->value('id');
    DB::table('issued_documents')->where('id', $issuedId)->update(['render_envelope' => null]);

    // Nothing has been renamed, so this reprint still reproduces the recorded
    // hash - which is exactly when freezing the envelope is provably safe.
    app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);

    expect(DB::table('issued_documents')->where('id', $issuedId)->value('render_envelope'))->not->toBeNull();

    DB::table('assessment_periods')->where('id', $fx['period_id'])->update(['name' => 'Renamed After Backfill']);

    $reprint = app(PrintReportCard::class)->handle($enrollmentId, $fx['period_id']);
    expect($reprint->html)->toContain('Sequence 1');
    expect($reprint->html)->not->toContain('Renamed After Backfill');
});
