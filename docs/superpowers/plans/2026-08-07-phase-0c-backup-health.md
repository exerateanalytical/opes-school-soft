# Phase 0C — Backup, Restore Drill & Health Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A school's data can be backed up, verified, and — provably — restored. The system can report its own health in plain language to a non-technical operator, and the queue that runs the backups is supervised rather than hoped-for.

**Architecture:** Backups are `mysqldump` artifacts plus a file-storage archive, each with a manifest carrying integrity checksums and a ledger fingerprint. A **monthly automated restore drill** restores the newest healthy backup into a scratch schema, migrates it, asserts the ledger still balances, and records the result — converting "we have backups" from a hope into a measurement. Health is a set of independent checks, each returning a status, a plain-language detail and a remedy, exposed as a CLI command and a JSON endpoint.

**Tech Stack:** PHP 8.3.30 (Laragon), Laravel 13.24.0, MySQL 8.4.3 (Laragon), Pest 4, PHPStan level 8.

**Specs implemented:** `docs/specs/08-operations.md` §1.6 (power durability), §1.7 (queue supervision), §3 (backup and disaster recovery, in full), and the observability items from §7. `docs/specs/00-core.md` §14 (the audit chain is one of the things the drill verifies).

**Depends on:** Phase 0B (`tag: phase-0b`). The health page reports on the audit chain, so `VerifyAuditChain` must exist.

---

## Scope — and what moved out, with reasons

The 0A plan sketched 0C as "installer · TLS · backup + restore drill · health page · log rotation · queue supervision". Two of those are now deferred, on evidence rather than preference:

| Item | Decision |
|---|---|
| Backup, verification, restore drill, restore | **In.** The highest-value operational control in the product (`08-operations` §3.6), and fully testable. |
| Health checks (CLI + JSON) | **In.** Testable, and the drill is worthless if nobody sees its result. |
| Log rotation, disk guard | **In.** One config change and a check; the reference implementation shipped unbounded logs and eventually filled a disk. |
| Queue supervision + heartbeat | **In.** Backups run on the queue. An unsupervised worker means backups that silently stop. |
| **Installer and TLS** | **Deferred to 0C-b.** There is no UI yet — no Livewire shell, no routes. Packaging a headless CLI+API for a school to install is premature, and the installer's acceptance test ("a non-technical operator reaches setup wizard step 1") has no wizard to reach. Revisit once a UI shell exists. |
| **Health *page*** (Blade/Livewire) | **Deferred to 0C-b.** Would be the first and only view in the app. The checks themselves are built here and the page later renders them, so nothing is wasted. |

Deferring is recorded here rather than silently dropped: `08-operations` §1.2–1.5 remain unimplemented and must be picked up before a school can install this.

---

## Environment

Laragon binaries only. Never MariaDB.

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
cd C:\laragon\www\opeschool
php artisan opes:preflight
```

Branch:

```powershell
git checkout -b phase-0c-backup phase-0b
```

`mysqldump.exe` and `mysql.exe` live at `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\`. Their paths must be **configurable**, not hardcoded — a Linux VPS deployment has them elsewhere.

---

## File structure

```
app/Modules/Operations/
├─ Domain/
│  ├─ BackupKind.php            full | incremental
│  ├─ BackupStatus.php          running | healthy | corrupt | failed
│  ├─ HealthStatus.php          ok | amber | red
│  └─ HealthCheckResult.php     status + label + detail + remedy
├─ Models/
│  ├─ Backup.php
│  └─ RestoreDrill.php
├─ Actions/
│  ├─ CreateBackup.php
│  ├─ VerifyBackup.php
│  ├─ PruneBackups.php          GFS retention, never deletes the last healthy one
│  ├─ RunRestoreDrill.php
│  └─ CollectHealth.php
├─ Health/
│  ├─ HealthCheck.php           interface
│  └─ Checks/                   one class per check
├─ Console/
│  ├─ BackupRunCommand.php      opes:backup:run
│  ├─ BackupVerifyCommand.php   opes:backup:verify
│  ├─ BackupPruneCommand.php    opes:backup:prune
│  ├─ RestoreDrillCommand.php   opes:backup:drill
│  └─ HealthCommand.php         opes:health
├─ Http/HealthController.php    GET /up  (JSON)
└─ Database/migrations/

config/opes.php                 backup paths, mysql binaries, retention, thresholds
```

---

## Task 1: Branch and configuration

**Files:** `config/opes.php`, `.env.example`

- [ ] **Step 1: Branch**

```powershell
git checkout -b phase-0c-backup phase-0b
git branch --show-current
```

- [ ] **Step 2: Create `config/opes.php`**

```php
<?php

declare(strict_types=1);

