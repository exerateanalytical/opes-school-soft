<?php

declare(strict_types=1);

/**
 * The token layer is a CONTRACT, not a stylesheet detail: later phases'
 * screens are specified in terms of these names, so a rename silently
 * unstyles them.
 */
it('declares the full spacing, radius, shadow, z-index and motion scale', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    foreach ([
        '--space-1: 4px', '--space-2: 8px', '--space-3: 12px', '--space-4: 16px',
        '--space-6: 24px', '--space-8: 32px', '--space-12: 48px', '--space-16: 64px',
        '--radius-card', '--shadow-e1', '--shadow-e2', '--shadow-e3',
        '--z-sticky', '--z-modal', '--z-toast',
        '--motion-fast', '--motion-base', '--ease-standard',
        '--tap-target: 44px',
    ] as $token) {
        expect($css)->toContain($token);
    }
});

it('declares spacing in px, never rem, because the root is 17px', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    // An 8pt grid expressed in rem against a 17px root is an 8.5pt grid.
    expect($css)->not->toMatch('/--space-\d+:\s*[\d.]+rem/');
});

it('keeps the 17px root', function (): void {
    expect((string) file_get_contents(base_path('resources/css/app.css')))
        ->toContain('font-size: 17px');
});

it('keeps the shipped type scale, which the token block must not redefine', function (): void {
    // The 2026-08-15 token layer deliberately does NOT re-issue --text-*: the
    // scale already in app.css was tuned against the 17px root and is on
    // every screen. A second declaration inside the same @theme block would
    // silently resize the whole product.
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    foreach (['--text-xs', '--text-sm', '--text-base', '--text-lg', '--text-xl', '--text-2xl', '--text-3xl'] as $token) {
        expect(substr_count($css, $token.':'))->toBe(1, "{$token} is declared more than once");
    }
});
