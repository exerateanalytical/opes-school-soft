<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AssessmentTestHelpers.php';

/**
 * RefreshDatabase is deliberately NOT used here, and its absence is the design
 * of this file rather than an oversight.
 *
 * RefreshDatabase wraps every test in one open transaction. T17 needs a SECOND
 * MySQL SESSION to contend for a row lock with the first, and a second session
 * cannot see - let alone block on - rows another connection has not committed.
 * The identical reasoning, and the identical remedy, are already written down
 * in tests/Unit/Support/SequenceTest.php for the sequence allocator.
 *
 * So this file commits for real and truncates between tests. tests/Pest.php
 * already binds Tests\TestCase to the whole Feature suite, so nothing is
 * declared here beyond the omission of RefreshDatabase.
 */

beforeEach(function (): void {
    assessmentTruncateAll();
    actingAs(assessmentPublisher());
});

afterEach(function (): void {
    DB::purge('assessment_second');

    // This file COMMITS its fixtures (no RefreshDatabase - T17's second
    // connection needs to see them), so truncating only in beforeEach cleans
    // up after the PREVIOUS test but leaves the LAST test's rows behind for
    // every RefreshDatabase file that runs after this one. Those files wrap
    // their work in a transaction on top of whatever is committed underneath,
    // and their absolute-count assertions (audit rows, user counts, KPI
    // totals) silently inherit this file's debris - the exact cascade that
    // broke 28 unrelated tests in the first full-suite run. Clean up on the
    // way out, not just on the way in.
    assessmentTruncateAll();
});

it('publishes a class group and writes one snapshot per student in one batch', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 3]);

    $result = app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        $fx['config_id'],
    );

    expect($result['published'])->toBe(1);
    expect($result['blocked'])->toBe(0);
    expect($result['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);
    expect($result['results'][0]['snapshots'])->toBe(3);

    expect(ReportCardSnapshot::query()->count())->toBe(3);
    expect(ReportCardSnapshot::query()->distinct()->count('snapshot_batch_id'))->toBe(1);

    /** @var PeriodPublication $publication */
    $publication = PeriodPublication::query()->firstOrFail();
    expect($publication->status)->toBe(PeriodPublication::STATUS_PUBLISHED);
    expect($publication->report_card_config_version_id)->toBe($fx['config_version_id']);

    // 13.1: the pinned version is now referenced by an issued document and is
    // frozen, so a later edit of the configurator cannot rewrite this card.
    expect(DB::table('report_card_config_versions')->where('id', $fx['config_version_id'])->value('frozen_at'))
        ->not->toBeNull();
});

it('prints the totals row and its stated derivation (13.6)', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1, 'subjects' => 2, 'score' => '13.000']);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();
    $totals = $snapshot->payload['totals'];

    // Coefficients 4 and 3, both subjects at 13.00.
    expect($totals['sum_coefficient'])->toBe('7.00');
    expect($totals['sum_score_times_coef'])->toBe('91.00');
    expect($totals['derivation'])->toBe('Moyenne = 91,00 / 7,00 = 13,00');
});

it('T14: one blocked class group does not block the others, and results are reported per class group', function () {
    // Four class groups. One of them has a pending mark under a framework whose
    // policy is block_publication, so it - and ONLY it - must fail its gates.
    $fx = assessmentFixture(['groups' => 4, 'students' => 2]);

    DB::table('assessment_frameworks')
        ->where('id', $fx['framework_id'])
        ->update(['missing_component_policy' => 'block_publication']);

    $blockedGroup = $fx['class_group_ids'][2];
    $victim = $fx['enrollments'][$blockedGroup][0];

    DB::table('marks')
        ->where('enrollment_id', $victim)
        ->where('subject_allocation_id', $fx['allocation_ids'][0])
        ->update(['state' => 'pending', 'score' => null]);

    $result = app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        $fx['config_id'],
    );

    expect($result['published'])->toBe(3);
    expect($result['blocked'])->toBe(1);

    $byGroup = [];

    foreach ($result['results'] as $row) {
        $byGroup[$row['class_group_id']] = $row;
    }

    expect($byGroup[$blockedGroup]['outcome'])->toBe(PublishPeriod::OUTCOME_BLOCKED);
    expect($byGroup[$blockedGroup]['failures'])->not->toBeEmpty();
    expect($byGroup[$blockedGroup]['snapshots'])->toBe(0);

    foreach ($fx['class_group_ids'] as $classGroupId) {
        if ($classGroupId === $blockedGroup) {
            continue;
        }

        expect($byGroup[$classGroupId]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);
        expect($byGroup[$classGroupId]['snapshots'])->toBe(2);
    }

    // The three healthy class groups are published and their cards exist. This
    // is the whole of C8: in v1 all four would be waiting on one teacher.
    expect(ReportCardSnapshot::query()->count())->toBe(6);
    expect(PeriodPublication::query()->where('status', PeriodPublication::STATUS_PUBLISHED)->count())->toBe(3);

    // The blocked group keeps a machine-readable record of WHY, all failures
    // together rather than one at a time (13.2).
    $blocking = PeriodPublication::query()
        ->where('class_group_id', $blockedGroup)
        ->value('blocking_report');

    expect($blocking)->not->toBeNull();
});