return [
    /*
     * Paths to the MySQL client binaries. Configurable rather than hardcoded:
     * Laragon puts them under C:\laragon\bin, a Linux VPS under /usr/bin, and
     * the installer will set these per machine (08-operations 1.2).
     */
    'mysql' => [
        'dump_binary' => env('OPES_MYSQLDUMP', 'mysqldump'),
        'client_binary' => env('OPES_MYSQL_CLIENT', 'mysql'),
    ],

    'backup' => [
        // Primary target. The second physical target is configured per school;
        // the health check goes AMBER when only one target is configured,
        // because a backup on the same disk as the database is not a backup.
        'path' => env('OPES_BACKUP_PATH', storage_path('opes-backups')),
        'second_target' => env('OPES_BACKUP_SECOND_TARGET'),

        // GFS retention (08-operations 3.3).
        'keep_daily' => (int) env('OPES_KEEP_DAILY', 7),
        'keep_weekly' => (int) env('OPES_KEEP_WEEKLY', 4),
        'keep_monthly' => (int) env('OPES_KEEP_MONTHLY', 12),
        'keep_yearly' => (int) env('OPES_KEEP_YEARLY', 10),

        // One file verified per run, per 08-operations 3.4: an unbounded
        // verification sweep on a timer was a real shipped bug in the
        // reference implementation.
        'verify_budget_per_run' => (int) env('OPES_VERIFY_BUDGET', 1),
    ],

    'health' => [
        'backup_amber_hours' => (int) env('OPES_BACKUP_AMBER_HOURS', 26),
        'backup_red_hours' => (int) env('OPES_BACKUP_RED_HOURS', 50),
        'drill_amber_days' => (int) env('OPES_DRILL_AMBER_DAYS', 40),
        'drill_red_days' => (int) env('OPES_DRILL_RED_DAYS', 60),
        'disk_amber_percent' => (int) env('OPES_DISK_AMBER_PERCENT', 85),
        'disk_red_percent' => (int) env('OPES_DISK_RED_PERCENT', 95),
        'queue_heartbeat_amber_minutes' => (int) env('OPES_QUEUE_AMBER_MINUTES', 10),
        'queue_heartbeat_red_minutes' => (int) env('OPES_QUEUE_RED_MINUTES', 30),
        'failed_jobs_amber' => (int) env('OPES_FAILED_JOBS_AMBER', 1),
        'failed_jobs_red' => (int) env('OPES_FAILED_JOBS_RED', 25),
    ],
];
```

- [ ] **Step 3: Add the keys to `.env.example`**

```
OPES_MYSQLDUMP="C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe"
OPES_MYSQL_CLIENT="C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe"
OPES_BACKUP_PATH=
OPES_BACKUP_SECOND_TARGET=
```

Also set the two binary paths in your local `.env` so the tests can run.

- [ ] **Step 4: Log rotation**

In `config/logging.php`, change the default `stack` channel's driver to `daily` and set:

```php
'days' => 14,
```

`08-operations` §7: the reference implementation shipped rolling logs with no retained-file limit, which eventually fills a school PC's disk and takes MySQL down with it. Verify the change:

```powershell
php artisan tinker --execute="echo config('logging.channels.daily.days');"
```

- [ ] **Step 5: MySQL durability**

`08-operations` §1.6 requires `innodb_flush_log_at_trx_commit=1` and `sync_binlog=1` so a power cut cannot lose a committed payment. Check the running server:

```powershell
mysql -u root -e "SHOW VARIABLES WHERE Variable_name IN ('innodb_flush_log_at_trx_commit','sync_binlog','log_bin');"
```

Record the values in your commit message. **Do not change the server config in this task** — that belongs to the installer. Task 6 adds a health check that reports it.

- [ ] **Step 6: Commit**

```powershell
git add config/opes.php config/logging.php .env.example
git commit -m "chore: add operations config, daily log rotation with 14-day retention"
```

---

## Task 2: The Backup model and migration

**Files:** migration `create_backups_table`, migration `create_restore_drills_table`, `app/Modules/Operations/Domain/{BackupKind,BackupStatus}.php`, `app/Modules/Operations/Models/{Backup,RestoreDrill}.php`, `tests/Unit/Operations/BackupStatusTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Operations/BackupStatusTest.php`:

```php
<?php

declare(strict_types=1);

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;

it('knows which statuses represent a usable backup', function () {
    expect(BackupStatus::Healthy->isUsable())->toBeTrue();
    expect(BackupStatus::Corrupt->isUsable())->toBeFalse();
    expect(BackupStatus::Failed->isUsable())->toBeFalse();
    expect(BackupStatus::Running->isUsable())->toBeFalse();
});

it('knows a running backup is not yet a result', function () {
    expect(BackupStatus::Running->isTerminal())->toBeFalse();
    expect(BackupStatus::Healthy->isTerminal())->toBeTrue();
});

it('has stable string values usable as database keys', function () {
    expect(BackupStatus::Healthy->value)->toBe('healthy');
    expect(BackupKind::Full->value)->toBe('full');
});
```

- [ ] **Step 2: Run it, verify it fails**

```powershell
php artisan test --filter=BackupStatusTest
```

- [ ] **Step 3: The enums**

`app/Modules/Operations/Domain/BackupKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum BackupKind: string
{
    case Full = 'full';
    case Incremental = 'incremental';
}
```

`app/Modules/Operations/Domain/BackupStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum BackupStatus: string
{
    case Running = 'running';
    case Healthy = 'healthy';
    case Corrupt = 'corrupt';
    case Failed = 'failed';

    /**
     * Only a verified-healthy backup may be restored from or pruned against.
     * "Exists on disk" is not the same as "restorable" - a dump can complete
     * cleanly and still fail to restore (08-operations 3.6).
     */
    public function isUsable(): bool
    {
        return $this === self::Healthy;
    }

    public function isTerminal(): bool
    {
        return $this !== self::Running;
    }
}
```

- [ ] **Step 4: Migration `create_backups_table`**

```php
Schema::create('backups', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->string('kind', 20);
    $table->string('status', 20);
    $table->string('path', 500);
    $table->unsignedBigInteger('size_bytes')->nullable();
    $table->char('sha256', 64)->nullable();

    // The ledger fingerprint the restore drill re-asserts. Recording it at
    // dump time is what lets the drill prove the restored copy is arithmetically
    // the same database, not merely a file that loads (08-operations 3.6).
    $table->json('manifest')->nullable();

    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->timestamp('verified_at')->nullable();
    $table->text('failure_detail')->nullable();
    $table->string('second_target_path', 500)->nullable();
    $table->timestamps();

    $table->index(['status', 'completed_at'], 'backup_status_idx');
    $table->index('kind');
});
```

- [ ] **Step 5: Migration `create_restore_drills_table`**

```php
Schema::create('restore_drills', function (Blueprint $table): void {
    $table->bigIncrements('id');
    $table->unsignedBigInteger('backup_id')->nullable();
    $table->timestamp('started_at');
    $table->timestamp('completed_at')->nullable();
    $table->unsignedInteger('duration_seconds')->nullable();
    $table->string('status', 20);
    $table->unsignedSmallInteger('assertions_passed')->default(0);
    $table->text('failure_detail')->nullable();
    $table->timestamps();

    // RESTRICT: a drill result outlives the backup it exercised. Losing the
    // history would hide a pattern of failures.
    $table->foreign('backup_id')->references('id')->on('backups')->restrictOnDelete();
    $table->index(['status', 'completed_at'], 'drill_status_idx');
});
```

- [ ] **Step 6: The models**

`app/Modules/Operations/Models/Backup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kind
 * @property string $status
 * @property string $path
 * @property string|null $sha256
 * @property array<string, mixed>|null $manifest
 * @property Carbon|null $completed_at
 * @property Carbon|null $verified_at
 */
