<?php

declare(strict_types=1);

/**
 * The language files must PARSE, and must agree with each other.
 *
 * Written after breaking `lang/fr/opes.php` in exactly the way this catches:
 * a French string carrying an apostrophe ("Aujourd'hui") was written into a
 * single-quoted PHP literal unescaped, which is a parse error. Every screen
 * whose translation lived past that line went to a 500, and the failure was
 * invisible until a page was actually rendered - `php -l` says so
 * immediately, but only if somebody runs it and does not send the output to
 * /dev/null.
 *
 * A missing KEY is the quieter half of the same problem: __() returns the key
 * itself when a translation is absent, so the screen renders
 * "opes.nav.accounting_dashboard" at the reader rather than throwing. That
 * one shipped, and was found by looking at the running product.
 */
it('parses every language file', function (string $locale): void {
    $path = lang_path($locale.'/opes.php');

    expect(file_exists($path))->toBeTrue("Missing {$path}");

    // require would fatal the whole suite on a parse error rather than fail
    // this test with a usable message, so the file is linted as a subprocess.
    $php = PHP_BINARY;
    $output = [];
    $status = 0;
    exec(escapeshellarg($php).' -l '.escapeshellarg($path).' 2>&1', $output, $status);

    expect($status)->toBe(0, "lang/{$locale}/opes.php does not parse:\n".implode("\n", $output));
})->with(['en', 'fr']);

it('gives every English key a French counterpart', function (): void {
    /** @var array<string, mixed> $en */
    $en = require lang_path('en/opes.php');
    /** @var array<string, mixed> $fr */
    $fr = require lang_path('fr/opes.php');

    /** @return list<string> */
    $flatten = static function (array $rows, string $prefix = '') use (&$flatten): array {
        $keys = [];

        foreach ($rows as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $keys = array_merge($keys, $flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    };

    $missing = array_values(array_diff($flatten($en), $flatten($fr)));

    // Reported as a list rather than a count: "31 keys missing" sends the
    // reader hunting, and the whole point is that they should not have to.
    expect($missing)->toBe([], "Keys present in en but not fr:\n  ".implode("\n  ", $missing));
});
