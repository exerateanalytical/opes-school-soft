<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Actions\RunRestoreDrill;
use App\Modules\Operations\Models\RestoreDrill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function drillDir(): string
{
    $dir = storage_path('framework/testing/opes-drill');
    File::ensureDirectoryExists($dir);

    return $dir;
}

function dropDrillSchemas(): void
{
    foreach (DB::select("SHOW DATABASES LIKE 'opes\\_drill\\_%'") as $row) {
        // reset() takes a reference, so the cast must land in a variable first.
        $values = (array) $row;
        $name = (string) reset($values);
        DB::statement("DROP DATABASE IF EXISTS `{$name}`");
    }
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-drill'));
    dropDrillSchemas();
});

it('restores the newest healthy backup and reports success', function () {
    config(['opes.backup.path' => drillDir()]);
    app(CreateBackup::class)->handle();

    $drill = app(RunRestoreDrill::class)->handle();

    expect($drill->status)->toBe('passed');
    expect($drill->assertions_passed)->toBeGreaterThan(0);
    expect($drill->completed_at)->not->toBeNull();
});

it('drops the scratch schema afterwards, whatever the outcome', function () {
    config(['opes.backup.path' => drillDir()]);
    app(CreateBackup::class)->handle();

    app(RunRestoreDrill::class)->handle();

    expect(DB::select("SHOW DATABASES LIKE 'opes\\_drill\\_%'"))->toBeEmpty();
});

it('fails when there is no healthy backup to exercise', function () {
    config(['opes.backup.path' => drillDir()]);

    $drill = app(RunRestoreDrill::class)->handle();

    expect($drill->status)->toBe('failed');
    expect($drill->failure_detail)->toContain('no healthy backup');
});

it('detects a corrupted dump rather than reporting success', function () {
    // 08-operations 3.6 acceptance criterion: a deliberately corrupted dump
    // must be caught by the next drill. A drill that cannot fail proves nothing.
    config(['opes.backup.path' => drillDir()]);
    $backup = app(CreateBackup::class)->handle();

    File::put($backup->path, 'CREATE TABLE broken (;;; this is not valid sql');

    $drill = app(RunRestoreDrill::class)->handle();

    expect($drill->status)->toBe('failed');
});

it('drops the scratch schema even when the drill fails', function () {
    // Otherwise a repeatedly failing drill slowly fills the disk it exists to
    // protect - the exact failure it is meant to prevent.
    config(['opes.backup.path' => drillDir()]);
    $backup = app(CreateBackup::class)->handle();
    File::put($backup->path, 'not sql at all');

    app(RunRestoreDrill::class)->handle();

    expect(DB::select("SHOW DATABASES LIKE 'opes\\_drill\\_%'"))->toBeEmpty();
});

it('records the drill so the health check can read it', function () {
    config(['opes.backup.path' => drillDir()]);
    app(CreateBackup::class)->handle();

    app(RunRestoreDrill::class)->handle();

    expect(RestoreDrill::query()->count())->toBe(1);
});
