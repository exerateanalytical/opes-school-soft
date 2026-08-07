<?php

declare(strict_types=1);

// 00-core 14: audit rows are written by exactly one Action. If any other class
// can insert into audit_logs, the hash chain has an unserialised writer and can
// fork - at which point verification is meaningless.

it('has exactly one writer of audit rows', function () {
    $appDir = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'app';
    $offenders = [];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDir));

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, 'WriteAuditEntry.php') || str_contains($path, 'AuditLog.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (preg_match('/AuditLog::query\(\)\s*->\s*(create|insert)/', $source) === 1) {
            $offenders[] = $path;
        }

        if (preg_match('/new\s+AuditLog\s*\(/', $source) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Only WriteAuditEntry may create audit rows: '.implode(', ', $offenders));
});

it('never lets a migration cascade a delete into the audit log', function () {
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];

    foreach ($migrations as $migration) {
        $source = (string) file_get_contents($migration);

        if (! str_contains($source, 'audit_logs')) {
            continue;
        }

        expect($source)->not->toContain('cascadeOnDelete');
        expect($source)->toContain('restrictOnDelete');
    }
});

it('keeps the chain anchor in step with the only writer', function () {
    // The anchor is what makes tail truncation detectable. If a writer advanced
    // the log without advancing the anchor, verification would start failing on
    // legitimate writes and everyone would learn to ignore it.
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Identity/Actions/WriteAuditEntry.php'
    );

    expect($source)->toContain('AuditChainAnchor');
});
