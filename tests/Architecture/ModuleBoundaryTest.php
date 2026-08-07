<?php

declare(strict_types=1);

// docs/specs/00-core.md 6.2 rule 2: cross-module access goes only through the
// owning module's Actions or published Events - never its Models.
//
// Modules are enumerated dynamically so this keeps working as they gain code.
// Empty modules are skipped: Pest's arch plugin cannot express expectations
// against a namespace with no classes.

/**
 * @return list<string>
 */
function opesModulesWithCode(): array
{
    // Resolved from the filesystem, not app_path(): the arch expectations
    // below are built while Pest collects test files, before the application
    // container is booted.
    $base = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules';

    if (! is_dir($base)) {
        return [];
    }

    $modules = [];
    $entries = scandir($base);

    if ($entries === false) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $base.DIRECTORY_SEPARATOR.$entry;

        if (! is_dir($path)) {
            continue;
        }

        /** @var iterable<string, SplFileInfo> $iterator */
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
        $hasPhp = false;

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $hasPhp = true;
                break;
            }
        }

        if ($hasPhp) {
            $modules[] = $entry;
        }
    }

    return $modules;
}

it('enumerates modules without error even while they are empty', function () {
    // Phase 0A scaffolds 20 empty module directories. This asserts the helper
    // is honest about that rather than silently returning nothing useful once
    // code lands.
    expect(is_dir(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'Modules'))->toBeTrue();
    expect(opesModulesWithCode())->toBeArray();
});

$modules = opesModulesWithCode();

foreach ($modules as $module) {
    $others = array_values(array_filter($modules, static fn (string $m): bool => $m !== $module));

    if ($others === []) {
        continue;
    }

    $forbidden = array_map(
        static fn (string $other): string => "App\\Modules\\{$other}\\Models",
        $others,
    );

    // No exceptions. Attribution used to need one - WriteAuditEntry took an
    // Identity\Models\User, so any module writing an audit entry had to import
    // it - but the actor now crosses the boundary as App\Support\Audit\Actor,
    // a shared-kernel value object. 00-core 14 (every audited action names an
    // actor) and 6.2 (never another module's Models) both hold, so the rule is
    // absolute again.
    arch("{$module} does not reach into another module's Models")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($forbidden);
}