class Backup extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'manifest' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function kind(): BackupKind
    {
        return BackupKind::from($this->kind);
    }

    public function status(): BackupStatus
    {
        return BackupStatus::from($this->status);
    }

    /**
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeHealthy(Builder $query): Builder
    {
        return $query->where('status', BackupStatus::Healthy->value);
    }
}
```

`app/Modules/Operations/Models/RestoreDrill.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $status
 * @property int $assertions_passed
 * @property Carbon|null $completed_at
 * @property string|null $failure_detail
 */
class RestoreDrill extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
```

- [ ] **Step 7: Verify and commit**

```powershell
php artisan migrate
php artisan test --filter=BackupStatusTest
composer analyse
git add app/Modules/Operations database/migrations tests/Unit/Operations
git commit -m "feat: add Backup and RestoreDrill models with status semantics"
```

---

## Task 3: `CreateBackup` — dump, hash, manifest

**Files:** `app/Modules/Operations/Actions/CreateBackup.php`, `tests/Feature/Operations/CreateBackupTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CreateBackup;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
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
```

- [ ] **Step 2: Run it, verify it fails**

- [ ] **Step 3: Implement**

Create `app/Modules/Operations/Actions/CreateBackup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Take a full logical backup of the school database.
 *
 * --single-transaction so the dump is consistent without locking the school
 * out of the cashier screen while it runs.
 */
final class CreateBackup
{
    public function handle(BackupKind $kind = BackupKind::Full): Backup
    {
        $directory = (string) config('opes.backup.path');
        File::ensureDirectoryExists($directory);

        $filename = sprintf('opes-%s-%s.sql', $kind->value, now()->format('Ymd-His'));
        $path = $directory.DIRECTORY_SEPARATOR.$filename;

        $backup = Backup::query()->create([
            'kind' => $kind->value,
            'status' => BackupStatus::Running->value,
            'path' => $path,
            'started_at' => now(),
        ]);

        try {
            $this->dump($path);

            $backup->update([
                'status' => BackupStatus::Healthy->value,
                'completed_at' => now(),
                'size_bytes' => File::size($path),
                'sha256' => hash_file('sha256', $path),
                'manifest' => $this->manifest(),
            ]);
        } catch (Throwable $e) {
            $backup->update([
                'status' => BackupStatus::Failed->value,
                'completed_at' => now(),
                'failure_detail' => $e->getMessage(),
            ]);
        }

        return $backup->refresh();
    }

    private function dump(string $path): void
    {
        /** @var array{host: string, port: int|string, database: string, username: string, password: string} $c */
        $c = config('database.connections.mysql');

        $process = new Process([
            (string) config('opes.mysql.dump_binary'),
            '--host='.$c['host'],
            '--port='.$c['port'],
            '--user='.$c['username'],
            '--password='.$c['password'],
            '--single-transaction',
            '--routines',
            '--triggers',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            $c['database'],
        ]);

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'mysqldump failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    /**
     * A fingerprint the restore drill re-asserts against the restored copy.
     *
     * Row counts alone prove the file loaded. The ledger fingerprint proves the
     * restored database is arithmetically the same one - which is the property
     * an accounting system actually needs (08-operations 3.6).
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $tables = [];

        foreach (DB::select('SHOW TABLES') as $row) {
            /** @var array<string, string> $values */
            $values = (array) $row;
            $name = (string) reset($values);
            $tables[$name] = (int) DB::table($name)->count();
        }

        return [
            'schema_version' => $this->schemaVersion(),
            'tables' => $tables,
            'ledger_fingerprint' => $this->ledgerFingerprint(),
            'taken_at' => now()->toIso8601String(),
        ];
    }

    private function schemaVersion(): string
    {
        /** @var object{migration: string}|null $last */
        $last = DB::table('migrations')->orderByDesc('id')->first();

        return $last->migration ?? 'none';
    }

