<?php

declare(strict_types=1);

// docs/specs/00-core.md 7.1: no float in money contexts, enforced rather than
// trusted. The previous spec mandated this in prose and contradicted itself.

arch('support code declares strict types')
    ->expect('App\Support')
    ->toUseStrictTypes();

arch('value objects are final')
    ->expect([
        'App\Support\Money\Money',
        'App\Support\Rate\Rate',
        'App\Support\Score\Score',
    ])
    ->toBeFinal();

arch('value objects are readonly')
    ->expect([
        'App\Support\Money\Money',
        'App\Support\Rate\Rate',
        'App\Support\Score\Score',
    ])
    ->toBeReadonly();

/**
 * Strip comments and docblocks so the scan below judges CODE, not prose. The
 * value objects document exactly why they avoid float and round(), and those
 * explanations must not be what trips the rule.
 */
function opesStripComments(string $source): string
{
    $out = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $out .= $token[1];

            continue;
        }

        $out .= $token;
    }

    return $out;
}

it('has no float type hints, float casts or round() in the numeric value objects', function () {
    // Resolved from the filesystem, not app_path(): the Architecture suite
    // does not boot the application container, so it must run standalone
    // (php artisan test --testsuite=Architecture) as well as in a full run.
    $app = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR;

    $files = [
        $app.'Support/Money/Money.php',
        $app.'Support/Money/Allocator.php',
        $app.'Support/Rate/Rate.php',
        $app.'Support/Score/Score.php',
    ];

    foreach ($files as $file) {
        $raw = file_get_contents($file);

        expect($raw)->toBeString();

        $source = opesStripComments((string) $raw);

        expect($source)->not->toMatch('/:\s*float\b/', "float return type in {$file}");
        // (?<!\|) permits only the `int|float` overflow-guard union, which is
        // how these classes DETECT PHP's silent int-to-float promotion. A bare
        // `float $x` parameter - float arriving as money - still fails.
        expect($source)->not->toMatch('/(?<!\|)\bfloat\s+\$/', "float parameter in {$file}");
        expect($source)->not->toMatch('/\(float\)/', "float cast in {$file}");
        expect($source)->not->toMatch('/\bround\s*\(/', "round() in {$file} - use integer division");
    }
});
