<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md 4.3 / 4.5 - document numbering: allocation from
 * the row-locked sequence inside the render transaction, never max()+1;
 * gaps permitted; and A FAILED RENDER CONSUMES NO NUMBER, because the
 * allocation, the IssuedDocument insert and the print log are one
 * transaction committed only after the bytes exist and hash.
 */
beforeEach(function (): void {
    p13coreViews();
    p13coreDocumentProfile();
});

it('allocates sequential serials through the sequences table, scoped per series and year', function () {
    p13coreUserAs(Role::Bursar);

    $series = DocumentSeries::factory()->create([
        'code' => 'SAB',
        'format' => '{school}/{year}/{code}/{serial:6}',
        'scope' => 'academic_year',
    ]);
    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries($series)
        ->create(['blade_view' => 'p13core-snapshot']);

    $snapA = p13coreSnapshotRow(p13coreSnapshotPayload());
    $snapB = p13coreSnapshotRow(p13coreSnapshotPayload());

    $first = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snapA['enrollment_id'],
        subjectLabel: 'Report card for AZEMKEU Brice',
        snapshotId: $snapA['snapshot_id'],
        seriesScopeValue: '2026',
    );

    $second = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snapB['enrollment_id'],
        subjectLabel: 'Report card for FOTSO Marie',
        snapshotId: $snapB['snapshot_id'],
        seriesScopeValue: '2026',
    );

    // The payload's captured school block says HA - serials render from it...
    // no: the serial renders from the school.short_code SETTING, which is
    // absent in this fixture, so the documented 'SCH' fallback appears. What
    // matters here is the counter and the format, not the school code.
    expect($first->serial)->toBe('SCH/2026/SAB/000001');
    expect($second->serial)->toBe('SCH/2026/SAB/000002');

    // The counter lives in `sequences`, fully scoped - the row IS the proof
    // that no max()+1 path was involved.
    expect((int) DB::table('sequences')->where('series', 'document.SAB.2026')->value('next_value'))->toBe(3);
});

it('consumes no number, writes no issued document and no print log when the render fails', function () {
    p13coreUserAs(Role::Bursar);

    $series = DocumentSeries::factory()->create(['code' => 'SAF']);
    $boom = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries($series)
        ->create(['blade_view' => 'p13core-boom']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    expect(fn () => app(RenderDocument::class)->handle(
        templateCode: $boom->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'Doomed render',
        snapshotId: $snap['snapshot_id'],
        seriesScopeValue: '2026',
    ))->toThrow(Exception::class, 'p13core boom');

    // Everything the transaction touched rolled back together (4.5).
    expect(IssuedDocument::query()->count())->toBe(0);
    expect(DB::table('document_print_logs')->count())->toBe(0);
    expect(DB::table('sequences')->where('series', 'document.SAF.2026')->exists())->toBeFalse();

    // The number the failed render would have taken goes to the next
    // successful one - a failure is not a gap.
    $working = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries($series)
        ->create(['blade_view' => 'p13core-snapshot']);

    $rendered = app(RenderDocument::class)->handle(
        templateCode: $working->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'Working render',
        snapshotId: $snap['snapshot_id'],
        seriesScopeValue: '2026',
    );

    expect($rendered->serial)->toBe('SCH/2026/SAF/000001');
});

it('permits gaps: a manually advanced counter is not "repaired" toward max()+1', function () {
    p13coreUserAs(Role::Bursar);

    $series = DocumentSeries::factory()->create(['code' => 'SAG']);
    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries($series)
        ->create(['blade_view' => 'p13core-snapshot']);

    // Simulate a consumed-then-rolled-back-elsewhere history: the counter is
    // ahead of the issued rows. Gaps-permitted means the next serial simply
    // continues from the counter (00-core 12).
    DB::table('sequences')->insert([
        'series' => 'document.SAG.2026',
        'next_value' => 41,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $rendered = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'After the gap',
        snapshotId: $snap['snapshot_id'],
        seriesScopeValue: '2026',
    );

    expect($rendered->serial)->toBe('SCH/2026/SAG/000041');
});

it('refuses a live render path any series number: live documents carry none', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()->create(['blade_view' => 'p13core-live']);

    $rendered = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 1,
        subjectLabel: 'Class list Form 1A',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($rendered->serial)->toBeNull();
    expect($rendered->issuedDocumentId)->toBeNull();

    // No DOCUMENT sequence exists or advanced - other sequences (matricule,
    // admission_no) belong to the fixture factories, not to this render.
    expect(DB::table('sequences')->where('series', 'like', 'document.%')->count())->toBe(0);
});

it('rejects a global-scoped series whose format demands a year token', function () {
    expect(fn () => DocumentSeries::factory()->create([
        'code' => 'SAX',
        'scope' => 'global',
        'reset_policy' => 'never',
        'format' => '{school}/{year}/{code}/{serial:6}',
    ]))->toThrow(RuntimeException::class, 'scope=global');
});