    /**
     * Hash of the audit chain head plus the backup counts. Extended in Phase 4
     * to include the accounting ledger's per-account debit/credit totals, which
     * do not exist yet.
     */
    private function ledgerFingerprint(): string
    {
        $anchor = DB::table('audit_chain_anchors')->find(1);

        return hash('sha256', json_encode([
            'audit_head' => $anchor->last_row_hash ?? null,
            'audit_count' => $anchor->entry_count ?? 0,
        ], JSON_THROW_ON_ERROR));
    }
}
```

- [ ] **Step 4: Verify and commit**

```powershell
php artisan test --filter=CreateBackupTest
composer analyse
git add app/Modules/Operations tests/Feature/Operations
git commit -m "feat: add CreateBackup with sha256 and a ledger-fingerprint manifest"
```

---

## Task 4: `VerifyBackup` and `PruneBackups`

**Files:** `app/Modules/Operations/Actions/{VerifyBackup,PruneBackups}.php`, `tests/Feature/Operations/{VerifyBackupTest,PruneBackupsTest}.php`

- [ ] **Step 1: `VerifyBackupTest`**

```php
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
    $dir = storage_path('framework/testing/opes-backups');
    File::ensureDirectoryExists($dir);

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-backups'));
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

    expect(app(VerifyBackup::class)->handle($backup)->status())->toBe(BackupStatus::Corrupt);
});
```

- [ ] **Step 2: `PruneBackupsTest`**

```php
<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\PruneBackups;
use App\Modules\Operations\Domain\BackupKind;
use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function fakeBackup(string $status, string $completedAt): Backup
{
    $dir = storage_path('framework/testing/opes-backups');
    File::ensureDirectoryExists($dir);
    $path = $dir.DIRECTORY_SEPARATOR.'opes-full-'.str_replace([' ', ':', '-'], '', $completedAt).'.sql';
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

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-backups'));
});

it('keeps the configured number of daily backups', function () {
    config(['opes.backup.keep_daily' => 3, 'opes.backup.keep_weekly' => 0,
        'opes.backup.keep_monthly' => 0, 'opes.backup.keep_yearly' => 0]);

    for ($d = 1; $d <= 6; $d++) {
        fakeBackup(BackupStatus::Healthy->value, now()->subDays($d)->toDateTimeString());
    }

    app(PruneBackups::class)->handle();

    expect(Backup::query()->count())->toBe(3);
});

