<?php

declare(strict_types=1);

use App\Modules\Assessment\Actions\ConfigureReportCard;
use App\Modules\Assessment\Actions\PublishPeriod;
use App\Modules\Assessment\Actions\RenderReportCard;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use Database\Factories\ReportCardConfigFactory;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/**
 * T13 and T19.
 *
 * `assessmentFixture()`, `assessmentPublisher()` and `assessmentTruncateAll()`
 * are declared in PublicationTest.php in this directory. Pest includes every
 * file in a suite before running any test in it, so they are available here;
 * run this directory, not this file in isolation.
 *
 * RefreshDatabase is absent for the same reason it is absent there - see that
 * file's header.
 */
beforeEach(function (): void {
    assessmentTruncateAll();
    actingAs(assessmentPublisher());
});

it('T13: mutating the marks, coefficients, bands and config leaves the pdf_hash identical', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 3, 'subjects' => 2]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->orderBy('id')->firstOrFail();
    $snapshotId = (int) $snapshot->getKey();

    $before = app(RenderReportCard::class)->handle($snapshotId);

    // The hash stored at issue and the hash produced by re-rendering must
    // already agree, or the rest of this test proves nothing.
    expect($before['pdf_hash'])->toBe($snapshot->pdf_hash);

    // --- Now move every single thing the card was derived from. ------------

    // 1. The marks.
    DB::table('marks')->update(['score' => '19.000', 'version' => 2]);

    // 2. The coefficients.
    DB::table('subject_allocations')->update(['coefficient' => '9.00', 'version' => 2]);

    // 3. The grade bands - the mention printed beside the average.
    DB::table('grade_bands')->update(['mention' => 'REWRITTEN', 'label' => 'REWRITTEN']);

    // 4. The report card configuration: different columns, different blocks,
    //    different branding.
    $rewritten = ReportCardConfigFactory::bulletinPayload();
    $rewritten['branding']['accent_colour'] = '#ff0000';
    $rewritten['layout'] = 'something_else_entirely';
    $rewritten['marks_columns'] = [
        ['key' => 'subject_name', 'label_fr' => 'RENAMED'],
    ];

    $reconfigured = app(ConfigureReportCard::class)->handle($fx['framework_id'], 'BULLETIN', $rewritten);

    // The config edit was REAL: the pinned version was frozen by publication,
    // so this produced a successor rather than a mutation. If this assertion
    // ever fails, the hash assertion below is passing for the wrong reason.
    expect($reconfigured['created_new_version'])->toBeTrue();
    expect($reconfigured['version_id'])->not->toBe($fx['config_version_id']);
    expect(DB::table('report_card_config_versions')->where('id', $fx['config_version_id'])->value('frozen_at'))
        ->not->toBeNull();

    // --- Re-render. --------------------------------------------------------

    $after = app(RenderReportCard::class)->handle($snapshotId);

    expect($after['pdf_hash'])->toBe($before['pdf_hash']);
    expect($after['card'])->toBe($before['card']);

    // And the payload itself is untouched: 13.3's "the snapshot is
    // authoritative" is a statement about the row, not only about the render.
    /** @var ReportCardSnapshot $reread */
    $reread = ReportCardSnapshot::query()->findOrFail($snapshotId);
    expect($reread->payload_hash)->toBe($snapshot->payload_hash);
    expect(ReportCardSnapshot::hashOf($reread->payload))->toBe($snapshot->payload_hash);
});

