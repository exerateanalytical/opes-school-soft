<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Fiscal\FiscalIdentityGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * docs/specs/10-documents.md §4.7 - "SPECIMEN for previews and demo licences".
 *
 * Completeness and truthfulness are different questions. A school can fill in
 * every fiscal field and still not have had a human confirm the NIU is real -
 * a demo copy, or a half-finished setup. Such a document must render (a
 * school needs to see its own layouts) but must never be mistakable for a
 * legally sufficient one, so it carries SPECIMEN until the identity is
 * confirmed.
 */
beforeEach(function (): void {
    p13coreViews();
    p13coreDocumentProfile();
});

/**
 * Complete fiscal identity; $confirmedBy decides provisional vs real.
 *
 * `fiscal_identity_confirmed_by` carries a real FK to users - confirmation
 * is a person taking responsibility, so there is no such thing as an
 * anonymous one, and the schema enforces it.
 */
function specimenFiscalIdentity(?int $confirmedBy): void
{
    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage Bilingual College',
        'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI Yaoundé I',
        'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => $confirmedBy === null ? null : now(),
        'fiscal_identity_confirmed_by' => $confirmedBy,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('treats an incomplete fiscal identity as provisional', function (): void {
    // Nothing inserted at all - the state every fresh install starts in.
    expect(FiscalIdentityGate::isProvisional())->toBeTrue();
});

it('treats a complete but unconfirmed fiscal identity as provisional', function (): void {
    specimenFiscalIdentity(confirmedBy: null);

    expect(FiscalIdentityGate::missingFields())->toBe([])
        ->and(FiscalIdentityGate::isProvisional())->toBeTrue();
});

it('stops being provisional once a human confirms the identity', function (): void {
    $confirmer = p13coreUserAs(Role::Bursar);

    specimenFiscalIdentity(confirmedBy: (int) $confirmer->getKey());

    expect(FiscalIdentityGate::isProvisional())->toBeFalse();
});

it('stamps SPECIMEN on a live document while the identity is provisional', function (): void {
    p13coreUserAs(Role::Bursar);
    specimenFiscalIdentity(confirmedBy: null);

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)->toContain('SPECIMEN');
});

it('does not stamp SPECIMEN once the identity is confirmed', function (): void {
    $user = p13coreUserAs(Role::Bursar);
    specimenFiscalIdentity(confirmedBy: (int) $user->getKey());

    $doc = app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );

    expect($doc->html)->not->toContain('SPECIMEN');
});

// A round-trip hash-equality test was deliberately NOT added here for the
// LIVE path: renderLive() embeds the current timestamp into every render by
// design (it is a "Generated on: ..." working view, 4.2), so two separate
// calls never produce byte-identical output regardless of the watermark -
// that is pre-existing behaviour, not something this feature touches.
//
// The guarantee this feature actually depends on - that SPECIMEN never
// leaks into the reproducibility hash - lives in the SNAPSHOT-BACKED (issue)
// path instead, and is enforced structurally there: renderSnapshotBacked()
// computes $hash from the clean (watermark: null) render BEFORE branching
// on provisionalWatermark() to build $outputBytes (RenderDocument.php,
// "6-7. Render and hash" through the SPECIMEN overlay block). A test
// exercising that path needs the full report-card snapshot fixture
// (p13coreSnapshotRow) rather than the plain live-document one used above;
// left as a follow-up rather than forced into this pass.
