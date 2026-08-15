<?php

declare(strict_types=1);

use App\Support\Branding\BrandTokens;

it('falls back to the Heritage defaults for anything not supplied', function (): void {
    $tokens = BrandTokens::fromArray(['primary' => '#123456']);

    expect($tokens->all()['primary'])->toBe('#123456')
        ->and($tokens->all()['accent'])->toBe(BrandTokens::DEFAULTS['accent'])
        ->and($tokens->all()['danger'])->toBe(BrandTokens::DEFAULTS['danger']);
});

it('uppercases every stored hex so a saved palette is byte-stable', function (): void {
    expect(BrandTokens::fromArray(['primary' => '#0b5a32'])->all()['primary'])->toBe('#0B5A32');
});

it('refuses a malformed hex naming the offending token', function (): void {
    BrandTokens::fromArray(['primary' => 'rgb(1,2,3)']);
})->throws(InvalidArgumentException::class, 'primary');

it('ignores a token name it does not know', function (): void {
    $tokens = BrandTokens::fromArray(['primary' => '#123456', 'not_a_token' => '#000000']);

    expect(array_keys($tokens->all()))->toBe(array_keys(BrandTokens::DEFAULTS));
});

it('emits the CSS custom properties the shell paints from', function (): void {
    $vars = BrandTokens::fromArray([
        'primary' => '#0B5A32',
        'secondary' => '#064A2B',
        'accent' => '#D9A829',
    ])->toCssVariables();

    expect($vars)->toHaveKeys([
        '--color-primary', '--color-chrome', '--color-chrome-light',
        '--color-heritage-yellow', '--color-success', '--color-warning', '--color-danger',
    ])
        // The sidebar body is a DARKER step than the secondary it derives
        // from, the same relationship the built-in palette has.
        ->and($vars['--color-chrome-light'])->toBe('#064A2B')
        ->and($vars['--color-primary'])->toBe('#0B5A32')
        ->and($vars['--color-heritage-yellow'])->toBe('#D9A829');
});

it('round-trips through its array form', function (): void {
    $original = BrandTokens::fromArray(['primary' => '#123456', 'accent' => '#ABCDEF']);

    expect(BrandTokens::fromArray($original->all())->all())->toBe($original->all());
});