it('reports every failing gate together rather than one at a time', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2, 'requires_hod_validation' => true, 'requires_conseil' => true]);

    DB::table('marks')->update(['workflow_state' => 'draft']);

    $result = app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    expect($result['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_BLOCKED);

    $failures = implode(' | ', $result['results'][0]['failures']);

    expect($failures)->toContain('not yet validated');
    expect($failures)->toContain('conseil de classe');
    expect(ReportCardSnapshot::query()->count())->toBe(0);
});

it('T17: two concurrent publishes leave exactly one snapshot batch', function () {
    // Genuine concurrency, two real MySQL sessions.
    //
    // Session A opens a transaction and takes the publication row lock through
    // PublishPeriod's OWN `lockPublication()` - the same SELECT ... FOR UPDATE
    // the Action issues as its first statement, so A is in precisely the state
    // a mid-flight publisher is in, with real uncommitted state on a real
    // connection.
    //
    // Session B then runs the COMPLETE PublishPeriod::handle(). With a one
    // second innodb_lock_wait_timeout it must BLOCK and fail rather than read
    // the row A is holding: a publisher that did not take the lock would sail
    // past and write a second batch. When A commits and B runs again, B
    // publishes; A's own retry then finds the work done and writes nothing.
    //
    // The assertion at the end is T17 verbatim: one batch id, one snapshot per
    // student, no duplicates.
    $fx = assessmentFixture(['groups' => 1, 'students' => 3]);
    $classGroupId = $fx['class_group_ids'][0];

    config([
        'database.connections.assessment_second' => config('database.connections.'.config('database.default')),
    ]);

    $default = (string) config('database.default');

    DB::connection('assessment_second')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    DB::beginTransaction();

    try {
        app(PublishPeriod::class)->lockPublication($fx['period_id'], $classGroupId, Actor::system());

        DB::setDefaultConnection('assessment_second');

        $contended = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);

        DB::setDefaultConnection($default);

        expect($contended['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_FAILED);
        expect($contended['results'][0]['failures'][0])->toContain('Lock wait timeout');
    } finally {
        DB::setDefaultConnection($default);
        DB::commit();
    }

    // A has released the row. B publishes for real, on its own connection.
    DB::setDefaultConnection('assessment_second');

    try {
        $winner = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);
    } finally {
        DB::setDefaultConnection($default);
    }

    expect($winner['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_PUBLISHED);

    // A retries, on the original connection, unaware B finished.
    $loser = app(PublishPeriod::class)->handle($fx['period_id'], [$classGroupId], $fx['config_id']);

    expect($loser['results'][0]['outcome'])->toBe(PublishPeriod::OUTCOME_ALREADY_PUBLISHED);
    expect($loser['results'][0]['snapshots'])->toBe(0);

    expect(ReportCardSnapshot::query()->count())->toBe(3);
    expect(ReportCardSnapshot::query()->distinct()->count('snapshot_batch_id'))->toBe(1);
    expect(ReportCardSnapshot::query()->distinct()->count('enrollment_id'))->toBe(3);
});

it('un-publishes with a reason and retains every snapshot', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    expect(ReportCardSnapshot::query()->count())->toBe(2);

    app(PublishPeriod::class)->unpublish(
        $fx['period_id'],
        $fx['class_group_ids'][0],
        'Coefficients for Physique were configured wrongly for the whole level.',
    );

    /** @var PeriodPublication $publication */
    $publication = PeriodPublication::query()->firstOrFail();

    expect($publication->status)->toBe(PeriodPublication::STATUS_UNPUBLISHED);
    expect($publication->isIssuable())->toBeFalse();
    expect($publication->unpublish_reason)->toContain('Physique');

    // 13.2: "Snapshots are retained, never deleted; the card is simply no
    // longer issuable."
    expect(ReportCardSnapshot::query()->count())->toBe(2);
});

it('refuses to un-publish without a reason', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    app(PublishPeriod::class)->unpublish($fx['period_id'], $fx['class_group_ids'][0], '   ');
})->throws(Illuminate\Validation\ValidationException::class);
