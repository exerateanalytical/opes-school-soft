<?php

declare(strict_types=1);

use App\Support\Storage\StoredImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// tests/Unit does NOT boot the framework (tests/Pest.php extends TestCase in
// Feature only), and Storage::fake needs a booted application. These are unit
// tests of pure helpers, but they exercise a filesystem disk, so they opt in
// to the application TestCase explicitly rather than move directory.
uses(Tests\TestCase::class);

beforeEach(function (): void {
    Storage::fake('public');
});

it('stores under a content-hashed filename inside branding/', function (): void {
    $path = StoredImage::put('crest', UploadedFile::fake()->image('anything.png', 200, 200));

    expect($path)->toStartWith('branding/crest-')
        ->and($path)->toEndWith('.png')
        ->and(Storage::disk('public')->exists($path))->toBeTrue();
});

it('gives identical bytes the identical path', function (): void {
    $bytes = (string) file_get_contents(__FILE__);

    expect(StoredImage::putContents('crest', $bytes, 'png'))
        ->toBe(StoredImage::putContents('crest', $bytes, 'png'));
});

it('gives different bytes a DIFFERENT path', function (): void {
    // THE load-bearing property. A frozen document chrome stores the PATH; if
    // replacing an image reused the path, every document issued before the
    // replacement would re-render to different bytes and fail its
    // reproducibility check forever.
    expect(StoredImage::putContents('signature', 'first version', 'png'))
        ->not->toBe(StoredImage::putContents('signature', 'second version', 'png'));
});

it('keeps the slot in the filename so a stray file is identifiable', function (): void {
    expect(StoredImage::putContents('school_stamp', 'x', 'png'))
        ->toStartWith('branding/school-stamp-');
});

it('refuses an extension outside the allow-list', function (): void {
    StoredImage::putContents('crest', 'x', 'svg');
})->throws(InvalidArgumentException::class, 'svg');

it('deletes a previous path only when it differs from the new one', function (): void {
    $old = StoredImage::putContents('crest', 'old bytes', 'png');
    $new = StoredImage::putContents('crest', 'new bytes', 'png');

    StoredImage::forget($old, $new);

    expect(Storage::disk('public')->exists($old))->toBeFalse()
        ->and(Storage::disk('public')->exists($new))->toBeTrue();

    // Same path on both sides: a re-upload of identical bytes must NOT
    // delete the file it just wrote.
    StoredImage::forget($new, $new);

    expect(Storage::disk('public')->exists($new))->toBeTrue();
});

it('never deletes a path outside branding/', function (): void {
    Storage::disk('public')->put('documents/keep-me.pdf', 'x');

    StoredImage::forget('documents/keep-me.pdf', 'branding/crest-abc.png');

    expect(Storage::disk('public')->exists('documents/keep-me.pdf'))->toBeTrue();
});
