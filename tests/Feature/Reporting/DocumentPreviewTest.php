<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
    p13coreDocumentProfile();
});

it('allocates no serial, writes no issued document and logs no print', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());

    // A series the template really points at, so "no serial was burned" is a
    // statement about the allocator rather than about a missing row.
    $series = DocumentSeries::factory()->create();
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'ReportCardSnapshot',
        'series_code' => $series->code,
    ]);

    // The sequence table is pre-seeded by migration, so the fact under test is
    // "unchanged", not "empty".
    $sequencesBefore = DB::table('sequences')->orderBy('id')->get()->toJson();

    $preview = app(RenderDocument::class)->preview(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshot['snapshot_id'], language: 'en',
    );

    expect(DB::table('sequences')->orderBy('id')->get()->toJson())->toBe($sequencesBefore);

    expect($preview->serial)->toBeNull()
        ->and($preview->issuedDocumentId)->toBeNull()
        ->and($preview->printLogId)->toBeNull()
        ->and($preview->storagePath)->toBeNull()
        ->and(DB::table('issued_documents')->count())->toBe(0)
        ->and(DB::table('document_print_logs')->count())->toBe(0);
});

it('always carries the SPECIMEN watermark', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    // Fiscal identity CONFIRMED, so nothing else would put SPECIMEN on it.
    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(p13coreSnapshotPayload());
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'ReportCardSnapshot',
    ]);

    expect(app(RenderDocument::class)->preview(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshot['snapshot_id'], language: 'en',
    )->html)->toContain(__('documents.watermark.specimen', [], 'en'));
});

it('refuses a caller without the print permission', function (): void {
    p13coreUserAs(Role::Teacher);

    app(RenderDocument::class)->preview(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup', subjectId: 5, subjectLabel: 'Class list', language: 'en',
        data: ['rows' => []],
    );
})->throws(AuthorizationException::class);

it('previews a live template too', function (): void {
    p13coreUserAs(Role::Bursar);

    expect(app(RenderDocument::class)->preview(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup', subjectId: 5, subjectLabel: 'Class list Form 1A',
        language: 'en', data: ['rows' => ['AZEMKEU Brice']],
    )->html)->toContain('AZEMKEU Brice');
});

it('refuses a snapshot-backed template previewed without a snapshot', function (): void {
    p13coreUserAs(Role::Bursar);

    app(RenderDocument::class)->preview(
        templateCode: DocumentTemplate::factory()->create([
            'blade_view' => 'p13core-snapshot',
            'is_snapshot_backed' => true,
            'snapshot_source' => 'ReportCardSnapshot',
        ])->code,
        subjectType: 'Enrollment', subjectId: 42, subjectLabel: 'AZEMKEU Brice', language: 'en',
    );
})->throws(ValidationException::class);
