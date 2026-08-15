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

/**
 * The semantic colours are used in TWO roles, and only one of them was ever
 * checked. As a solid FILL (a red "Overdue" pill) the vivid value is correct
 * and white on it passes. As TEXT on its own tint - `bg-warning-bg
 * text-warning`, which portal/row, portal/icon, the status pills and the KPI
 * cards genuinely render - the vivid value failed: amber measured 2.25:1 on
 * #FFF5D9, success 4.08:1 on its tint, danger 4.27:1 on its.
 *
 * So each semantic colour now has a separate TEXT role, darkened until it
 * clears AA on BOTH white and its own tint. These are the pairs that role
 * actually renders on.
 *
 * @var list<array{0: string, 1: string, 2: string}>
 */
$semanticText = [
    ['success', '#EAF6EF', 'the "Paid" row label and the saved-settings toast'],
    ['warning', '#FFF5D9', 'the "Pending" pill and the unsaved-changes hint'],
    ['danger', '#FDECEC', 'the field validation error and the exception banner'],
];

it('clears AA for every semantic text role on white and on its own tint', function () use ($semanticText): void {
    $tokens = BrandTokens::defaults();

    foreach ($semanticText as [$token, $tint, $where]) {
        $text = $tokens->textRole($token);

        expect(ColorContrast::ratio($text, '#FFFFFF'))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$where} fails AA on white")
            ->and(ColorContrast::ratio($text, $tint))
            ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$where} fails AA on {$tint}");
    }
});

it('clears AA for every semantic text role in every shipped preset', function () use ($semanticText): void {
    foreach (BrandPreset::all() as $preset) {
        $tokens = BrandTokens::fromArray($preset['colors']);

        foreach ($semanticText as [$token, $tint, $where]) {
            $text = $tokens->textRole($token);

            expect(ColorContrast::ratio($text, '#FFFFFF'))
                ->toBeGreaterThanOrEqual(
                    ColorContrast::AA_NORMAL,
                    "preset [{$preset['key']}]: {$where} fails AA on white",
                )
                ->and(ColorContrast::ratio($text, $tint))
                ->toBeGreaterThanOrEqual(
                    ColorContrast::AA_NORMAL,
                    "preset [{$preset['key']}]: {$where} fails AA on {$tint}",
                );
        }
    }
});

it('derives a readable text role for ANY colour a school might pick', function () use ($semanticText): void {
    // The whole point of deriving rather than hard-coding three more hexes:
    // a school that picks a pale amber must not get unreadable body text.
    foreach (['#FFD700', '#5FB884', '#EF404A', '#000000'] as $picked) {
        foreach ($semanticText as [$token, $tint, $where]) {
            $text = BrandTokens::fromArray([$token => $picked])->textRole($token);

            expect(ColorContrast::ratio($text, '#FFFFFF'))
                ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$picked} as {$token}: fails AA on white")
                ->and(ColorContrast::ratio($text, $tint))
                ->toBeGreaterThanOrEqual(ColorContrast::AA_NORMAL, "{$picked} as {$token}: fails AA on {$tint}");
        }
    }
});

it('emits every semantic text role as a CSS custom property', function (): void {
    expect(BrandTokens::defaults()->toCssVariables())
        ->toHaveKeys(['--color-success-text', '--color-warning-text', '--color-danger-text']);
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
