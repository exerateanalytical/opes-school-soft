<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\DocumentReproducibilityViolation;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

/**
 * This file adds no production code. It exists because the design decision in
 * StoredImage (content-hashed filenames) is the only thing standing between
 * this feature and a permanent, silent, unrecoverable failure of every
 * certificate a school ever issued. An untested invariant of that weight is
 * not an invariant.
 */
beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

/**
 * @return array{template: DocumentTemplate, snapshot_id: int}
 */
function reproSnapshotTemplate(): array
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

function reproRender(DocumentTemplate $template, int $snapshotId): App\Modules\Reporting\Domain\RenderedDocument
{
    return app(RenderDocument::class)->handle(
        templateCode: $template->code, subjectType: 'Enrollment', subjectId: 42,
        subjectLabel: 'AZEMKEU Brice', snapshotId: $snapshotId, language: 'en',
    );
}

it('reprints byte-identically after the school uploads a NEW signature at a new path', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    $original = reproRender($template, $snapshotId);

    // The school replaces the signature. Content-hashing means this lands at
    // a DIFFERENT path; the profile row moves, the frozen chrome does not.
    $replacement = StoredImage::putContents('principal_signature', 'REPLACEMENT SIGNATURE', 'png');

    expect($replacement)->not->toBe($signature);

    DB::table('school_document_profiles')->where('id', 1)
        ->update(['principal_signature_path' => $replacement]);

    // The reprint re-renders from the FROZEN chrome, which still names the
    // original path, whose bytes are unchanged. It must reproduce.
    $reprint = reproRender($template, $snapshotId);

    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and($reprint->isDuplicate)->toBeTrue();
});

it('carries the frozen image, not the current one, onto a reprint', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    reproRender($template, $snapshotId);

    DB::table('school_document_profiles')->where('id', 1)->update([
        'principal_signature_path' => StoredImage::putContents('principal_signature', 'REPLACEMENT', 'png'),
    ]);

    $reprint = reproRender($template, $snapshotId);

    // A reprint carrying TODAY's signature on YESTERDAY's certificate is a
    // forgery, not a reprint.
    $html = (string) $reprint->html;

    expect($html)->toContain(base64_encode('ORIGINAL SIGNATURE'));
    expect($html)->not->toContain(base64_encode('REPLACEMENT'));
});

it('refuses honestly, rather than forging, when the frozen image is gone', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);

    $signature = StoredImage::putContents('principal_signature', 'ORIGINAL SIGNATURE', 'png');
    p13coreDocumentProfile(['principal_signature_path' => $signature]);

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    reproRender($template, $snapshotId);

    // The file the frozen chrome names is deleted (the delete-on-replace path
    // in the settings screen). The reprint can no longer reproduce the issued
    // bytes - and says so, loudly, instead of quietly printing a certificate
    // with the signature missing.
    Storage::disk('public')->delete($signature);

    reproRender($template, $snapshotId);
})->throws(DocumentReproducibilityViolation::class);

it('leaves documents issued BEFORE any image existed reproducible', function (): void {
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile();

    ['template' => $template, 'snapshot_id' => $snapshotId] = reproSnapshotTemplate();

    $original = reproRender($template, $snapshotId);

    // The school uploads its first-ever crest AFTER issuing. The already
    // issued document must not acquire it retroactively.
    DB::table('school_document_profiles')->where('id', 1)->update([
        'crest_path' => StoredImage::putContents('crest', 'BRAND NEW CREST', 'png'),
    ]);

    $reprint = reproRender($template, $snapshotId);

    expect($reprint->contentHash)->toBe($original->contentHash)
        ->and((string) $reprint->html)->not->toContain(base64_encode('BRAND NEW CREST'));
});
