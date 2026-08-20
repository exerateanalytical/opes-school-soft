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
    /*
     * The 2026-08-15 token layer deliberately does NOT re-issue --text-*: the
     * scale already in app.css was tuned against the 17px root and is on
     * every screen. A second declaration inside the same @theme block would
     * silently resize the whole product.
     *
     * SCOPED to the @theme block, not counted across the whole file. This
     * used to be substr_count over app.css, which failed on correct code:
     * `html.portal-root` legitimately re-declares the whole scale to Tailwind
     * stock values, because the guardian portal is drawn to a 16px root and
     * that rule shadows the tokens on portal pages only. The assertion was
     * flagging a documented, deliberate override as a duplicate - and a test
     * that fails on correct code is one people learn to skip.
     *
     * What it protects is unchanged: the shipped scale must be declared
     * exactly once in the block every screen resolves through.
     */
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $start = strpos($css, '@theme {');
    expect($start)->not->toBeFalse('app.css has no @theme block');

    $depth = 0;
    $end = null;

    for ($i = (int) strpos($css, '{', $start); $i < strlen($css); $i++) {
        if ($css[$i] === '{') {
            $depth++;
        } elseif ($css[$i] === '}') {
            $depth--;

            if ($depth === 0) {
                $end = $i;

                break;
            }
        }
    }

    expect($end)->not->toBeNull('app.css has an unclosed @theme block');

    $theme = substr($css, (int) $start, (int) $end - (int) $start);

    foreach (['--text-xs', '--text-sm', '--text-base', '--text-lg', '--text-xl', '--text-2xl', '--text-3xl'] as $token) {
        expect(substr_count($theme, $token.':'))->toBe(1, "{$token} is declared more than once inside @theme");
    }
});
