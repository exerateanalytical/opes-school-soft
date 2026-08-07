<?php

declare(strict_types=1);

// Guards against the exact bug found in manual QA: every health-check class
// under app/Modules/Operations/Health/Checks was written before the i18n
// system existed, so every label/detail/remedy string was a hardcoded English
// literal. tests/Feature/LocalisationTest.php cannot catch that class of bug -
// it only diffs lang-file KEYS against each other, and has no visibility into
// a literal sitting inside a PHP class body. This test reads the check files
// as text and flags any string literal that looks like user-facing prose.
//
// Heuristic: a string literal is suspicious when it is longer than 15
// characters AND contains a space (a translation key such as
// 'opes.health.database.label' has no space; 'php artisan migrate' inside a
// remedy string does have one, so remedies with embedded commands still need
// to go through __() - which they do). Two categories of long, spaced
// literal are legitimate and are explicitly excluded, for the entire call
// they appear in (including further arguments and dot-concatenated pieces of
// the same string, tracked via PHP's own paren nesting):
//   1. The string is an argument to __()/trans() - i.e. a translation key
//      or its parameter array, not the translated text.
//   2. The string is passed to an exception constructor (new *Exception(...)
//      / new RuntimeException(...)) - those are internal diagnostic messages
//      caught by CollectHealth, never shown to a bursar, and out of scope for
//      this pass per the task brief.
//
// Uses PHP's own token_get_all() rather than a regex over raw source. A regex
// scan for quoted spans previously misread apostrophes inside doc-comment
// prose (e.g. "the server's filesystem") as opening/closing string
// delimiters, and separately failed to follow a message string split across
// a '.' concatenation inside an exception constructor call because its
// exclusion window only looked a fixed distance back. The tokenizer sees
// comments and string literals exactly as PHP does, so neither class of
// false positive can recur.

/**
 * @return list<string>
 */
function opesHealthCheckFiles(): array
{
    $dir = dirname(__DIR__, 2)
        .DIRECTORY_SEPARATOR.'app'
        .DIRECTORY_SEPARATOR.'Modules'
        .DIRECTORY_SEPARATOR.'Operations'
        .DIRECTORY_SEPARATOR.'Health'
        .DIRECTORY_SEPARATOR.'Checks';

    if (! is_dir($dir)) {
        return [];
    }

    $files = glob($dir.DIRECTORY_SEPARATOR.'*.php');

    return $files === false ? [] : $files;
}

/**
 * Scans one file's source for suspicious hardcoded prose literals.
 *
 * @return list<string> human-readable descriptions of each offending literal
 */
function opesFindHardcodedHealthStrings(string $path): array
{
    $source = file_get_contents($path);

    if ($source === false) {
        return [];
    }

    $offenders = [];

    /** @var list<bool> $parenExcludeStack true while inside a __()/trans()/*Exception() call */
    $parenExcludeStack = [];
    $lastSignificant = null;
    $secondLastSignificant = null;

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            [$id, $text, $line] = $token;

            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) {
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $excluded = $parenExcludeStack !== [] && end($parenExcludeStack) === true;
                $contents = substr($text, 1, -1);

                if (! $excluded && strlen($contents) > 15 && str_contains($contents, ' ')) {
                    $offenders[] = sprintf('line %d: %s', $line, $text);
                }

                $secondLastSignificant = $lastSignificant;
                $lastSignificant = $text;

                continue;
            }
        } else {
            $text = $token;
        }

        if ($text === '(') {
            $exclude = match (true) {
                $lastSignificant === '__' || $lastSignificant === 'trans' => true,
                $lastSignificant !== null
                    && preg_match('/(Exception|Error)$/', $lastSignificant) === 1
                    && $secondLastSignificant === 'new' => true,
                default => $parenExcludeStack !== [] && end($parenExcludeStack) === true,
            };

            $parenExcludeStack[] = $exclude;
        } elseif ($text === ')') {
            array_pop($parenExcludeStack);
        }

        $secondLastSignificant = $lastSignificant;
        $lastSignificant = $text;
    }

    return $offenders;
}

it('finds every health check file', function () {
    expect(opesHealthCheckFiles())->not->toBeEmpty();
});

it('has no hardcoded user-facing English strings in any health check', function () {
    foreach (opesHealthCheckFiles() as $file) {
        $offenders = opesFindHardcodedHealthStrings($file);

        expect($offenders)->toBe(
            [],
            sprintf(
                "%s contains what looks like hardcoded user-facing text instead of a __() translation call:\n%s",
                basename($file),
                implode("\n", $offenders),
            ),
        );
    }
});

it('actually catches a reintroduced hardcoded string', function () {
    // Proves the heuristic is not vacuous: write a scratch file shaped like a
    // real check with a literal English sentence where __() should be, and
    // confirm the scanner flags it.
    $scratch = tempnam(sys_get_temp_dir(), 'opes_health_guard_').'.php';

    file_put_contents($scratch, <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Operations\Health\Checks;

        final class ScratchCheck
        {
            public function run(): HealthCheckResult
            {
                return new HealthCheckResult(
                    key: 'scratch.check',
                    label: 'Scratch check',
                    status: HealthStatus::Red,
                    detail: 'This is a hardcoded English sentence that should be translated.',
                    remedy: 'Do the obviously correct thing right now.',
                );
            }
        }
        PHP);

    try {
        $offenders = opesFindHardcodedHealthStrings($scratch);

        expect($offenders)->not->toBeEmpty();
        expect(implode("\n", $offenders))->toContain('hardcoded English sentence');
    } finally {
        unlink($scratch);
    }
});

it('does not flag a check file that already uses translation keys', function () {
    // A translation-key argument such as 'opes.health.database.label' is
    // longer than 15 characters but has no space, so it is never flagged
    // regardless of the __() exclusion - this pins that down explicitly.
    $scratch = tempnam(sys_get_temp_dir(), 'opes_health_guard_ok_').'.php';

    file_put_contents($scratch, <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace App\Modules\Operations\Health\Checks;

        final class ScratchOkCheck
        {
            public function run(): HealthCheckResult
            {
                return new HealthCheckResult(
                    key: 'scratch.check',
                    label: (string) __('opes.health.scratch.label'),
                    status: HealthStatus::Ok,
                    detail: (string) __('opes.health.scratch.detail', ['count' => 3]),
                    remedy: '',
                );
            }
        }
        PHP);

    try {
        expect(opesFindHardcodedHealthStrings($scratch))->toBe([]);
    } finally {
        unlink($scratch);
    }
});
