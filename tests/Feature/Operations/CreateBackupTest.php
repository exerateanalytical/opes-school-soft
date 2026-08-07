<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Domain\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function backupDir(): string
{
    $dir = storage_path('framework/testing/opes-backups');
    File::ensureDirectoryExists($dir);

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-backups'));
});

it('produces a dump file that is not empty', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect(File::exists($backup->path))->toBeTrue();
    expect(File::size($backup->path))->toBeGreaterThan(0);
});

it('records a sha256 that matches the file on disk', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect($backup->sha256)->toBe(hash_file('sha256', $backup->path));
});

it('marks the backup healthy only after the dump completes', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect($backup->status())->toBe(BackupStatus::Healthy);
    expect($backup->completed_at)->not->toBeNull();
});

it('records a manifest carrying a ledger fingerprint', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect($backup->manifest)->toHaveKey('tables');
    expect($backup->manifest)->toHaveKey('ledger_fingerprint');
    expect($backup->manifest)->toHaveKey('schema_version');
});

it('names the file so backups sort chronologically', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect(basename($backup->path))->toMatch('/^opes-full-\d{8}-\d{6}\.sql$/');
});

it('records a failure rather than throwing when the dump binary is missing', function () {
    config(['opes.backup.path' => backupDir()]);
    config(['opes.mysql.dump_binary' => 'definitely-not-a-real-binary-xyz']);

    $backup = app(CreateBackup::class)->handle();

    // A failed backup must leave a RECORD, not just an exception in a log
    // nobody reads. The health check reads this table.
    expect($backup->status())->toBe(BackupStatus::Failed);
    expect($backup->failure_detail)->not->toBeNull();
});

it('falls back to a real directory when the env key is present but empty', function () {
    // env('X', $default) returns "" - NOT the default - when the key exists
    // with no value, which is exactly what .env.example prescribes. Caught the
    // hard way: a real backup landed at C:\opes-full-....sql, the filesystem
    // root, outside any rotation or backup target.
    expect(config('opes.backup.path'))->not->toBe('');
    expect(config('opes.mysql.dump_binary'))->not->toBe('');
    expect(config('opes.mysql.client_binary'))->not->toBe('');
});

it('never writes a backup to the filesystem root', function () {
    config(['opes.backup.path' => backupDir()]);

    $backup = app(CreateBackup::class)->handle();

    expect(dirname($backup->path))->not->toBe(DIRECTORY_SEPARATOR);
    expect(dirname($backup->path))->not->toMatch('/^[A-Za-z]:\\?$/');
});
