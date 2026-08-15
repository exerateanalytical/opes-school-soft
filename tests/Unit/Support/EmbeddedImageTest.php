<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\EmbeddedImage;
use Illuminate\Support\Facades\Storage;

// tests/Unit does NOT boot the framework (tests/Pest.php extends TestCase in
// Feature only), and Storage::fake needs a booted application. These are unit
// tests of pure helpers, but they exercise a filesystem disk, so they opt in
// to the application TestCase explicitly rather than move directory.
uses(Tests\TestCase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('returns a base64 data URI for a stored image', function (): void {
    Storage::disk('public')->put('branding/crest-abc.png', 'PNGBYTES');

    expect(EmbeddedImage::dataUri('branding/crest-abc.png'))
        ->toBe('data:image/png;base64,'.base64_encode('PNGBYTES'));
});

it('maps each allowed extension to its mime type', function (): void {
    Storage::disk('public')->put('branding/a-1.jpg', 'J');
    Storage::disk('public')->put('branding/a-2.webp', 'W');

    expect(EmbeddedImage::dataUri('branding/a-1.jpg'))->toStartWith('data:image/jpeg;base64,')
        ->and(EmbeddedImage::dataUri('branding/a-2.webp'))->toStartWith('data:image/webp;base64,');
});

it('returns null for a missing file rather than a broken image', function (): void {
    // dompdf has remote assets disabled and renders nothing for an
    // unresolvable src; returning null lets the BLADE omit the <img>
    // entirely, which is a document with no crest rather than a document
    // with a hole in it.
    expect(EmbeddedImage::dataUri('branding/never-existed.png'))->toBeNull();
});

it('returns null for null, empty and non-branding paths', function (): void {
    Storage::disk('public')->put('documents/secret.pdf', 'x');

    expect(EmbeddedImage::dataUri(null))->toBeNull()
        ->and(EmbeddedImage::dataUri(''))->toBeNull()
        // A path column is operator-editable text; it must never be able to
        // inline an arbitrary file off the disk into a PDF.
        ->and(EmbeddedImage::dataUri('documents/secret.pdf'))->toBeNull()
        ->and(EmbeddedImage::dataUri('../../.env'))->toBeNull()
        ->and(EmbeddedImage::dataUri('branding/../../.env'))->toBeNull();
});

it('resolves the same bytes to the same URI every time', function (): void {
    Storage::disk('public')->put('branding/crest-abc.png', 'PNGBYTES');

    expect(EmbeddedImage::dataUri('branding/crest-abc.png'))
        ->toBe(EmbeddedImage::dataUri('branding/crest-abc.png'));
});

it('resolves a whole branding block, leaving the frozen paths untouched', function (): void {
    Storage::disk('public')->put('branding/crest-abc.png', 'CRESTBYTES');

    $resolved = EmbeddedImage::resolveBranding([
        'crest_path' => 'branding/crest-abc.png',
        'logo_path' => null,
    ]);

    expect($resolved['crest_path'])->toBe('branding/crest-abc.png')
        ->and($resolved['crest_uri'])->toBe('data:image/png;base64,'.base64_encode('CRESTBYTES'))
        ->and($resolved['logo_uri'])->toBeNull();
});
