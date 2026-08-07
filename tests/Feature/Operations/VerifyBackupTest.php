<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Actions\VerifyBackup;
use App\Modules\Operations\Domain\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function verifyDir(): string
{
    $dir = storage_path('framework/testing/opes-verify');
    File::ensureDirectoryExists($dir);

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-verify'));
});

it('confirms an untouched backup is still healthy', function () {
    config(['opes.backup.path' => verifyDir()]);
    $backup = app(CreateBackup::class)->handle();

    $verified = app(VerifyBackup::class)->handle($backup);

    expect($verified->status())->toBe(BackupStatus::Healthy);
    expect($verified->verified_at)->not->toBeNull();
});

it('marks a backup corrupt when its bytes changed after the hash was taken', function () {
    config(['opes.backup.path' => verifyDir()]);
    $backup = app(CreateBackup::class)->handle();

    File::append($backup->path, "\n-- tampered\n");

    $verified = app(VerifyBackup::class)->handle($backup);

    expect($verified->status())->toBe(BackupStatus::Corrupt);
    expect($verified->failure_detail)->toContain('checksum');
});

it('marks a backup corrupt when the file has vanished', function () {
    config(['opes.backup.path' => verifyDir()]);
    $backup = app(CreateBackup::class)->handle();

    File::delete($backup->path);

    $verified = app(VerifyBackup::class)->handle($backup);

    expect($verified->status())->toBe(BackupStatus::Corrupt);
    expect($verified->failure_detail)->toContain('missing');
});
