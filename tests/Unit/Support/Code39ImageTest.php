<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\Code39Image;

it('produces a PNG data URI', function (): void {
    $uri = Code39Image::dataUri('HBCAST2026000145');

    expect($uri)->toStartWith('data:image/png;base64,');

    $bytes = base64_decode(substr($uri, strlen('data:image/png;base64,')), true);

    // The PNG magic number. dompdf silently draws nothing for a malformed
    // image, so asserting "it is a string" would assert nothing.
    expect(substr((string) $bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
});

it('is deterministic for the same payload', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145'))->toBe(Code39Image::dataUri('HBCAST2026000145'));
});

it('produces different images for different payloads', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145'))->not->toBe(Code39Image::dataUri('HBCAST2026000146'));
});

it('refuses a payload outside the Code 39 alphanumeric subset', function (): void {
    // Lowercase and punctuation are not in the subset this platform uses, and
    // a generator that silently transliterates them prints a barcode that
    // scans back as something else.
    Code39Image::dataUri('hbc/ast/2026');
})->throws(InvalidArgumentException::class);

it('honours the height and width factor it is given', function (): void {
    expect(Code39Image::dataUri('HBCAST2026000145', widthFactor: 2, height: 40))
        ->not->toBe(Code39Image::dataUri('HBCAST2026000145', widthFactor: 2, height: 60));
});
