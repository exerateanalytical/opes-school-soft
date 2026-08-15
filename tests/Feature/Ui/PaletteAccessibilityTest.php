<?php

declare(strict_types=1);

use App\Modules\SchoolProfile\Domain\BrandPreset;
use App\Support\Branding\BrandTokens;
use App\Support\Branding\ColorContrast;
use App\Support\Branding\ColorScale;

/**
 * The pairs this platform ACTUALLY renders, asserted against WCAG AA - so a
 * future palette change, preset addition or scale tweak cannot silently ship
 * a screen nobody can read.
 *
 * Only real combinations are listed. Asserting hypothetical pairs produces a
 * test that fails for reasons nobody can act on, which is how a suite gets
 * skipped.
 *
 * `warning` is deliberately absent: Heritage Gold is an ACCENT, never a solid
 * fill behind white text, and the product does not render it as one.
 */
$textOnFill = [
    ['#FFFFFF', 'primary', 'the primary button and the table header'],
    ['#FFFFFF', 'secondary', 'the sidebar active surface'],
    ['#FFFFFF', 'success', 'the "Paid" status pill'],
    ['#FFFFFF', 'danger', 'the "Overdue" pill and destructive buttons'],
];

it('clears AA for white text on every solid fill in the default palette', function () use ($textOnFill): void {
    $tokens = BrandTokens::defaults();

    foreach ($textOnFill as [$text, $token, $where]) {
        expect(ColorContrast::ratio($text, $tokens->get($token)))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$where} fails AA");
    }
});

it('clears AA for white text on every solid fill in every shipped preset', function () use ($textOnFill): void {
    foreach (BrandPreset::all() as $preset) {
        $tokens = BrandTokens::fromArray($preset['colors']);

        foreach ($textOnFill as [$text, $token, $where]) {
            expect(ColorContrast::ratio($text, $tokens->get($token)))
                ->toBeGreaterThanOrEqual(
                    ColorContrast::AA_NORMAL,
                    "preset [{$preset['key']}]: {$where} fails AA",
                );
        }
    }
});

it('clears AA for charcoal body text on the KPI card washes', function (): void {
    // The KPI tints are ~4% saturation washes precisely so text contrast is
    // untouched; this is the assertion that keeps them that way.
    foreach (['#EAF6EC', '#EAF1FB', '#FFF5D9', '#FDECEC'] as $wash) {
        expect(ColorContrast::ratio('#14201A', $wash))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "charcoal on {$wash} fails AA");
    }
});

it('gives a usable text shade at step 800 of any brand ramp', function (): void {
    // Dashboards print a brand-tinted heading over a white card. Step 800 is
    // the ramp position reserved for that, so it must be readable for ANY
    // colour a school might pick - including a light one.
    foreach (['#0B5A32', '#1B3A6B', '#8A1F3D', '#D9A829', '#5FB884'] as $brand) {
        expect(ColorContrast::ratio(ColorScale::of($brand)[800], '#FFFFFF'))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "step 800 of {$brand} fails AA on white");
    }
});
