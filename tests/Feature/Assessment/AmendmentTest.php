<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\AmendMarks;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Assessment\Actions\RenderReportCard;
use App\Modules\Assessment\Models\Amendment;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * T15 - an amendment returns EVERY affected student, not only the corrected
 * one; the generation increments; superseded snapshots are retained.
 *
 * The fixture helpers live in PublicationTest.php in this directory; see the
 * note at the top of SnapshotTest.php.
 */
beforeEach(function (): void {
    assessmentTruncateAll();
    actingAs(assessmentPublisher());
});

// See PublicationTest's afterEach note: this file commits its fixtures, so it
// must clean up on the way out too, or its last test's rows leak into every
// RefreshDatabase file that runs after it - this file is alphabetically FIRST
// in the directory, so its debris reached ApprovalChainTest and beyond.
afterEach(function (): void {
    assessmentTruncateAll();
});

if (! function_exists('amendableFixture')) {
    /**
     * Four students with deliberately distinct averages, so that moving one
     * mark demonstrably moves other students' ranks. Coefficients 4 and 3:
     *
     *   s0  16, 13 => 103/7 = 14.71   rank 1
     *   s1  11, 18 =>  98/7 = 14.00   rank 3
     *   s2   9, 14 =>  78/7 = 11.14   rank 4
     *   s3  16, 13 => 103/7 = 14.71   rank 1  (competition tie, rank 2 skipped)
     *
     * @return array{fixture: array<string, mixed>, publication: PeriodPublication, enrollments: list<int>}
     */
    function amendableFixture(): array
    {
        $fx = assessmentFixture(['groups' => 1, 'students' => 4, 'subjects' => 2]);

        app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

        /** @var PeriodPublication $publication */
        $publication = PeriodPublication::query()->firstOrFail();

        return [
            'fixture' => $fx,
            'publication' => $publication,
            'enrollments' => $fx['enrollments'][$fx['class_group_ids'][0]],
        ];
    }
}

it('T15: an amendment returns every affected student, not only the corrected one', function () {
    ['fixture' => $fx, 'publication' => $publication, 'enrollments' => $enrollments] = amendableFixture();

    $corrected = $enrollments[2];

    $before = ReportCardSnapshot::query()
        ->where('enrollment_id', $corrected)
        ->firstOrFail();

    expect($before->payload['rank']['position'])->toBe(4);

    $markId = (int) DB::table('marks')
        ->where('enrollment_id', $corrected)
        ->where('subject_allocation_id', $fx['allocation_ids'][0])
        ->value('id');

    $result = app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '19.500']],
        'Transcription error: the CA sheet read 19.5 and 9 was keyed.',
    );

    // THE assertion. One mark moved; the corrected student's new average moves
    // the class mean, the standard deviation, the pass rate and every other
    // student's rank, all of which are already printed on the other three
    // cards. v1 would have reported one student and left three cards wrong.
    expect($result['affected_enrollment_ids'])->toHaveCount(4);
    expect($result['affected_enrollment_ids'])->toContain($corrected);

    foreach ($enrollments as $enrollmentId) {
        expect($result['affected_enrollment_ids'])->toContain($enrollmentId);
    }

    // The generation increments (15.2 step 3).
    expect($result['from_generation'])->toBe(1);
    expect($result['to_generation'])->toBe(2);
    $refreshedPublication = PeriodPublication::query()->findOrFail((int) $publication->getKey());
    expect($refreshedPublication)->toBeInstanceOf(PeriodPublication::class);
    expect((int) $refreshedPublication->generation)->toBe(2);

    // A new generation of snapshots for EVERY enrollment in the class group,
    // not only the corrected one (15.2 step 4).
    expect($result['snapshots_written'])->toBe(4);
    expect(ReportCardSnapshot::query()->where('generation', 2)->count())->toBe(4);

    // The superseded generation is RETAINED, never deleted.
    expect(ReportCardSnapshot::query()->where('generation', 1)->count())->toBe(4);
    expect(ReportCardSnapshot::query()->where('generation', 1)->whereNull('superseded_by_snapshot_id')->count())
        ->toBe(0);

    // And it still renders, byte for byte, as it was issued - a parent holding
    // the paper generation-1 card can have that exact document reproduced.
    $reissued = app(RenderReportCard::class)->handle((int) $before->getKey());
    expect($reissued['pdf_hash'])->toBe($before->pdf_hash);
    expect($reissued['generation'])->toBe(1);

    // The corrected student really did move to the top.
    /** @var ReportCardSnapshot $after */
    $after = ReportCardSnapshot::query()
        ->where('enrollment_id', $corrected)
        ->where('generation', 2)
        ->firstOrFail();

    expect($after->payload['rank']['position'])->toBe(1);

    $amendment = Amendment::query()->firstOrFail();
    expect($amendment->status)->toBe(Amendment::STATUS_APPLIED);
    expect($amendment->affected_enrollment_ids)->toHaveCount(4);
    expect($amendment->reason)->toContain('Transcription error');
    expect($amendment->mark_changes[0]['before']['score'])->toBe('9.000');
    expect($amendment->mark_changes[0]['after']['score'])->toBe('19.500');
});

