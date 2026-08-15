<?php

declare(strict_types=1);

use App\Support\Branding\ColorContrast;
use App\Support\Branding\ColorScale;

/**
 * The PHP implementation must reproduce the ui-design-system skill's
 * generator. That generator is a python script and cannot run at request time
 * - the palette is chosen at RUNTIME by an operator - so the algorithm is
 * reimplemented here and pinned to the script's output for the platform's own
 * brand colour.
 *
 * The fixture below is the LITERAL output of
 *   python .../ui-design-system/scripts/design_token_generator.py "#0B5A32" \
 *          --style modern --format json
 * read at colors.primary, with one documented substitution: step 500 is the
 * generator's `DEFAULT` (the colour as supplied) rather than its re-quantised
 * `500` (#235A3E). See ColorScale's class docblock for why.
 *
 * If this test fails after a refactor, the scale drifted from the documented
 * algorithm; do not "fix" it by editing the fixture.
 */
it('reproduces the reference ramp for Heritage Green', function (): void {
    $reference = [
        50 => '#AAF2CD', 100 => '#A1F2C9', 200 => '#91F2C1',
        300 => '#80F2B8', 400 => '#70F2B0', 500 => '#0B5A32',
        600 => '#17482F', 700 => '#0D3621', 800 => '#062415', 900 => '#021109',
    ];

    expect(ColorScale::of('#0B5A32'))->toBe($reference);
});

it('keeps step 500 exactly as the colour supplied', function (): void {
    expect(ColorScale::of('#1B3A6B')[500])->toBe('#1B3A6B');
});

it('produces a monotonically darkening ramp', function (): void {
    $scale = ColorScale::of('#8A1F3D');
    $previous = null;

    foreach ($scale as $hex) {
        $sum = (int) hexdec(substr($hex, 1, 2))
            + (int) hexdec(substr($hex, 3, 2))
            + (int) hexdec(substr($hex, 5, 2));

        if ($previous !== null) {
            expect($sum)->toBeLessThanOrEqual($previous);
        }

        $previous = $sum;
    }
});

it('darkens steps 800 and 900 until they are readable on white', function (): void {
    // A pale brand colour's proportional falloff leaves the text steps too
    // bright to read; the AA clamp in ColorScale::of() is what stops a
    // dashboard heading shipping at 2:1.
    foreach (['#D9A829', '#5FB884', '#0B5A32'] as $brand) {
        $scale = ColorScale::of($brand);

        expect(ColorContrast::ratio($scale[800], '#FFFFFF'))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "step 800 of {$brand}")
            ->and(ColorContrast::ratio($scale[900], '#FFFFFF'))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "step 900 of {$brand}");
    }
});

it('refuses a malformed hex', function (): void {
    ColorScale::of('#GGGGGG');
})->throws(InvalidArgumentException::class);
