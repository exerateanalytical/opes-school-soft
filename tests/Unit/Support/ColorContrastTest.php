<?php

declare(strict_types=1);

use App\Support\Branding\ColorContrast;

it('computes the canonical black-on-white ratio', function (): void {
    expect(ColorContrast::ratio('#000000', '#FFFFFF'))->toBeGreaterThan(20.9)
        ->and(ColorContrast::ratio('#000000', '#FFFFFF'))->toBeLessThan(21.1);
});

it('is symmetric', function (): void {
    expect(round(ColorContrast::ratio('#0B5A32', '#FFFFFF'), 4))
        ->toBe(round(ColorContrast::ratio('#FFFFFF', '#0B5A32'), 4));
});

it('gives identical colours a ratio of 1', function (): void {
    expect(round(ColorContrast::ratio('#D9A829', '#D9A829'), 4))->toBe(1.0);
});

it('passes AA for Heritage green on white and fails for gold on white', function (): void {
    // Heritage Green #0B5A32 on white is the platform's own button colour and
    // must clear 4.5:1. Heritage Gold #D9A829 on white does NOT - which is
    // exactly why the design system uses gold as an accent only, never as a
    // text or button fill. The branding screen has to say so out loud when a
    // school picks something similar.
    expect(ColorContrast::passesAA('#0B5A32', '#FFFFFF'))->toBeTrue()
        ->and(ColorContrast::passesAA('#D9A829', '#FFFFFF'))->toBeFalse();
});

it('refuses a malformed hex', function (): void {
    ColorContrast::ratio('0B5A32', '#FFFFFF');
})->throws(InvalidArgumentException::class);
