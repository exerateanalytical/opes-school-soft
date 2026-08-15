<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Reporting\Actions\RenderDocument;
use App\Modules\Reporting\Models\DocumentTemplate;
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
 * Every branding image set, on a fake public disk.
 *
 * @return array<string, string>
 */
function brandingProfileWithImages(): array
{
    $paths = [
        'crest_path' => StoredImage::putContents('crest', 'CRESTBYTES', 'png'),
        'logo_path' => StoredImage::putContents('logo', 'LOGOBYTES', 'png'),
        'principal_signature_path' => StoredImage::putContents('principal_signature', 'PSIGBYTES', 'png'),
        'registrar_signature_path' => StoredImage::putContents('registrar_signature', 'RSIGBYTES', 'png'),
        'school_stamp_path' => StoredImage::putContents('school_stamp', 'STAMPBYTES', 'png'),
    ];

    p13coreDocumentProfile($paths);

    return $paths;
}

/** A live document off the branded fixture. */
function renderBranded(DocumentTemplate $template): App\Modules\Reporting\Domain\RenderedDocument
{
    return app(RenderDocument::class)->handle(
        templateCode: $template->code,
        subjectType: 'ClassGroup',
        subjectId: 5,
        subjectLabel: 'Class list Form 1A',
        language: 'en',
        data: ['rows' => ['AZEMKEU Brice']],
    );
}

it('embeds the crest and the logo as data URIs, never as a storage URL', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $doc = renderBranded(DocumentTemplate::factory()->create(['blade_view' => 'p13core-branded']));

    $html = (string) $doc->html;

    expect($html)
        ->toContain('data:image/png;base64,'.base64_encode('CRESTBYTES'))
        ->toContain('data:image/png;base64,'.base64_encode('LOGOBYTES'));

    // dompdf has remote assets disabled: a /storage URL renders NOTHING.
    expect($html)->not->toContain('src="/storage/');
    expect($html)->not->toContain('src="http');
});

it('prints the signature images above the signature lines', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $doc = renderBranded(DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-branded',
        'signature_roles' => ['principal', 'registrar'],
    ]));

    expect((string) $doc->html)
        ->toContain(base64_encode('PSIGBYTES'))
        ->toContain(base64_encode('RSIGBYTES'))
        ->toContain(base64_encode('STAMPBYTES'));
});

it('prints no image element at all when a slot is unset', function (): void {
    p13coreUserAs(Role::Bursar);
    p13coreDocumentProfile();

    $doc = renderBranded(DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-branded',
        'signature_roles' => ['principal'],
    ]));

    // A missing crest must leave NO <img> - not an empty box, not a broken
    // image. A letterhead with a hole in it reads as a broken install.
    expect((string) $doc->html)->not->toContain('<img');
});

it('prints only the signature image whose role the template actually carries', function (): void {
    p13coreUserAs(Role::Bursar);
    brandingProfileWithImages();

    $doc = renderBranded(DocumentTemplate::factory()->create([
        'blade_view' => 'p13core-branded',
        'signature_roles' => ['registrar'],
    ]));

    $html = (string) $doc->html;

    expect($html)->toContain(base64_encode('RSIGBYTES'));

    // The principal did not sign this one; printing his signature would be a
    // forgery, not a convenience.
    expect($html)->not->toContain(base64_encode('PSIGBYTES'));
});

it('never leaks a non-branding path into a rendered document', function (): void {
    p13coreUserAs(Role::Bursar);

    // A hand-edited path column. EmbeddedImage refuses anything outside
    // branding/, so this must render as no crest rather than as an inlined
    // copy of whatever the operator pointed at.
    Storage::disk('public')->put('documents/secret.pdf', 'TOPSECRET');
    p13coreDocumentProfile(['crest_path' => 'documents/secret.pdf']);

    $doc = renderBranded(DocumentTemplate::factory()->create(['blade_view' => 'p13core-branded']));

    $html = (string) $doc->html;

    expect($html)->not->toContain(base64_encode('TOPSECRET'));
    expect($html)->not->toContain('<img');
});