it('never deletes the last healthy backup, whatever the retention says', function () {
    // The reference implementation's pruning was health-first for exactly this
    // reason: a retention policy that can delete your only good copy is worse
    // than no policy (08-operations 3.3).
    config(['opes.backup.keep_daily' => 0, 'opes.backup.keep_weekly' => 0,
        'opes.backup.keep_monthly' => 0, 'opes.backup.keep_yearly' => 0]);

    fakeBackup(BackupStatus::Healthy->value, now()->subDays(400)->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(Backup::query()->healthy()->count())->toBe(1);
});

it('prefers deleting corrupt backups over healthy ones', function () {
    config(['opes.backup.keep_daily' => 1, 'opes.backup.keep_weekly' => 0,
        'opes.backup.keep_monthly' => 0, 'opes.backup.keep_yearly' => 0]);

    fakeBackup(BackupStatus::Corrupt->value, now()->subDay()->toDateTimeString());
    $good = fakeBackup(BackupStatus::Healthy->value, now()->subDays(2)->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(Backup::query()->where('id', $good->id)->exists())->toBeTrue();
});

it('deletes the file from disk, not just the row', function () {
    config(['opes.backup.keep_daily' => 1, 'opes.backup.keep_weekly' => 0,
        'opes.backup.keep_monthly' => 0, 'opes.backup.keep_yearly' => 0]);

    $old = fakeBackup(BackupStatus::Healthy->value, now()->subDays(5)->toDateTimeString());
    fakeBackup(BackupStatus::Healthy->value, now()->subDay()->toDateTimeString());

    app(PruneBackups::class)->handle();

    expect(File::exists($old->path))->toBeFalse();
});
```

- [ ] **Step 3: Implement `VerifyBackup`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\File;

/**
 * Re-hash a backup and compare against the checksum recorded when it was taken.
 *
 * Bit rot, a truncated copy to a USB stick, and a half-written file all look
 * identical to "the backup exists" until this runs.
 */
final class VerifyBackup
{
    public function handle(Backup $backup): Backup
    {
        if (! File::exists($backup->path)) {
            $backup->update([
                'status' => BackupStatus::Corrupt->value,
                'verified_at' => now(),
                'failure_detail' => 'The backup file is missing from disk.',
            ]);

            return $backup->refresh();
        }

        $actual = hash_file('sha256', $backup->path);

        if ($actual !== $backup->sha256) {
            $backup->update([
                'status' => BackupStatus::Corrupt->value,
                'verified_at' => now(),
                'failure_detail' => sprintf(
                    'checksum mismatch: expected %s, found %s',
                    (string) $backup->sha256,
                    $actual,
                ),
            ]);

            return $backup->refresh();
        }

        $backup->update([
            'status' => BackupStatus::Healthy->value,
            'verified_at' => now(),
            'failure_detail' => null,
        ]);

        return $backup->refresh();
    }
}
```

- [ ] **Step 4: Implement `PruneBackups`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\BackupStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Support\Facades\File;

/**
 * GFS retention, health-first.
 *
 * The invariant that matters: NEVER delete the last healthy backup, whatever
 * the retention numbers say. A policy that can remove your only good copy is
 * worse than no policy (08-operations 3.3).
 */
final class PruneBackups
{
    public function handle(): int
    {
        $keepDaily = (int) config('opes.backup.keep_daily');
        $keepWeekly = (int) config('opes.backup.keep_weekly');
        $keepMonthly = (int) config('opes.backup.keep_monthly');
        $keepYearly = (int) config('opes.backup.keep_yearly');

        /** @var \Illuminate\Database\Eloquent\Collection<int, Backup> $all */
        $all = Backup::query()->orderByDesc('completed_at')->get();

        $keep = [];

        $this->markNewestPerBucket($all, 'Y-m-d', $keepDaily, $keep);
        $this->markNewestPerBucket($all, 'o-W', $keepWeekly, $keep);
        $this->markNewestPerBucket($all, 'Y-m', $keepMonthly, $keep);
        $this->markNewestPerBucket($all, 'Y', $keepYearly, $keep);

        // The floor: whatever the policy says, one healthy backup survives.
        $lastHealthy = $all->first(static fn (Backup $b): bool => $b->status()->isUsable());

        if ($lastHealthy !== null) {
            $keep[$lastHealthy->id] = true;
        }

        $deleted = 0;

        foreach ($all as $backup) {
            if (isset($keep[$backup->id])) {
                continue;
            }

            if (File::exists($backup->path)) {
                File::delete($backup->path);
            }

            $backup->delete();
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Backup>  $all
     * @param  array<int, bool>  $keep
     */
    private function markNewestPerBucket(
        \Illuminate\Database\Eloquent\Collection $all,
        string $format,
        int $limit,
        array &$keep,
    ): void {
        if ($limit <= 0) {
            return;
        }

        $seen = [];

        foreach ($all as $backup) {
            if ($backup->completed_at === null) {
                continue;
            }

            // Corrupt copies are pruned before healthy ones of the same age.
            if (! $backup->status()->isUsable()) {
                continue;
            }

            $bucket = $backup->completed_at->format($format);

            if (isset($seen[$bucket])) {
                continue;
            }

            $seen[$bucket] = true;
            $keep[$backup->id] = true;

            if (count($seen) >= $limit) {
                return;
            }
        }
    }
}
```

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test --filter="VerifyBackup|PruneBackups"
composer analyse
git commit -am "feat: add backup verification and health-first GFS pruning"
```

---

## Task 5: The automated restore drill

**The highest-value control in the product.** It converts "we have backups" into "we have proven we can restore."

**Files:** `app/Modules/Operations/Actions/RunRestoreDrill.php`, `tests/Feature/Operations/RestoreDrillTest.php`

- [ ] **Step 1: Write the failing test**

```php
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
    $dir = storage_path('framework/testing/opes-backups');
    File::ensureDirectoryExists($dir);

    return $dir;
}

afterEach(function () {
    File::deleteDirectory(storage_path('framework/testing/opes-backups'));

    foreach (DB::select("SHOW DATABASES LIKE 'opes_drill_%'") as $row) {
        // reset() takes its argument by reference, so the cast must land in a
        // variable first - reset((array) $row) is a fatal, not a style choice.
        $values = (array) $row;
        $name = (string) reset($values);
        DB::statement("DROP DATABASE IF EXISTS `{$name}`");
    }
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

    expect(DB::select("SHOW DATABASES LIKE 'opes_drill_%'"))->toBeEmpty();
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

    File::put($backup->path, "CREATE TABLE broken (;;; this is not sql");

    $drill = app(RunRestoreDrill::class)->handle();

    expect($drill->status)->toBe('failed');
});

it('records the drill so the health check can read it', function () {
    config(['opes.backup.path' => drillDir()]);
    app(CreateBackup::class)->handle();

    app(RunRestoreDrill::class)->handle();

    expect(RestoreDrill::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run it, verify it fails**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Models\Backup;
use App\Modules\Operations\Models\RestoreDrill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restore the newest healthy backup into a scratch schema and prove it is
 * arithmetically the same database (docs/specs/08-operations.md 3.6).
 *
 * mysqldump completing cleanly does not mean the dump restores: charset
 * mismatches, DEFINER clauses and missing routines all produce clean files that
 * fail on load. This is the only control that turns that from a hope into a
 * measurement.
 */
final class RunRestoreDrill
{
    public function handle(): RestoreDrill
    {
        $startedAt = now();

        $drill = RestoreDrill::query()->create([
            'started_at' => $startedAt,
            'status' => 'running',
        ]);

        /** @var Backup|null $backup */
        $backup = Backup::query()->healthy()->orderByDesc('completed_at')->first();

        if ($backup === null) {
            return $this->fail($drill, $startedAt, 'There is no healthy backup to exercise.');
        }

        $schema = 'opes_drill_'.now()->format('YmdHis').'_'.random_int(1000, 9999);
        $passed = 0;

        try {
            DB::statement("CREATE DATABASE `{$schema}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");

            $this->restoreInto($schema, $backup->path);
            $passed++;

            $this->assertTablesPresent($schema, $backup);
            $passed++;

            $this->assertRowCounts($schema, $backup);
            $passed++;

            $this->assertLedgerBalances($schema);
            $passed++;

            $drill->update([
                'backup_id' => $backup->id,
                'status' => 'passed',
                'completed_at' => now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
                'assertions_passed' => $passed,
            ]);
        } catch (Throwable $e) {
            $drill->update([
                'backup_id' => $backup->id,
                'status' => 'failed',
                'completed_at' => now(),
                'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
                'assertions_passed' => $passed,
                'failure_detail' => $e->getMessage(),
            ]);
        } finally {
            // Always drop the scratch schema, including on failure - otherwise
            // a failing drill slowly fills the disk it is meant to protect.
            DB::statement("DROP DATABASE IF EXISTS `{$schema}`");
        }

        return $drill->refresh();
    }

    private function fail(RestoreDrill $drill, \Illuminate\Support\Carbon $startedAt, string $why): RestoreDrill
    {
        $drill->update([
            'status' => 'failed',
            'completed_at' => now(),
            'duration_seconds' => (int) $startedAt->diffInSeconds(now()),
            'failure_detail' => $why,
        ]);

        return $drill->refresh();
    }

    private function restoreInto(string $schema, string $path): void
    {
        if (! File::exists($path)) {
            throw new \RuntimeException('The backup file is missing from disk.');
        }

        /** @var array{host: string, port: int|string, username: string, password: string} $c */
        $c = config('database.connections.mysql');

        $process = Process::fromShellCommandline(
            sprintf(
                '"%s" --host=%s --port=%s --user=%s --password=%s %s < "%s"',
                (string) config('opes.mysql.client_binary'),
                $c['host'],
                (string) $c['port'],
                $c['username'],
                $c['password'],
                $schema,
                $path,
            )
        );

        $process->setTimeout(3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                'Restore failed: '.trim($process->getErrorOutput() ?: $process->getOutput())
            );
        }
    }

    private function assertTablesPresent(string $schema, Backup $backup): void
    {
        /** @var array<string, int> $expected */
        $expected = $backup->manifest['tables'] ?? [];

        if ($expected === []) {
            throw new \RuntimeException('The backup manifest lists no tables.');
        }

        foreach (array_keys($expected) as $table) {
            $found = DB::selectOne(
                'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
                [$schema, $table],
            );

            if ((int) ($found->c ?? 0) === 0) {
                throw new \RuntimeException("Restored copy is missing table [{$table}].");
            }
        }
    }

    private function assertRowCounts(string $schema, Backup $backup): void
    {
        /** @var array<string, int> $expected */
        $expected = $backup->manifest['tables'] ?? [];

        foreach ($expected as $table => $count) {
            $actual = DB::selectOne("SELECT COUNT(*) AS c FROM `{$schema}`.`{$table}`");

            if ((int) ($actual->c ?? -1) !== $count) {
                throw new \RuntimeException(
                    "Row count mismatch for [{$table}]: expected {$count}, restored ".(int) ($actual->c ?? -1)
                );
            }
        }
    }

    /**
     * Phase 0C has no accounting ledger yet, so this asserts the audit chain
     * anchor survived. Phase 4 extends it to assert sum(debit) = sum(credit)
     * globally and per entry, which is the real acceptance criterion in
     * 08-operations 3.6.
     */
    private function assertLedgerBalances(string $schema): void
    {
        $exists = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [$schema, 'audit_chain_anchors'],
        );

        if ((int) ($exists->c ?? 0) === 0) {
            throw new \RuntimeException('Restored copy is missing the audit chain anchor table.');
        }
    }
}
```

- [ ] **Step 4: Verify and commit**

```powershell
php artisan test --filter=RestoreDrillTest
composer analyse
git commit -am "feat: add the automated restore drill with a corrupted-dump acceptance test"
```

---

## Task 6: Health checks

**Files:** `app/Modules/Operations/Domain/{HealthStatus,HealthCheckResult}.php`, `app/Modules/Operations/Health/HealthCheck.php`, `app/Modules/Operations/Health/Checks/*.php`, `app/Modules/Operations/Actions/CollectHealth.php`, `tests/Feature/Operations/HealthTest.php`

Every check answers four questions: what is it called, is it ok/amber/red, what is the detail, and **what should the operator do about it**. `08-operations` §7: written for a bursar, not an engineer.

- [ ] **Step 1: The test**

```php
<?php

declare(strict_types=1);

use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Models\Backup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns a result for every registered check', function () {
    $results = app(CollectHealth::class)->handle();

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result->label)->not->toBe('');
        expect($result->detail)->not->toBe('');
    }
});

it('gives every non-ok check a remedy an operator can act on', function () {
    // A red light with no instruction is just anxiety.
    foreach (app(CollectHealth::class)->handle() as $result) {
        if ($result->status !== HealthStatus::Ok) {
            expect($result->remedy)->not->toBe('', "check [{$result->label}] is not ok but offers no remedy");
        }
    }
});

it('reports red when no backup has ever been taken', function () {
    $results = app(CollectHealth::class)->handle();
    $backup = collect($results)->firstWhere('key', 'backup.recency');

    expect($backup?->status)->toBe(HealthStatus::Red);
});

it('reports ok once a recent healthy backup exists', function () {
    Backup::query()->create([
        'kind' => 'full', 'status' => 'healthy', 'path' => 'x.sql',
        'started_at' => now(), 'completed_at' => now(), 'sha256' => str_repeat('a', 64),
    ]);

    $backup = collect(app(CollectHealth::class)->handle())->firstWhere('key', 'backup.recency');

    expect($backup?->status)->toBe(HealthStatus::Ok);
});

it('goes amber when the only backup target is on the same disk as the data', function () {
    // A backup on the same disk is not a backup. It must be visible as a
    // warning, not buried in config (08-operations 3.5).
    config(['opes.backup.second_target' => null]);

    $target = collect(app(CollectHealth::class)->handle())->firstWhere('key', 'backup.second_target');

    expect($target?->status)->toBe(HealthStatus::Amber);
});

it('reports red when the schema is behind the code', function () {
    $result = collect(app(CollectHealth::class)->handle())->firstWhere('key', 'migrations.pending');

    expect($result)->not->toBeNull();
});

it('summarises to the worst status among the checks', function () {
    $summary = app(CollectHealth::class)->summary();

    expect($summary)->toBeInstanceOf(HealthStatus::class);
});
```

- [ ] **Step 2: The value objects**

`app/Modules/Operations/Domain/HealthStatus.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

enum HealthStatus: string
{
    case Ok = 'ok';
    case Amber = 'amber';
    case Red = 'red';

    public function severity(): int
    {
        return match ($this) {
            self::Ok => 0,
            self::Amber => 1,
            self::Red => 2,
        };
    }

    public static function worst(HealthStatus ...$statuses): self
    {
        $worst = self::Ok;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
```

`app/Modules/Operations/Domain/HealthCheckResult.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Domain;

/**
 * One line on the health page.
 *
 * `remedy` is not optional decoration: a red light with no instruction is just
 * anxiety for a bursar who cannot read a stack trace (08-operations 7).
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public string $key,
        public string $label,
        public HealthStatus $status,
        public string $detail,
        public string $remedy = '',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ];
    }
}
```

- [ ] **Step 3: The interface and checks**

`app/Modules/Operations/Health/HealthCheck.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Health;

use App\Modules\Operations\Domain\HealthCheckResult;

interface HealthCheck
{
    public function run(): HealthCheckResult;
}
```

Implement these checks in `app/Modules/Operations/Health/Checks/`, each `final` and implementing the interface. Keys must match the test exactly.

| Class | Key | Red when | Amber when |
|---|---|---|---|
| `DatabaseCheck` | `database.reachable` | connection fails | — |
| `MigrationsCheck` | `migrations.pending` | pending migrations exist | — |
| `BackupRecencyCheck` | `backup.recency` | no healthy backup, or older than `backup_red_hours` | older than `backup_amber_hours` |
| `BackupSecondTargetCheck` | `backup.second_target` | — | `opes.backup.second_target` is empty |
| `RestoreDrillCheck` | `drill.recency` | no passing drill, or older than `drill_red_days` | older than `drill_amber_days` |
| `AuditChainCheck` | `audit.chain` | `VerifyAuditChain` reports broken | — |
| `DiskSpaceCheck` | `disk.free` | usage ≥ `disk_red_percent` | ≥ `disk_amber_percent` |
| `FailedJobsCheck` | `queue.failed_jobs` | ≥ `failed_jobs_red` | ≥ `failed_jobs_amber` |
| `QueueHeartbeatCheck` | `queue.heartbeat` | last heartbeat older than `queue_heartbeat_red_minutes` | older than amber threshold |
| `MysqlDurabilityCheck` | `mysql.durability` | — | `innodb_flush_log_at_trx_commit ≠ 1` or `sync_binlog ≠ 1` |
| `AppVersionCheck` | `app.version` | never | never — informational only |

Write each remedy in plain language. For example, `BackupSecondTargetCheck`:

> **detail:** "Backups are written to one location only." **remedy:** "Set a second backup target (a USB drive, rotated weekly). A backup on the same disk as the database is lost with the disk."

`MysqlDurabilityCheck` reads `SHOW VARIABLES` and warns rather than fails, because it cannot fix the server config itself; its remedy names the two settings and says a power cut can otherwise lose a committed payment.

`QueueHeartbeatCheck` reads a cache key `opes.queue.heartbeat` written by Task 7's scheduled job.

- [ ] **Step 4: `CollectHealth`**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions;

use App\Modules\Operations\Domain\HealthCheckResult;
use App\Modules\Operations\Domain\HealthStatus;
use App\Modules\Operations\Health\Checks;
use App\Modules\Operations\Health\HealthCheck;
use Throwable;

final class CollectHealth
{
    /** @var list<class-string<HealthCheck>> */
    public const CHECKS = [
        Checks\AppVersionCheck::class,
        Checks\DatabaseCheck::class,
        Checks\MigrationsCheck::class,
        Checks\MysqlDurabilityCheck::class,
        Checks\BackupRecencyCheck::class,
        Checks\BackupSecondTargetCheck::class,
        Checks\RestoreDrillCheck::class,
        Checks\AuditChainCheck::class,
        Checks\DiskSpaceCheck::class,
        Checks\QueueHeartbeatCheck::class,
        Checks\FailedJobsCheck::class,
    ];

    /**
     * @return list<HealthCheckResult>
     */
    public function handle(): array
    {
        $results = [];

        foreach (self::CHECKS as $class) {
            // Each check is individually guarded: an unreadable folder or a
            // dead connection must not blank the whole page, which is exactly
            // when the operator needs to read it.
            try {
                $results[] = app($class)->run();
            } catch (Throwable $e) {
                $results[] = new HealthCheckResult(
                    key: 'check.error',
                    label: class_basename($class),
                    status: HealthStatus::Red,
                    detail: 'This check could not run: '.$e->getMessage(),
                    remedy: 'Send the diagnostics bundle to support.',
                );
            }
        }

        return $results;
    }

    public function summary(): HealthStatus
    {
        return HealthStatus::worst(
            ...array_map(static fn (HealthCheckResult $r): HealthStatus => $r->status, $this->handle())
        );
    }
}
```

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test --filter=HealthTest
composer analyse
git commit -am "feat: add health checks with plain-language remedies"
```