it('proves the T13 mutation was capable of changing a card, by publishing a second class group after it', function () {
    // Without this, T13 could pass because the mutations were inert. Two class
    // groups: publish the first, mutate, then publish the SECOND from the same
    // live rows. Its card must differ - which is exactly what the first card's
    // unchanged hash is protecting against.
    $fx = assessmentFixture(['groups' => 2, 'students' => 2, 'subjects' => 2]);

    app(PublishPeriod::class)->handle($fx['period_id'], [$fx['class_group_ids'][0]], $fx['config_id']);

    $firstCard = app(RenderReportCard::class)->handle(
        (int) ReportCardSnapshot::query()->orderBy('id')->firstOrFail()->getKey(),
    );

    DB::table('marks')->update(['score' => '19.000']);
    DB::table('subject_allocations')->update(['coefficient' => '9.00']);

    app(PublishPeriod::class)->handle($fx['period_id'], [$fx['class_group_ids'][1]], $fx['config_id']);

    /** @var ReportCardSnapshot $second */
    $second = ReportCardSnapshot::query()
        ->where('class_group_id', $fx['class_group_ids'][1])
        ->orderBy('id')
        ->firstOrFail();

    expect($second->pdf_hash)->not->toBe($firstCard['pdf_hash']);
});

it('stores the resolved values, not references to live rows', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1, 'subjects' => 2, 'score' => '13.000']);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();
    $payload = $snapshot->payload;

    // Every number a reader checks by hand is in the document itself.
    expect($payload['subjects'][0]['coefficient'])->toBe('4.00');
    expect($payload['subjects'][0]['subject_score'])->toBe('13.00');
    expect($payload['subjects'][0]['score_times_coef'])->toBe('52.00');
    expect($payload['general_average']['rounded'])->toBe('13.000');
    expect($payload['mention'])->toBe('Assez Bien');
    expect($payload['class_statistics']['n'])->toBe(1);
    expect($payload['class_statistics'])->toHaveKey('stdev_population');
});

it('leaves a named hole for attendance rather than inventing absence hours (14)', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();

    $attendance = $snapshot->payload['attendance'];

    expect($attendance['available'])->toBeFalse();
    expect($attendance['hours_absent_justified'])->toBeNull();
    expect($attendance['hours_absent_unjustified'])->toBeNull();
    expect($attendance['note'])->toContain('not yet installed');

    // The block still renders, carrying the explanation. A silently omitted
    // block reads as "this student was never absent".
    $card = app(RenderReportCard::class)->handle((int) $snapshot->getKey());
    expect($card['card']['blocks']['absence_hours']['available'])->toBeFalse();
});

it('T19: Family F omits rank, average, mention and sigma-coef from the payload AND the card', function () {
    // The configuration used here is the FULL secondary bulletin - it asks for
    // Coef, MxCoef, Rang and Appreciation columns and enables the average,
    // mention, totals and statistics blocks. The suppression must therefore
    // come from the framework and the payload, not from a nursery-shaped
    // config, or 8.4 would hold only for administrators who configured it
    // correctly.
    $fx = assessmentFixture(['groups' => 1, 'students' => 2, 'nursery' => true]);

    $configured = app(ConfigureReportCard::class)->handle(
        $fx['framework_id'],
        'FULL_SECONDARY_LAYOUT',
        ReportCardConfigFactory::bulletinPayload(),
    );

    app(PublishPeriod::class)->handle(
        $fx['period_id'],
        $fx['class_group_ids'],
        (int) $configured['config']->getKey(),
    );

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();
    $payload = $snapshot->payload;

    // ABSENT. Not zero, not null-but-present: a blank "Rang" box on a nursery
    // card invites a bursar to fill it in.
    expect($payload)->not->toHaveKey('general_average');
    expect($payload)->not->toHaveKey('rank');
    expect($payload)->not->toHaveKey('mention');
    expect($payload)->not->toHaveKey('totals');
    expect($payload)->not->toHaveKey('class_statistics');

    $card = app(RenderReportCard::class)->handle((int) $snapshot->getKey())['card'];

    $columnKeys = array_map(static fn (array $c): string => $c['key'], $card['columns']);

    expect($columnKeys)->not->toContain('coefficient');
    expect($columnKeys)->not->toContain('score_times_coef');
    expect($columnKeys)->not->toContain('subject_rank');
    expect($columnKeys)->not->toContain('appreciation');

    expect($card['blocks'])->not->toHaveKey('general_average_and_rank');
    expect($card['blocks'])->not->toHaveKey('mention');
    expect($card['blocks'])->not->toHaveKey('totals_row');
    expect($card['blocks'])->not->toHaveKey('class_statistics');
});

