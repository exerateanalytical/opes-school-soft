<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Actions\RevokeIssuedDocument;
use App\Modules\Reporting\Models\DocumentSeries;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\Reporting\Models\IssuedDocument;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md 4.4 / 4.5 - the issued-document lifecycle: one
 * original, DUPLICATA derivation from the print log, permission-gated
 * reprints, revoke and supersede.
 */
beforeEach(function (): void {
    p13coreViews();
    p13coreDocumentProfile();
});

it('issues one original and derives DUPLICATA for every later render of the same snapshot', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'IDA']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $original = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'Report card for AZEMKEU Brice',
        snapshotId: $snap['snapshot_id'],
        seriesScopeValue: '2026',
    );

    expect($original->isDuplicate)->toBeFalse();
    expect($original->copyNo)->toBe(1);
    expect($original->html)->not->toContain('DUPLICATA');

    $duplicate = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $snap['enrollment_id'],
        subjectLabel: 'Report card for AZEMKEU Brice',
        snapshotId: $snap['snapshot_id'],
        seriesScopeValue: '2026',
    );

    // Same document, not a second original: same serial, same issued row,
    // watermarked output, copy_no advanced (4.5).
    expect($duplicate->isDuplicate)->toBeTrue();
    expect($duplicate->copyNo)->toBe(2);
    expect($duplicate->serial)->toBe($original->serial);
    expect($duplicate->issuedDocumentId)->toBe($original->issuedDocumentId);
    expect($duplicate->html)->toContain('DUPLICATA');
    expect($duplicate->contentHash)->toBe($original->contentHash);

    expect(IssuedDocument::query()->count())->toBe(1);

    /** @var IssuedDocument $issued */
    $issued = IssuedDocument::query()->firstOrFail();
    expect((int) $issued->printLogs()->count())->toBe(2);
    expect((bool) $issued->printLogs()->orderBy('id')->firstOrFail()->is_duplicate)->toBeFalse();
    expect((bool) $issued->printLogs()->orderByDesc('id')->firstOrFail()->is_duplicate)->toBeTrue();
});

it('gates reprints behind documents.reprint separately from documents.print', function () {
    // The Proviseur holds documents.print but deliberately NOT
    // documents.reprint (Role::defaultPermissions).
    p13coreUserAs(Role::Principal);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'IDB']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $args = [
        'templateCode' => $template->code,
        'subjectType' => 'Enrollment',
        'subjectId' => $snap['enrollment_id'],
        'subjectLabel' => 'Report card',
        'snapshotId' => $snap['snapshot_id'],
        'seriesScopeValue' => '2026',
    ];

    $original = app(RenderDocument::class)->handle(...$args);
    expect($original->isDuplicate)->toBeFalse();

    expect(fn () => app(RenderDocument::class)->handle(...$args))
        ->toThrow(AuthorizationException::class);
});