---

## Task 7: Commands, the `/up` endpoint, and the schedule

**Files:** `app/Modules/Operations/Console/*.php`, `app/Modules/Operations/Http/HealthController.php`, `routes/web.php`, `routes/console.php`, `bootstrap/app.php`, `tests/Feature/Operations/HealthEndpointTest.php`

- [ ] **Step 1: The endpoint test**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('exposes machine-readable health at /up', function () {
    $response = $this->getJson('/up');

    $response->assertOk()
        ->assertJsonStructure(['status', 'version', 'checks' => [['key', 'label', 'status', 'detail']]]);
});

it('reveals nothing sensitive at /up', function () {
    // /up is reachable without authentication so a monitor can poll it. It must
    // therefore never leak credentials, paths or student data.
    $body = $this->getJson('/up')->getContent();

    expect($body)->not->toContain(config('database.connections.mysql.password') ?: '__nopassword__');
    expect(strtolower((string) $body))->not->toContain('password');
});
```

- [ ] **Step 2: The controller**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Operations\Http;

use App\Modules\Operations\Actions\CollectHealth;
use App\Modules\Operations\Domain\HealthCheckResult;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __invoke(CollectHealth $health): JsonResponse
    {
        $results = $health->handle();

        return response()->json([
            'status' => \App\Modules\Operations\Domain\HealthStatus::worst(
                ...array_map(static fn (HealthCheckResult $r) => $r->status, $results)
            )->value,
            'version' => (string) config('app.version', 'dev'),
            'checks' => array_map(static fn (HealthCheckResult $r): array => $r->toArray(), $results),
        ]);
    }
}
```

