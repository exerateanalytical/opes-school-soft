<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Modules\SchoolProfile\Actions\WriteSetting;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

/**
 * The canonical logo reaches the LETTERHEAD of newly issued documents, and
 * reaches NOTHING ELSE. The second half is the one that matters: the chrome is
 * inside the hashed bytes, so if changing the platform logo could move an
 * already-issued document's render, every certificate that school ever issued
 * would fail its reproducibility check permanently.
 */
function canonChromeTemplate(): array
{
    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);

    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
        'signature_roles' => ['principal'],
    ]);

    return ['template' => $template, 'snapshot_id' => $snapshot['snapshot_id']];
}

function canonChromeRender(DocumentTemplate $template, int $snapshotId): App\Modules\Reporting\Domain\RenderedDocument
{
    return app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );
}

it('captures the platform logo ahead of the document profile logo in live chrome', function (): void {
    $user = p13coreUserAs(Role::Bursar, Role::Principal);

    $documentLogo = StoredImage::putContents('logo', 'DOCUMENT LOGO', 'png');
    $platformLogo = StoredImage::putContents('app-logo', 'PLATFORM LOGO', 'png');

    p13coreDocumentProfile(['logo_path' => $documentLogo]);
    app(WriteSetting::class)->handle('branding.app_logo_path', $platformLogo, $user->toAuditActor());

    $chrome = app(RenderDocument::class)->captureSchoolChrome(true);

    expect($chrome['branding']['logo_path'])->toBe($platformLogo)
        // The crest is a DIFFERENT mark on a Cameroon school document and is
        // deliberately untouched by the unification.
        ->and($chrome['branding'])->toHaveKey('crest_path');
});

it('keeps the document profile logo in live chrome when no platform logo is set', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $documentLogo = StoredImage::putContents('logo', 'DOCUMENT LOGO', 'png');
    p13coreDocumentProfile(['logo_path' => $documentLogo]);

    $chrome = app(RenderDocument::class)->captureSchoolChrome(true);

    expect($chrome['branding']['logo_path'])->toBe($documentLogo);
});

it('reprints an already-issued document byte-identically after the platform logo changes', function (): void {
    $user = p13coreUserAs(Role::Bursar, Role::Principal);

    $original = StoredImage::putContents('app-logo', 'ORIGINAL PLATFORM LOGO', 'png');
    p13coreDocumentProfile(['logo_path' => null]);
    app(WriteSetting::class)->handle('branding.app_logo_path', $original, $user->toAuditActor());

    ['template' => $template, 'snapshot_id' => $snapshotId] = canonChromeTemplate();

    $issued = canonChromeRender($template, $snapshotId);

    // The school uploads a new platform logo. Content-hashing lands it at a
    // different path, so the frozen chrome still names bytes that exist.
    $replacement = StoredImage::putContents('app-logo', 'REPLACEMENT PLATFORM LOGO', 'png');
    expect($replacement)->not->toBe($original);

    app(WriteSetting::class)->handle('branding.app_logo_path', $replacement, $user->toAuditActor());

    $reprint = canonChromeRender($template, $snapshotId);

    expect($reprint->contentHash)->toBe($issued->contentHash)
        ->and($reprint->isDuplicate)->toBeTrue();
});
