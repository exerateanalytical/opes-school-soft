<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Domain\RenderedDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
use App\Support\Storage\StoredImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/P13CoreHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    p13coreViews();
    Storage::fake('public');
});

function schoolWatermarkRender(): RenderedDocument
{
    return app(RenderDocument::class)->handle(
        templateCode: DocumentTemplate::factory()->create(['blade_view' => 'p13core-live'])->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );
}

it('prints nothing when the school watermark is off', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['watermark_enabled' => false, 'watermark_text' => 'HERITAGE']);

    // The CLASS NAME alone appears in the layout stylesheet on every render,
    // so the assertion has to be the DIV, or it asserts nothing.
    expect(schoolWatermarkRender()->html)->not->toContain('<div class="doc-school-watermark">');
});

it('prints the school text watermark when it is on', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true,
        'watermark_text' => 'HERITAGE BILINGUAL COLLEGE',
        'watermark_opacity' => 10,
    ]);

    expect(schoolWatermarkRender()->html)
        ->toContain('<div class="doc-school-watermark">')
        ->toContain('HERITAGE BILINGUAL COLLEGE')
        ->toContain('rgba(120, 120, 120, 0.1)');
});

it('prints an image watermark as a data URI', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true,
        'watermark_image_path' => StoredImage::putContents('watermark', 'MARKBYTES', 'png'),
    ]);

    $html = schoolWatermarkRender()->html;

    expect($html)->toContain('data:image/png;base64,'.base64_encode('MARKBYTES'));
    expect($html)->not->toContain('src="/storage/');
});

it('draws the school watermark AND the specimen status watermark together', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile(['watermark_enabled' => true, 'watermark_text' => 'HERITAGE']);
    // No fiscal_identities row at all: provisional, so SPECIMEN applies.

    $html = schoolWatermarkRender()->html;

    expect($html)
        ->toContain('<div class="doc-school-watermark">')
        ->toContain('HERITAGE')
        ->toContain('<div class="doc-watermark">')
        ->toContain('SPÉCIMEN');
});

it('keeps drawing the school watermark on a DUPLICATA reprint', function (): void {
    // The whole reason this is a second layer: with one slot, the first
    // reprint of any document would silently drop the school's own mark.
    p13coreUserAs(Role::Bursar, Role::Principal);
    p13coreDocumentProfile(['watermark_enabled' => true, 'watermark_text' => 'HERITAGE']);

    DB::table('fiscal_identities')->updateOrInsert(['id' => 1], [
        'legal_name' => 'Heritage', 'niu' => 'P000000000000A',
        'tax_centre_name' => 'CDI', 'tax_regime' => 'reel',
        'fiscal_identity_confirmed_at' => now(),
        'fiscal_identity_confirmed_by' => (int) auth()->id(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $snapshot = p13coreSnapshotRow(['student' => ['name' => 'AZEMKEU Brice'], 'marks' => []]);
    $template = DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-snapshot',
        'is_snapshot_backed' => true,
        'snapshot_source' => 'report_card',
    ]);

    $args = [
        'templateCode' => $template->code, 'subjectType' => 'Enrollment', 'subjectId' => 42,
        'subjectLabel' => 'AZEMKEU Brice', 'snapshotId' => $snapshot['snapshot_id'], 'language' => 'en',
    ];

    $original = app(RenderDocument::class)->handle(...$args);
    $reprint = app(RenderDocument::class)->handle(...$args);

    expect($reprint->isDuplicate)->toBeTrue()
        ->and($reprint->html)->toContain('DUPLICATA')
        ->and($reprint->html)->toContain('HERITAGE')
        // The overlay lives OUTSIDE the hashed artefact, so the clean render
        // still reproduces the hash recorded at issue.
        ->and($reprint->contentHash)->toBe($original->contentHash);
});

it('clamps a nonsense opacity rather than printing an opaque or invisible mark', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile([
        'watermark_enabled' => true, 'watermark_text' => 'HERITAGE', 'watermark_opacity' => 200,
    ]);

    expect(schoolWatermarkRender()->html)->toContain('rgba(120, 120, 120, 0.3)');
});
