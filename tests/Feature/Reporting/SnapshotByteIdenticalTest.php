<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\DocumentReproducibilityViolation;
use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md 4.2 / 4.5 - the snapshot guarantee at the
 * PLATFORM level: issue, mutate every underlying live row, re-render, and
 * the recomputed hash of the clean artefact equals the stored hash exactly
 * (which is only possible if the bytes are identical - the comparison IS
 * the byte-identity assertion). And the negative: when the render inputs
 * really have moved, the reprint is a DocumentReproducibilityViolation,
 * never a silent print.
 */
beforeEach(function (): void {
    p13coreViews();
    p13coreDocumentProfile();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('re-renders byte-identically after every underlying row is mutated, even a year later', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'SBI']))
        ->create(['blade_view' => 'p13core-snapshot']);

    // The payload carries its own school block - the as-at-issue capture -
    // so nothing below the snapshot can reach the artefact.
    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $args = [
        'templateCode' => $template->code,
        'subjectType' => 'Enrollment',
        'subjectId' => $snap['enrollment_id'],
        'subjectLabel' => 'Report card for AZEMKEU Brice',
        'snapshotId' => $snap['snapshot_id'],
        'seriesScopeValue' => '2026',
    ];

    $original = app(RenderDocument::class)->handle(...$args);

    /** @var IssuedDocument $issued */
    $issued = IssuedDocument::query()->findOrFail($original->issuedDocumentId);
    expect($issued->content_hash)->toBe($original->contentHash);

    // --- Mutate everything the document could have been derived from. ------
    DB::table('students')->update(['first_name' => 'REWRITTEN', 'last_name' => 'REWRITTEN']);
    p13coreDocumentProfile([
        'ministry_en' => 'A DIFFERENT MINISTRY ENTIRELY',
        'default_document_language' => 'fr',
    ]);

    // And reprint from another wall-clock year: the PDF metadata is pinned
    // to the ISSUE timestamp, so even time moving cannot move the bytes.
    Carbon::setTestNow(Carbon::now()->addYear());

    $reprint = app(RenderDocument::class)->handle(...$args);

    expect($reprint->contentHash)->toBe($original->contentHash);
    expect($reprint->isDuplicate)->toBeTrue();

    // The stored hash never moved either.
    expect(IssuedDocument::query()->findOrFail($original->issuedDocumentId)->content_hash)
        ->toBe($original->contentHash);
});

it('throws DocumentReproducibilityViolation when the render inputs really moved, and logs no print', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'SBV']))
        ->create(['blade_view' => 'p13core-snapshot', 'state_header' => 'default_on']);

    // NO captured school block: the chrome renders from the live profile,
    // which is exactly the leak this exception exists to catch.
    $payload = p13coreSnapshotPayload();
    unset($payload['school']);
    $snap = p13coreSnapshotRow($payload);

    $args = [
        'templateCode' => $template->code,
        'subjectType' => 'Enrollment',
        'subjectId' => $snap['enrollment_id'],
        'subjectLabel' => 'Report card',
        'snapshotId' => $snap['snapshot_id'],
        'seriesScopeValue' => '2026',
    ];

    $original = app(RenderDocument::class)->handle(...$args);
    $logsAfterIssue = (int) DB::table('document_print_logs')->count();

    // Move the live chrome the template renders from.
    p13coreDocumentProfile(['ministry_en' => 'THE LETTERHEAD HAS CHANGED']);

    expect(fn () => app(RenderDocument::class)->handle(...$args))
        ->toThrow(DocumentReproducibilityViolation::class);

    // The refused reprint left nothing behind: no print log, no state change.
    expect((int) DB::table('document_print_logs')->count())->toBe($logsAfterIssue);
    expect(IssuedDocument::query()->findOrFail($original->issuedDocumentId)->content_hash)
        ->toBe($original->contentHash);
});

it('renders through a REAL report_card_snapshots payload, never the caller data, for a registered source', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'SBR']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    // A caller trying to smuggle different content in through $data changes
    // nothing: for a registered snapshot source, the snapshot IS the payload.
    $rendered = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'Report card',
        snapshotId: $snap['snapshot_id'],
        data: ['lines' => ['Average' => '19.99 / 20 (forged)']],
        seriesScopeValue: '2026',
    );

    expect($rendered->html)->toContain('13.50 / 20');
    expect($rendered->html)->not->toContain('19.99');

    // And the snapshot generation is pinned on the log (4.4).
    expect((int) DB::table('document_print_logs')->orderByDesc('id')->value('snapshot_version'))->toBe(1);
});