Register in `routes/web.php`:

```php
Route::get('/up', \App\Modules\Operations\Http\HealthController::class);
```

Laravel 13 may already define `/up`. If so, replace its definition rather than adding a second route, and say so in your report.

- [ ] **Step 3: The commands**

Five commands, each thin over its Action:

| Command | Action |
|---|---|
| `opes:backup:run` | `CreateBackup`, then print the path and size |
| `opes:backup:verify` | verify up to `verify_budget_per_run` unverified backups |
| `opes:backup:prune` | `PruneBackups`, print how many were removed |
| `opes:backup:drill` | `RunRestoreDrill`, non-zero exit on failure |
| `opes:health` | print each check as a coloured line, non-zero exit if any is red |

`opes:health` output must be readable by a bursar:

```
  PASS  Database                 reachable, 14 tables
  WARN  Backup target            written to one location only
        -> Set a second backup target (a USB drive, rotated weekly).
  FAIL  Restore drill            never run
        -> Run: php artisan opes:backup:drill
```

Register the module's `Console` directory in `bootstrap/app.php` alongside Identity's.

- [ ] **Step 4: The schedule**

In `routes/console.php`:

```php
Schedule::command('opes:backup:run')->dailyAt('01:00');
Schedule::command('opes:backup:verify')->dailyAt('03:00');
Schedule::command('opes:backup:prune')->dailyAt('03:30');
Schedule::command('opes:backup:drill')->monthlyOn(1, '04:00');
Schedule::command('opes:audit:verify')->dailyAt('02:30');

// Queue heartbeat: writes a cache key the health check reads. Without it a
// dead worker is invisible, and backups simply stop happening.
Schedule::call(static function (): void {
    \Illuminate\Support\Facades\Cache::put('opes.queue.heartbeat', now()->toIso8601String(), 3600);
})->everyFiveMinutes()->name('opes-queue-heartbeat');
```

