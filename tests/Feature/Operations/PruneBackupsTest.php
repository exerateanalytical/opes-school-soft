<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\PruneBackups;
use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function pruneDir(): string
{
    $dir = storage_path('framework/testing/opes-prune');
    File::ensureDirectoryExists($dir);

    return $dir;
}

function fakeBackup(string $status, string $completedAt): Backup
{
    $path = pruneDir().DIRECTORY_SEPARATOR.'opes-full-'.md5($completedAt.$status.uniqid()).'.sql';
    File::put($path, '-- dump');

    return Backup::query()->create([
        'kind' => BackupKind::Full->value,
        'status' => $status,
        'path' => $path,
        'started_at' => $completedAt,
        'completed_at' => $completedAt,
        'sha256' => hash_file('sha256', $path),
    ]);
}

function onlyKeepDaily(int $n): void
{
    config([
        'opes.backup.keep_daily' => $n,
        'opes.backup.keep_weekly' => 0,
        'opes.backup.keep_monthly' => 0,
        'opes.backup.keep_yearly' => 0,
    ]);
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-prune'));
});

it('keeps the configured number of daily backups', function () {
    onlyKeepDaily(3);

    for ($d = 1; $d <= 6; $d++) {
        fakeBackup(BackupStatus::Healthy->value, now()->subDays($d)->toDateTimeString());
    }

    app(PruneBackups::class)->handle();

    expect(Backup::query()->count())->toBe(3);
});

it('never deletes the last healthy backup, whatever the retention says', function () {
    // A retention policy that can delete your only good copy is worse than no
    // policy at all (08-operations 3.3).
    onlyKeepDaily(0);

    fakeBackup(BackupStatus::Healthy->value, now()->subDays(400)->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(Backup::query()->healthy()->count())->toBe(1);
});

it('prefers deleting corrupt backups over healthy ones', function () {
    onlyKeepDaily(1);

    fakeBackup(BackupStatus::Corrupt->value, now()->subDay()->toDateTimeString());
    $good = fakeBackup(BackupStatus::Healthy->value, now()->subDays(2)->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(Backup::query()->whereKey($good->id)->exists())->toBeTrue();
});

it('deletes the file from disk, not just the row', function () {
    onlyKeepDaily(1);

    $old = fakeBackup(BackupStatus::Healthy->value, now()->subDays(5)->toDateTimeString());
    fakeBackup(BackupStatus::Healthy->value, now()->subDay()->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(File::exists($old->path))->toBeFalse();
});

it('reports how many it removed', function () {
    onlyKeepDaily(1);

    fakeBackup(BackupStatus::Healthy->value, now()->subDays(3)->toDateTimeString());
    fakeBackup(BackupStatus::Healthy->value, now()->subDays(2)->toDateTimeString());
    fakeBackup(BackupStatus::Healthy->value, now()->subDay()->toDateTimeString());

    expect(app(PruneBackups::class)->handle())->toBe(2);
});