it('T19 control: the same configuration against a Family A framework does print all four', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 2]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    /** @var ReportCardSnapshot $snapshot */
    $snapshot = ReportCardSnapshot::query()->firstOrFail();

    expect($snapshot->payload)->toHaveKeys(['general_average', 'rank', 'mention', 'totals']);

    $card = app(RenderReportCard::class)->handle((int) $snapshot->getKey())['card'];
    $columnKeys = array_map(static fn (array $c): string => $c['key'], $card['columns']);

    expect($columnKeys)->toContain('coefficient');
    expect($columnKeys)->toContain('subject_rank');
    expect($card['blocks'])->toHaveKey('general_average_and_rank');
});

it('rejects a marks column key the card cannot render (13.5)', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    $payload = ReportCardConfigFactory::bulletinPayload();
    $payload['marks_columns'][] = ['key' => 'attendance_rate', 'label_fr' => 'Assiduité'];

    app(ConfigureReportCard::class)->handle($fx['framework_id'], 'BROKEN', $payload);
})->throws(Illuminate\Validation\ValidationException::class);

it('rejects a per-sequence column with no period_ref, which is the one thing v1 could not express', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    $payload = ReportCardConfigFactory::bulletinPayload();
    array_splice($payload['marks_columns'], 1, 0, [['key' => 'period_score', 'label_fr' => 'Séq 1']]);

    app(ConfigureReportCard::class)->handle($fx['framework_id'], 'NOREF', $payload);
})->throws(Illuminate\Validation\ValidationException::class);

it('accepts the real bulletin de trimestre shape: a term column beside its own sequence columns', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    $payload = ReportCardConfigFactory::bulletinPayload();
    array_splice($payload['marks_columns'], 1, 0, [
        ['key' => 'period_score', 'label_fr' => 'Séq 1', 'period_ref' => 'child:1'],
        ['key' => 'period_score', 'label_fr' => 'Séq 2', 'period_ref' => 'child:2'],
    ]);

    $configured = app(ConfigureReportCard::class)->handle($fx['framework_id'], 'TRIMESTRE', $payload);

    expect($configured['version_no'])->toBe(1);
});

it('mutates an unused config version in place and forks a used one (13.1)', function () {
    $fx = assessmentFixture(['groups' => 2, 'students' => 1]);

    $payload = ReportCardConfigFactory::bulletinPayload();
    $payload['branding']['accent_colour'] = '#000000';

    // Never used yet: edited in place.
    $edited = app(ConfigureReportCard::class)->handle($fx['framework_id'], 'BULLETIN', $payload);

    expect($edited['created_new_version'])->toBeFalse();
    expect($edited['version_id'])->toBe($fx['config_version_id']);

    app(PublishPeriod::class)->handle($fx['period_id'], [$fx['class_group_ids'][0]], $fx['config_id']);

    // Now referenced by an issued card: the next edit forks.
    $payload['branding']['accent_colour'] = '#ffffff';
    $forked = app(ConfigureReportCard::class)->handle($fx['framework_id'], 'BULLETIN', $payload);

    expect($forked['created_new_version'])->toBeTrue();
    expect($forked['version_no'])->toBe(2);
});

it('refuses to rewrite a frozen config version even through the query builder', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    // The trigger, not the Action: 13.1's guarantee is about rows that may be
    // touched years later by code nobody has written yet.
    DB::table('report_card_config_versions')
        ->where('id', $fx['config_version_id'])
        ->update(['payload_hash' => str_repeat('0', 64)]);
})->throws(Illuminate\Database\QueryException::class);

it('refuses to delete an issued snapshot', function () {
    $fx = assessmentFixture(['groups' => 1, 'students' => 1]);

    app(PublishPeriod::class)->handle($fx['period_id'], $fx['class_group_ids'], $fx['config_id']);

    DB::table('report_card_snapshots')->delete();
})->throws(Illuminate\Database\QueryException::class);