it('additionally requires documents.reprint_financial on a financial series', function () {
    // A crafted user holding print + reprint but NOT the financial right -
    // the RCPT duplicate must still be refused (10-documents 19).
    $user = p13coreUserAs();
    $user->givePermissionTo(Permission::DocumentsPrint->value);
    $user->givePermissionTo(Permission::DocumentsReprint->value);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->fiscalYear()->create(['code' => 'RCPT']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $args = [
        'templateCode' => $template->code,
        'subjectType' => 'Payment',
        'subjectId' => 77,
        'subjectLabel' => 'Receipt for payment 77',
        'snapshotId' => $snap['snapshot_id'],
        'seriesScopeValue' => '2026',
    ];

    app(RenderDocument::class)->handle(...$args);

    expect(fn () => app(RenderDocument::class)->handle(...$args))
        ->toThrow(AuthorizationException::class);

    // The bursar's office holds the right, and gets the DUPLICATA.
    p13coreUserAs(Role::Bursar);
    $duplicate = app(RenderDocument::class)->handle(...$args);
    expect($duplicate->isDuplicate)->toBeTrue();
});

it('revokes with reason and actor, watermarks later renders ANNULE/VOID, and never un-revokes', function () {
    p13coreUserAs(Role::Administrator);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'IDC']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snap = p13coreSnapshotRow(p13coreSnapshotPayload());

    $args = [
        'templateCode' => $template->code,
        'subjectType' => 'Enrollment',
        'subjectId' => $snap['enrollment_id'],
        'subjectLabel' => 'Report card',
        'snapshotId' => $snap['snapshot_id'],
        'seriesScopeValue' => '2026',
    ];

    $original = app(RenderDocument::class)->handle(...$args);

    $revoked = app(RevokeIssuedDocument::class)->handle(
        (int) $original->issuedDocumentId,
        'Issued against the wrong student.',
    );

    expect($revoked->status)->toBe(IssuedDocument::STATUS_REVOKED);
    expect($revoked->revoked_reason)->toBe('Issued against the wrong student.');
    expect($revoked->revoked_by)->not->toBeNull();

    // 10.1: a voided/revoked document's every later render is marked, and
    // still hash-verifies against the issue.
    $rerender = app(RenderDocument::class)->handle(...$args);
    expect($rerender->html)->toContain('ANNULÉ / VOID');
    expect($rerender->contentHash)->toBe($original->contentHash);

    // Double revoke is refused; so is un-revoking through the model.
    expect(fn () => app(RevokeIssuedDocument::class)->handle((int) $original->issuedDocumentId, 'again'))
        ->toThrow(DomainException::class);

    expect(function () use ($original): void {
        /** @var IssuedDocument $doc */
        $doc = IssuedDocument::query()->findOrFail($original->issuedDocumentId);
        $doc->status = IssuedDocument::STATUS_VALID;
        $doc->save();
    })->toThrow(RuntimeException::class);
});

it('supersedes the prior issue when an amendment renders from a new snapshot', function () {
    p13coreUserAs(Role::Bursar);

    $template = DocumentTemplate::factory()
        ->snapshotBacked()
        ->withSeries(DocumentSeries::factory()->create(['code' => 'IDD']))
        ->create(['blade_view' => 'p13core-snapshot']);

    $snapV1 = p13coreSnapshotRow(p13coreSnapshotPayload());

    $amended = p13coreSnapshotPayload();
    $amended['lines']['Average'] = '14.00 / 20';
    $snapV2 = p13coreSnapshotRow($amended);

    $subjectId = $snapV1['enrollment_id'];

    $first = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $subjectId,
        subjectLabel: 'Report card, generation 1',
        snapshotId: $snapV1['snapshot_id'],
        seriesScopeValue: '2026',
    );

    $second = app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'Enrollment',
        subjectId: $subjectId,
        subjectLabel: 'Report card, generation 2',
        snapshotId: $snapV2['snapshot_id'],
        seriesScopeValue: '2026',
        supersedesIssuedDocumentId: $first->issuedDocumentId,
    );

    // Two ORIGINALS exist - a new snapshot is a new document, not a
    // duplicate - and the old one names its successor (4.4, 6.1).
    expect($second->isDuplicate)->toBeFalse();
    expect($second->serial)->not->toBe($first->serial);

    /** @var IssuedDocument $prior */
    $prior = IssuedDocument::query()->findOrFail($first->issuedDocumentId);
    expect($prior->status)->toBe(IssuedDocument::STATUS_SUPERSEDED);
    expect($prior->superseded_by_document_id)->toBe($second->issuedDocumentId);
});

it('refuses to render a document for a template whose signature roles name a state office', function () {
    expect(fn () => DocumentTemplate::factory()->create([
        'signature_roles' => ['principal', 'minister'],
    ]))->toThrow(RuntimeException::class, 'credential-forgery');

    expect(fn () => DocumentTemplate::factory()->create([
        'signature_roles' => ['principal', 'chief_of_village'],
    ]))->toThrow(RuntimeException::class, 'allow-list');
});