it('freeze_at_publication keeps ranks and class statistics at their generation-1 values', function () {
    ['fixture' => $fx, 'publication' => $publication, 'enrollments' => $enrollments] = amendableFixture();

    $corrected = $enrollments[2];
    $bystander = $enrollments[0];

    /** @var ReportCardSnapshot $bystanderBefore */
    $bystanderBefore = ReportCardSnapshot::query()->where('enrollment_id', $bystander)->firstOrFail();

    $markId = (int) DB::table('marks')
        ->where('enrollment_id', $corrected)
        ->where('subject_allocation_id', $fx['allocation_ids'][0])
        ->value('id');

    app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '19.500']],
        'A 0.25-point class would not be recalled; this one is corrected but not reranked.',
        Amendment::POLICY_FREEZE_AT_PUBLICATION,
    );

    /** @var ReportCardSnapshot $bystanderAfter */
    $bystanderAfter = ReportCardSnapshot::query()
        ->where('enrollment_id', $bystander)
        ->where('generation', 2)
        ->firstOrFail();

    expect($bystanderAfter->payload['rank'])->toBe($bystanderBefore->payload['rank']);
    expect($bystanderAfter->payload['class_statistics'])->toBe($bystanderBefore->payload['class_statistics']);

    // 15.2: the card must SAY so, or a reader comparing two cards from one
    // class sees an inconsistency with no explanation.
    expect($bystanderAfter->payload['rank_frozen_at'])->not->toBeNull();

    /** @var ReportCardSnapshot $correctedAfter */
    $correctedAfter = ReportCardSnapshot::query()
        ->where('enrollment_id', $corrected)
        ->where('generation', 2)
        ->firstOrFail();

    // The corrected student's OWN numbers do move.
    expect($correctedAfter->payload['general_average']['rounded'])->toBe('17.140');
});

it('rejects a mark change written against a stale version (00-core 10.6)', function () {
    ['fixture' => $fx, 'publication' => $publication, 'enrollments' => $enrollments] = amendableFixture();

    $markId = (int) DB::table('marks')
        ->where('enrollment_id', $enrollments[0])
        ->where('subject_allocation_id', $fx['allocation_ids'][0])
        ->value('id');

    DB::table('marks')->where('id', $markId)->update(['version' => 7]);

    app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '15.000']],
        'Stale write.',
    );
})->throws(Illuminate\Validation\ValidationException::class);

it('refuses an amendment with no stated reason', function () {
    ['fixture' => $fx, 'publication' => $publication, 'enrollments' => $enrollments] = amendableFixture();

    $markId = (int) DB::table('marks')->where('enrollment_id', $enrollments[0])->value('id');

    app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '15.000']],
        '  ',
    );
})->throws(Illuminate\Validation\ValidationException::class);

it('refuses to amend a class group that was never published', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2]);

    $publication = app(PublishPeriod::class)->lockPublication(
        $fx['period_id'],
        $fx['class_group_ids'][0],
        App\Support\Audit\Actor::system(),
    );

    $markId = (int) DB::table('marks')->value('id');

    app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '15.000']],
        'Nothing has been issued yet.',
    );
})->throws(Illuminate\Validation\ValidationException::class);

it('records the amendment against the publication, never against a student', function () {
    ['fixture' => $fx, 'publication' => $publication, 'enrollments' => $enrollments] = amendableFixture();

    $markId = (int) DB::table('marks')
        ->where('enrollment_id', $enrollments[1])
        ->where('subject_allocation_id', $fx['allocation_ids'][1])
        ->value('id');

    app(AmendMarks::class)->handle(
        (int) $publication->getKey(),
        [['mark_id' => $markId, 'version' => 1, 'score' => '20.000']],
        'Marker added the two halves of the paper twice.',
    );

    // C10 in the schema: the amendment has no enrollment column at all, because
    // a correction is not a single-student edit.
    expect(Illuminate\Support\Facades\Schema::hasColumn('report_card_amendments', 'enrollment_id'))->toBeFalse();

    $amendment = Amendment::query()->firstOrFail();
    expect($amendment->period_publication_id)->toBe((int) $publication->getKey());
    expect($amendment->approved_by)->not->toBeNull();
    expect($amendment->approved_at)->not->toBeNull();
});