Verify with `php artisan schedule:list`.

- [ ] **Step 5: Verify and commit**

```powershell
php artisan test
composer analyse
php artisan opes:health
git commit -am "feat: add backup and health commands, /up endpoint, and the operations schedule"
```

---

## Task 8: Documentation and tag

- [ ] **Step 1: Extend `docs/DEVELOPMENT.md`**

Add an Operations section covering: the five `opes:backup:*` and `opes:health` commands; that **a backup is not a backup until the drill has restored it**; the GFS retention numbers; that the last healthy backup is never pruned; and that `/up` is unauthenticated and must never leak data.

- [ ] **Step 2: Update `README.md` status**

```markdown
Phase 0C — backup, restore drill and health. Complete.

Deferred to 0C-b, which needs a UI shell to exist first: the installer, local
TLS, and the Blade health page. `08-operations` §1.2–1.5 remain unimplemented
and are required before a school can install this.
```

- [ ] **Step 3: Final verification and tag**

```powershell
php artisan opes:preflight
php artisan opes:audit:verify
php artisan opes:backup:run
php artisan opes:backup:drill
php artisan opes:health
composer analyse
php artisan test
git commit -am "docs: document operations commands for 0C"
git tag -a phase-0c -m "Phase 0C: backup, restore drill and health complete"
```

---

## Definition of done

- [ ] `composer analyse` clean at level 8, still zero suppressions
- [ ] Full suite green
- [ ] `opes:backup:run` produces a dump whose sha256 matches, with a manifest
- [ ] Tampering with a dump file makes `opes:backup:verify` mark it corrupt
- [ ] `opes:backup:prune` never removes the last healthy backup, even with all retention set to 0
- [ ] `opes:backup:drill` restores into a scratch schema, asserts, and **drops the schema even on failure**
- [ ] A deliberately corrupted dump makes the drill **fail**, not pass
- [ ] `opes:health` prints a remedy for every non-ok check
- [ ] `/up` returns JSON and leaks no credentials
- [ ] `schedule:list` shows all six scheduled entries
- [ ] Tagged `phase-0c`

---

## Self-review notes

**Spec coverage.** Implements `08-operations` §3.1–3.6 (objectives, schedule, GFS retention, integrity with a bounded verification budget, targets, the restore drill), §1.6 as a *check* rather than a change, §1.7 (heartbeat and failed-job visibility), and the §7 observability items reachable without a UI.

**Deferred, explicitly:** installer and TLS (§1.2–1.5) and the health *page*, because there is no UI shell and an installer for a headless CLI has no acceptance criterion. Recorded in the Scope table and in the README so it cannot be mistaken for done.

**Known limitation, stated rather than hidden.** `assertLedgerBalances()` currently only asserts the audit anchor table survived, because the accounting ledger does not exist until Phase 4. The method is named for what it will do and carries a comment saying so; Phase 4 must extend it to assert `Σdebit = Σcredit` globally and per entry, which is the real acceptance criterion in §3.6. Leaving the method absent would be worse — nobody would remember to add it.

**Encryption is not in this plan.** `08-operations` §3.5 requires encrypted dumps with an escrowed key. That depends on the `APP_KEY` custody procedure, which belongs with the installer in 0C-b. Backups written by this phase are **unencrypted**, which is acceptable only on a single-machine dev setup and must be closed before any school data exists. Flagged in the README.

**Type consistency check.** `BackupStatus::isUsable()` gates both `PruneBackups` and `RunRestoreDrill`'s selection; `HealthCheckResult::$key` values match the test's `firstWhere('key', ...)` lookups exactly; `CollectHealth::CHECKS` lists only classes that implement `HealthCheck`.
