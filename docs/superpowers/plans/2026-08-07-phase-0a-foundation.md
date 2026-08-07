# Phase 0A — Foundation & Kernel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stand up the OPES SCHOOL Laravel application skeleton with enforced module boundaries, a preflight guard that refuses to run on the wrong database engine, and the three numeric value objects (`Money`, `Rate`, `Score`) that every financial and academic calculation in the system depends on — all proven by tests.

**Architecture:** A modular monolith. Business logic lives in framework-agnostic `Domain/` and `Actions/` directories under `app/Modules/<Module>/`; HTTP controllers and Livewire components are thin adapters over the same Actions. Module boundaries are enforced by Pest architecture tests, not convention. All money is `BIGINT SIGNED` whole FCFA handled through a `Money` value object; all rates are integer basis points; floats are forbidden in both and the ban is machine-checked.

**Tech Stack:** PHP 8.3.30 (Laragon), Laravel 12, MySQL 8.4.3 (Laragon), Composer, Pest 3, PHPStan level 8, `spatie/laravel-permission` (Phase 0B), Tailwind + Livewire 3 (Phase 0C).

**Specs implemented:** `docs/specs/00-core.md` §4 (fixed decisions), §5 (naming), §6 (architecture), §7 (numeric policy). Everything else in Phase 0 is covered by plans 0B–0D (see §Scope below).

---

## Scope

Phase 0 in `00-core.md` §15 is fourteen workstreams. This plan is the first of four:

| Plan | Contents | Produces |
|---|---|---|
| **0A (this)** | Skeleton · preflight · module structure · `Money`/`Rate`/`Score` · `business_date()` · architecture tests · CI | An app that boots, refuses the wrong DB engine, enforces its own boundaries, and has provably correct arithmetic |
| 0B | Auth · roles · permissions · hash-chained audit · settings registry · i18n | A school can log in and every change is recorded |
| 0C | Installer · TLS · backup + verified restore drill · health page · log rotation · queue supervision | An operator can install, back up, and see system state |
| 0D | 1,200-student reference fixture · performance-budget harness · demo data with manifest | CI can catch performance regressions before they ship |

**0A must be complete before any other plan starts.** `Money` is a dependency of Phases 4–6 and 11; getting it wrong later is a data-migration problem across every financial table.

---

## Environment

Laragon binaries only. **Do not use any other PHP or MySQL on this machine** — Laragon also ships MariaDB, which `00-core` §4 explicitly forbids.

| Tool | Path |
|---|---|
| PHP 8.3.30 | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` |
| Composer | `C:\laragon\bin\composer\composer.bat` |
| MySQL 8.4.3 client | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysql.exe` |
| MySQL 8.4.3 server | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqld.exe` |
| `mysqldump` | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\mysqldump.exe` |

**Verified present:** `pdo_mysql`, `mbstring`, `intl`, `bcmath`, `zip`, `gd`, `exif`, `fileinfo`, `openssl`, `curl`, `sodium`, and `PASSWORD_ARGON2ID`.

All shell steps below assume **PowerShell** with the session PATH set in Task 1 Step 1.

---

## File structure

```
C:\laragon\www\opeschool\
├─ app\
│  ├─ Support\                          ← shared kernel, no module owns it
│  │  ├─ Money\
│  │  │  ├─ Money.php                   value object: BIGINT SIGNED whole FCFA
│  │  │  ├─ MoneyException.php
│  │  │  └─ Allocator.php               largest-remainder split
│  │  ├─ Rate\
│  │  │  ├─ Rate.php                    integer basis points
│  │  │  └─ RateException.php
│  │  ├─ Score\
│  │  │  ├─ Score.php                   DECIMAL(6,3) semantics, half-up to 2dp
│  │  │  └─ ScoreException.php
│  │  └─ Clock\
│  │     └─ BusinessDate.php            single source of "what day is it"
│  ├─ Modules\                          ← one dir per module, created empty in 0A
│  │  └─ .gitkeep
│  └─ Console\Commands\
│     └─ PreflightCommand.php           refuses to run on the wrong stack
├─ tests\
│  ├─ Unit\Support\
│  │  ├─ MoneyTest.php
│  │  ├─ AllocatorTest.php
│  │  ├─ RateTest.php
│  │  ├─ ScoreTest.php
│  │  └─ BusinessDateTest.php
│  ├─ Feature\
│  │  └─ PreflightTest.php
│  └─ Architecture\
│     ├─ NumericPolicyTest.php          no float/decimal in money contexts
│     ├─ ModuleBoundaryTest.php         cross-module access rules
│     └─ DomainPurityTest.php           Domain/ imports no framework
├─ .github\workflows\ci.yml
├─ phpstan.neon
└─ docs\specs\                          (already exists)
```

**Why `Support/` and not a module:** `Money`, `Rate`, `Score` and `BusinessDate` are used by every module. Placing them in any one module would make every other module depend on it, defeating the boundary rules in `00-core` §6.2.

---

## Task 1: Bootstrap the Laravel skeleton

**Files:**
- Create: the Laravel 12 skeleton at `C:\laragon\www\opeschool\`
- Create: `.gitignore`, `README.md`

- [ ] **Step 1: Set the session PATH to Laragon binaries**

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
php -v
composer -V
mysql --version
```

Expected: `PHP 8.3.30`, a Composer version line, and `mysql ... Ver 8.4.3 ... (MySQL Community Server - GPL)`.

**If `mysql --version` reports MariaDB, stop.** The PATH is wrong and every later step will build against a forbidden engine.

- [ ] **Step 2: Create the Laravel project in a temp dir and move it in**

The project directory already contains `docs\` and `frontend images\`, so `composer create-project` cannot target it directly.

```powershell
cd C:\laragon\www
composer create-project laravel/laravel opeschool-tmp --no-interaction
Get-ChildItem -Path C:\laragon\www\opeschool-tmp -Force | Move-Item -Destination C:\laragon\www\opeschool -Force
Remove-Item C:\laragon\www\opeschool-tmp -Recurse -Force
cd C:\laragon\www\opeschool
```

- [ ] **Step 3: Verify the app boots**

```powershell
php artisan --version
```

Expected: `Laravel Framework 12.x.x`

- [ ] **Step 4: Pin the PHP requirement**

Edit `composer.json`, set the `require.php` line to:

```json
"php": "^8.3",
```

- [ ] **Step 5: Initialise git and commit**

```powershell
git init
git add -A
git commit -m "chore: bootstrap Laravel 12 skeleton"
```

---

## Task 2: Install the test and analysis toolchain

**Files:**
- Modify: `composer.json`
- Create: `phpstan.neon`

- [ ] **Step 1: Install Pest, PHPStan and the architecture plugin**

```powershell
composer require --dev pestphp/pest pestphp/pest-plugin-laravel pestphp/pest-plugin-arch larastan/larastan --no-interaction
php artisan pest:install --no-interaction
```

- [ ] **Step 2: Create `phpstan.neon`**

```neon
includes:
    - vendor/larastan/larastan/extension.neon

parameters:
    level: 8
    paths:
        - app
        - tests
    tmpDir: build/phpstan
    checkMissingIterableValueType: false
```

- [ ] **Step 3: Add composer scripts**

Add to `composer.json` under `"scripts"`:

```json
"test": "pest",
"analyse": "phpstan analyse --memory-limit=1G",
"check": ["@analyse", "@test"]
```

- [ ] **Step 4: Verify the toolchain runs**

```powershell
composer analyse
composer test
```

Expected: PHPStan reports `[OK] No errors`. Pest runs the default example tests and passes.

- [ ] **Step 5: Commit**

```powershell
git add composer.json composer.lock phpstan.neon tests/ .gitignore
git commit -m "chore: add Pest, PHPStan level 8 and arch plugin"
```

---

## Task 3: Preflight command — refuse the wrong stack

This is the guard for `00-core` §4's "MySQL 8.0.x only, MariaDB explicitly unsupported". Laragon ships MariaDB alongside MySQL, so this is a live risk, not a theoretical one.

**Files:**
- Create: `app/Console/Commands/PreflightCommand.php`
- Test: `tests/Feature/PreflightTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/PreflightTest.php`:

```php
<?php

use App\Console\Commands\PreflightCommand;

it('reports the required php extensions', function () {
    $missing = PreflightCommand::missingExtensions();

    expect($missing)->toBe([]);
});

it('accepts a MySQL 8 version string', function () {
    expect(PreflightCommand::isSupportedDatabase('8.4.3'))->toBeTrue();
    expect(PreflightCommand::isSupportedDatabase('8.0.36'))->toBeTrue();
});

it('rejects MariaDB however it identifies itself', function () {
    expect(PreflightCommand::isSupportedDatabase('10.4.32-MariaDB'))->toBeFalse();
    expect(PreflightCommand::isSupportedDatabase('5.5.5-10.11.2-MariaDB-log'))->toBeFalse();
    expect(PreflightCommand::isSupportedDatabase('11.4.2-MariaDB'))->toBeFalse();
});

it('rejects MySQL below 8', function () {
    expect(PreflightCommand::isSupportedDatabase('5.7.44'))->toBeFalse();
});

it('requires argon2id to be available', function () {
    expect(PreflightCommand::hasArgon2id())->toBeTrue();
});
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=PreflightTest
```

Expected: FAIL — `Class "App\Console\Commands\PreflightCommand" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Console/Commands/PreflightCommand.php`:

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PreflightCommand extends Command
{
    protected $signature = 'opes:preflight';

    protected $description = 'Verify this machine can run OPES SCHOOL. Refuses unsupported stacks.';

    /**
     * Extensions required by docs/specs/00-core.md and 08-operations.md.
     *
     * @var list<string>
     */
    public const REQUIRED_EXTENSIONS = [
        'pdo_mysql',
        'mbstring',
        'intl',
        'bcmath',
        'zip',
        'gd',
        'exif',
        'fileinfo',
        'openssl',
        'curl',
        'sodium',
    ];

    /**
     * @return list<string>
     */
    public static function missingExtensions(): array
    {
        return array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $ext): bool => ! extension_loaded($ext),
        ));
    }

    public static function hasArgon2id(): bool
    {
        return defined('PASSWORD_ARGON2ID');
    }

    /**
     * MySQL 8+ only. MariaDB is explicitly unsupported (00-core §4) because
     * the required utf8mb4_0900_* collations are MySQL-exclusive.
     *
     * MariaDB advertises itself in several shapes, including the legacy
     * "5.5.5-" prefix it prepends for old-client compatibility, so we reject
     * on the vendor string first and only then parse the version.
     */
    public static function isSupportedDatabase(string $version): bool
    {
        if (stripos($version, 'mariadb') !== false) {
            return false;
        }

        if (preg_match('/(\d+)\.(\d+)\.(\d+)/', $version, $m) !== 1) {
            return false;
        }

        return (int) $m[1] >= 8;
    }

    public function handle(): int
    {
        $ok = true;

        $this->line('OPES SCHOOL preflight');
        $this->line('');

        $ok = $this->checkPhpVersion() && $ok;
        $ok = $this->checkExtensions() && $ok;
        $ok = $this->checkArgon2id() && $ok;
        $ok = $this->checkDatabase() && $ok;

        $this->line('');

        if (! $ok) {
            $this->error('Preflight FAILED. Fix the items marked FAIL above before continuing.');

            return self::FAILURE;
        }

        $this->info('Preflight passed.');

        return self::SUCCESS;
    }

    private function report(bool $passed, string $label, string $detail, string $remedy = ''): bool
    {
        if ($passed) {
            $this->line(sprintf('  <fg=green>PASS</> %-22s %s', $label, $detail));

            return true;
        }

        $this->line(sprintf('  <fg=red>FAIL</> %-22s %s', $label, $detail));

        if ($remedy !== '') {
            $this->line(sprintf('       %-22s %s', '', $remedy));
        }

        return false;
    }

    private function checkPhpVersion(): bool
    {
        return $this->report(
            PHP_VERSION_ID >= 80300,
            'PHP version',
            PHP_VERSION,
            'PHP 8.3 or newer is required. Use C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe',
        );
    }

    private function checkExtensions(): bool
    {
        $missing = self::missingExtensions();

        return $this->report(
            $missing === [],
            'PHP extensions',
            $missing === [] ? 'all present' : 'missing: '.implode(', ', $missing),
            'Enable the missing extensions in php.ini and restart.',
        );
    }

    private function checkArgon2id(): bool
    {
        return $this->report(
            self::hasArgon2id(),
            'Argon2id hashing',
            self::hasArgon2id() ? 'available' : 'unavailable',
            'Argon2id is required for password hashing (00-core §9.4).',
        );
    }

    private function checkDatabase(): bool
    {
        try {
            /** @var object{version: string} $row */
            $row = DB::selectOne('select version() as version');
            $version = $row->version;
        } catch (Throwable $e) {
            return $this->report(
                false,
                'Database',
                'not reachable: '.$e->getMessage(),
                'Start MySQL 8.4.3 from Laragon and check .env credentials.',
            );
        }

        return $this->report(
            self::isSupportedDatabase($version),
            'Database engine',
            $version,
            'MySQL 8.0+ is required. MariaDB is NOT supported — the required '
            .'utf8mb4_0900_* collations are MySQL-exclusive. In Laragon, select '
            .'mysql-8.4.3-winx64, not mariadb-xampp.',
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
php artisan test --filter=PreflightTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Run the command end to end**

```powershell
php artisan opes:preflight
```

Expected: Four `PASS` lines and `Preflight passed.` (The database check needs `.env` configured — if it reports "not reachable", that is a correct failure at this stage; configure `.env` in Task 4 and re-run.)

- [ ] **Step 6: Commit**

```powershell
git add app/Console/Commands/PreflightCommand.php tests/Feature/PreflightTest.php
git commit -m "feat: add opes:preflight, rejecting MariaDB and unsupported PHP"
```

---

## Task 4: Configure the database for MySQL 8 with the correct collations

**Files:**
- Modify: `.env`, `.env.example`
- Modify: `config/database.php`

- [ ] **Step 1: Create the databases**

```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS opeschool CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci; CREATE DATABASE IF NOT EXISTS opeschool_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root -e "SHOW VARIABLES LIKE 'version';"
```

Expected: the version row reads `8.4.3`, **not** MariaDB.

- [ ] **Step 2: Configure `.env`**

Set these keys in `.env`:

```
APP_NAME="OPES SCHOOL"
APP_TIMEZONE=Africa/Douala
APP_LOCALE=en
APP_FALLBACK_LOCALE=fr

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=opeschool
DB_USERNAME=root
DB_PASSWORD=

CACHE_STORE=database
QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

`CACHE_STORE` and `QUEUE_CONNECTION` are `database` by decision, not by default — `00-core` §4 makes Redis an opt-in VPS optimisation because it has no supported Windows build.

Mirror the same keys into `.env.example` with empty credentials.

- [ ] **Step 3: Set the collation in `config/database.php`**

In the `mysql` connection array, set:

```php
'charset' => 'utf8mb4',
'collation' => 'utf8mb4_0900_ai_ci',
'strict' => true,
```

- [ ] **Step 4: Verify preflight now passes the database check**

```powershell
php artisan config:clear
php artisan opes:preflight
```

Expected: `PASS   Database engine        8.4.3` and `Preflight passed.`

- [ ] **Step 5: Point the test suite at the test database**

In `phpunit.xml`, inside `<php>`, ensure:

```xml
<env name="DB_CONNECTION" value="mysql"/>
<env name="DB_DATABASE" value="opeschool_test"/>
<env name="APP_TIMEZONE" value="Africa/Douala"/>
```

Remove any `DB_CONNECTION` value of `sqlite` and any `:memory:` database line. `00-core` §20 requires integration tests against real MySQL — SQLite would let collation and strict-mode defects through.

- [ ] **Step 6: Commit**

```powershell
git add .env.example config/database.php phpunit.xml
git commit -m "chore: configure MySQL 8 with utf8mb4_0900_ai_ci and database queue/cache"
```

---

## Task 5: Create the module directory structure

**Files:**
- Create: `app/Modules/` with one directory per module from `00-core` §6.3
- Modify: `composer.json` (autoload)

- [ ] **Step 1: Create the module skeleton**

```powershell
$modules = @(
  'Identity','SchoolProfile','Academics','Students','Guardians','Admissions',
  'Attendance','Assessment','Fees','Accounting','Tax','Procurement','Assets',
  'HR','Payroll','Library','Inventory','Welfare','Communication','Reporting'
)
$subdirs = @('Domain','Actions','Models','Http','Livewire','Policies','Events','Listeners','Database')
foreach ($m in $modules) {
  foreach ($s in $subdirs) {
    New-Item -ItemType Directory -Force -Path "app\Modules\$m\$s" | Out-Null
    New-Item -ItemType File -Force -Path "app\Modules\$m\$s\.gitkeep" | Out-Null
  }
}
Get-ChildItem app\Modules -Directory | Measure-Object | Select-Object -Expand Count
```

Expected: `20`

- [ ] **Step 2: Verify PSR-4 autoloading already covers it**

Laravel's default `composer.json` maps `"App\\": "app/"`, so `App\Modules\Fees\Actions\RecordPayment` resolves without change. Confirm:

```powershell
composer dump-autoload
php -r "require 'vendor/autoload.php'; echo class_exists('App\Modules\Fees\Actions\Nothing') ? 'unexpected' : 'autoload ok', PHP_EOL;"
```

Expected: `autoload ok`

- [ ] **Step 3: Commit**

```powershell
git add app/Modules composer.json
git commit -m "chore: scaffold the 20 module directories"
```

---

## Task 6: The `Money` value object

The highest cost-of-error code in the system. `00-core` §7.1: `BIGINT SIGNED`, whole FCFA, no float, no decimal.

**Files:**
- Create: `app/Support/Money/Money.php`
- Create: `app/Support/Money/MoneyException.php`
- Test: `tests/Unit/Support/MoneyTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/MoneyTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Money\MoneyException;

it('constructs from whole francs', function () {
    expect(Money::of(350_000)->amount())->toBe(350_000);
});

it('supports negative amounts', function () {
    expect(Money::of(-1_500)->amount())->toBe(-1_500);
    expect(Money::of(-1_500)->isNegative())->toBeTrue();
});

it('has a zero constructor', function () {
    expect(Money::zero()->amount())->toBe(0);
    expect(Money::zero()->isZero())->toBeTrue();
});

it('adds and subtracts without loss', function () {
    $a = Money::of(350_000);
    $b = Money::of(120_000);

    expect($a->plus($b)->amount())->toBe(470_000);
    expect($a->minus($b)->amount())->toBe(230_000);
});

it('goes negative on subtraction rather than throwing', function () {
    // v1 used BIGINT UNSIGNED and MySQL raised ERROR 1690 on the first
    // overpayment. Credit balances are a normal state, not an error.
    expect(Money::of(100)->minus(Money::of(250))->amount())->toBe(-150);
});

it('multiplies by an integer factor', function () {
    expect(Money::of(1_250)->times(4)->amount())->toBe(5_000);
});

it('negates', function () {
    expect(Money::of(700)->negated()->amount())->toBe(-700);
});

it('compares', function () {
    expect(Money::of(500)->isGreaterThan(Money::of(400)))->toBeTrue();
    expect(Money::of(500)->equals(Money::of(500)))->toBeTrue();
    expect(Money::of(500)->isLessThan(Money::of(500)))->toBeFalse();
});

it('rejects a float amount', function () {
    // The declare(strict_types=1) at the top of this file is load-bearing:
    // strict typing applies at the CALL SITE. Without it PHP would coerce
    // 1500.75 to 1500 with only a deprecation notice, silently losing money.
    Money::of(1_500.75);
})->throws(TypeError::class);

it('sums a list', function () {
    $sum = Money::sum([Money::of(100), Money::of(250), Money::of(-50)]);

    expect($sum->amount())->toBe(300);
});

it('sums an empty list to zero', function () {
    expect(Money::sum([])->amount())->toBe(0);
});

it('formats for display with a thin space group separator', function () {
    expect(Money::of(1_250_000)->format())->toBe('1 250 000 FCFA');
    expect(Money::of(-4_500)->format())->toBe('-4 500 FCFA');
});

it('is immutable', function () {
    $a = Money::of(100);
    $a->plus(Money::of(50));

    expect($a->amount())->toBe(100);
});

it('rejects overflow past the BIGINT SIGNED ceiling', function () {
    Money::of(PHP_INT_MAX)->plus(Money::of(1));
})->throws(MoneyException::class, 'overflow');
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=MoneyTest
```

Expected: FAIL — `Class "App\Support\Money\Money" not found`.

- [ ] **Step 3: Write the exception class**

Create `app/Support/Money/MoneyException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Money;

use RuntimeException;

final class MoneyException extends RuntimeException
{
    public static function overflow(): self
    {
        return new self('Money arithmetic overflow: result exceeds BIGINT SIGNED range.');
    }

    public static function emptyRatios(): self
    {
        return new self('allocate() requires at least one ratio.');
    }

    public static function negativeRatio(): self
    {
        return new self('allocate() ratios must be non-negative.');
    }

    public static function zeroRatioSum(): self
    {
        return new self('allocate() ratios must sum to more than zero.');
    }
}
```

- [ ] **Step 4: Write the `Money` implementation**

Create `app/Support/Money/Money.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Money;

use JsonSerializable;
use Stringable;

/**
 * Whole FCFA (XAF). Persisted as BIGINT SIGNED.
 *
 * XAF has no subunit in practice, so the stored integer IS the franc — there
 * is no minor-unit scaling. Signed because fee adjustments, disposal gains and
 * losses, cash-desk variances and credit balances are all legitimately
 * negative (docs/specs/00-core.md §7.1).
 */
final readonly class Money implements JsonSerializable, Stringable
{
    private function __construct(private int $amount)
    {
    }

    public static function of(int $amount): self
    {
        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * @param  iterable<Money>  $items
     */
    public static function sum(iterable $items): self
    {
        $total = self::zero();

        foreach ($items as $item) {
            $total = $total->plus($item);
        }

        return $total;
    }

    public function amount(): int
    {
        return $this->amount;
    }

    public function plus(self $other): self
    {
        $result = $this->amount + $other->amount;

        if (is_float($result)) {
            throw MoneyException::overflow();
        }

        return new self($result);
    }

    public function minus(self $other): self
    {
        $result = $this->amount - $other->amount;

        if (is_float($result)) {
            throw MoneyException::overflow();
        }

        return new self($result);
    }

    public function times(int $factor): self
    {
        $result = $this->amount * $factor;

        if (is_float($result)) {
            throw MoneyException::overflow();
        }

        return new self($result);
    }

    public function negated(): self
    {
        return new self(-$this->amount);
    }

    public function absolute(): self
    {
        return new self(abs($this->amount));
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->amount >= $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        return $this->amount < $other->amount;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->amount <= $other->amount;
    }

    /**
     * Split this amount across the given ratios with no franc lost or created.
     *
     * @param  list<int>  $ratios
     * @return list<Money>
     */
    public function allocate(array $ratios): array
    {
        return Allocator::allocate($this, $ratios);
    }

    /**
     * Split into N equal parts, the earliest parts absorbing the remainder.
     *
     * @return list<Money>
     */
    public function split(int $parts): array
    {
        return $this->allocate(array_fill(0, $parts, 1));
    }

    /**
     * Display format. Thin space (U+202F) as the group separator, which is the
     * francophone convention and what the printed documents use.
     */
    public function format(bool $withCurrency = true): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $digits = number_format(abs($this->amount), 0, ',', "\u{202F}");

        return $sign.$digits.($withCurrency ? ' FCFA' : '');
    }

    public function __toString(): string
    {
        return (string) $this->amount;
    }

    public function jsonSerialize(): int
    {
        return $this->amount;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```powershell
php artisan test --filter=MoneyTest
```

Expected: PASS, 14 tests. (`AllocatorTest` is Task 7; `allocate()` will fatal until then — that is expected and no `MoneyTest` case exercises it.)

- [ ] **Step 6: Commit**

```powershell
git add app/Support/Money tests/Unit/Support/MoneyTest.php
git commit -m "feat: add Money value object, BIGINT SIGNED whole FCFA"
```

---

## Task 7: The largest-remainder allocator

`00-core` §7.3: `Σ parts === total`, asserted inside the value object, with a property test over the full range. This is what stops a three-instalment plan from silently losing a franc.

**Files:**
- Create: `app/Support/Money/Allocator.php`
- Test: `tests/Unit/Support/AllocatorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/AllocatorTest.php`:

```php
<?php

use App\Support\Money\Money;
use App\Support\Money\MoneyException;

it('splits evenly when it divides cleanly', function () {
    $parts = Money::of(300_000)->allocate([1, 1, 1]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([100_000, 100_000, 100_000]);
});

it('gives the residual to the earliest parts, never losing a franc', function () {
    // 350,000 / 3 = 116,666.67. Three equal instalments cannot be exact.
    $parts = Money::of(350_000)->allocate([1, 1, 1]);
    $amounts = array_map(fn (Money $m) => $m->amount(), $parts);

    expect($amounts)->toBe([116_667, 116_667, 116_666]);
    expect(array_sum($amounts))->toBe(350_000);
});

it('respects weighted ratios', function () {
    $parts = Money::of(100_000)->allocate([50, 30, 20]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([50_000, 30_000, 20_000]);
});

it('conserves the total for negative amounts', function () {
    $parts = Money::of(-350_000)->allocate([1, 1, 1]);
    $amounts = array_map(fn (Money $m) => $m->amount(), $parts);

    expect(array_sum($amounts))->toBe(-350_000);
});

it('tolerates a zero ratio', function () {
    $parts = Money::of(1_000)->allocate([1, 0, 1]);

    expect(array_map(fn (Money $m) => $m->amount(), $parts))
        ->toBe([500, 0, 500]);
});

it('splits into equal parts', function () {
    $amounts = array_map(fn (Money $m) => $m->amount(), Money::of(10)->split(3));

    expect($amounts)->toBe([4, 3, 3]);
    expect(array_sum($amounts))->toBe(10);
});

it('rejects an empty ratio list', function () {
    Money::of(100)->allocate([]);
})->throws(MoneyException::class, 'at least one ratio');

it('rejects a negative ratio', function () {
    Money::of(100)->allocate([1, -1]);
})->throws(MoneyException::class, 'non-negative');

it('rejects ratios summing to zero', function () {
    Money::of(100)->allocate([0, 0]);
})->throws(MoneyException::class, 'more than zero');

it('conserves the total across a wide sweep of amounts and ratios', function () {
    $ratioSets = [[1, 1, 1], [50, 30, 20], [1, 2, 3, 4], [7, 11], [1], [1, 0, 0, 1]];

    foreach ($ratioSets as $ratios) {
        foreach ([1, 2, 7, 99, 100, 333, 1_000, 12_345, 350_000, 999_999] as $amount) {
            $parts = Money::of($amount)->allocate($ratios);
            $sum = array_sum(array_map(fn (Money $m) => $m->amount(), $parts));

            expect($sum)->toBe($amount, "amount {$amount} with ratios ".implode(',', $ratios));
            expect($parts)->toHaveCount(count($ratios));
        }
    }
});
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=AllocatorTest
```

Expected: FAIL — `Class "App\Support\Money\Allocator" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Money/Allocator.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * Largest-remainder allocation.
 *
 * Floor each share, then hand the leftover francs one at a time to the shares
 * with the largest fractional remainders. Ties break on the earlier index, so
 * the result is deterministic and an instalment plan always reproduces.
 *
 * Guarantee: array_sum(allocate($m, $r)) === $m->amount(), always.
 */
final class Allocator
{
    /**
     * @param  list<int>  $ratios
     * @return list<Money>
     */
    public static function allocate(Money $money, array $ratios): array
    {
        if ($ratios === []) {
            throw MoneyException::emptyRatios();
        }

        foreach ($ratios as $ratio) {
            if ($ratio < 0) {
                throw MoneyException::negativeRatio();
            }
        }

        $ratioTotal = array_sum($ratios);

        if ($ratioTotal === 0) {
            throw MoneyException::zeroRatioSum();
        }

        // Work on the magnitude so intdiv() floors consistently; PHP's intdiv
        // truncates toward zero, which would round negatives the wrong way.
        $sign = $money->amount() < 0 ? -1 : 1;
        $absolute = abs($money->amount());

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($ratios as $index => $ratio) {
            $numerator = $absolute * $ratio;
            $share = intdiv($numerator, $ratioTotal);

            $shares[$index] = $share;
            $remainders[$index] = $numerator % $ratioTotal;
            $allocated += $share;
        }

        $leftover = $absolute - $allocated;

        // Largest remainder first; earlier index wins a tie.
        $order = array_keys($remainders);
        usort(
            $order,
            static fn (int $a, int $b): int => ($remainders[$b] <=> $remainders[$a]) ?: ($a <=> $b),
        );

        for ($i = 0; $i < $leftover; $i++) {
            $shares[$order[$i]]++;
        }

        ksort($shares);

        return array_values(array_map(
            static fn (int $share): Money => Money::of($sign * $share),
            $shares,
        ));
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
php artisan test --filter=AllocatorTest
```

Expected: PASS, 10 tests. The sweep asserts conservation across 60 amount/ratio combinations.

- [ ] **Step 5: Commit**

```powershell
git add app/Support/Money/Allocator.php tests/Unit/Support/AllocatorTest.php
git commit -m "feat: add largest-remainder Money allocator with conservation property test"
```

---

## Task 8: The `Rate` value object

`00-core` §7.2: rates are integer basis points. A CNPS rate stored as a float yields different francs on different runs, which breaks the payroll reproducibility guarantee in `05-hr-payroll` §7.

**Files:**
- Create: `app/Support/Rate/Rate.php`
- Create: `app/Support/Rate/RateException.php`
- Test: `tests/Unit/Support/RateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/RateTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\Money\Money;
use App\Support\Rate\Rate;
use App\Support\Rate\RateException;

it('constructs from basis points', function () {
    expect(Rate::ofBasisPoints(4_200)->basisPoints())->toBe(4_200);
});

it('constructs from a percentage string without float drift', function () {
    expect(Rate::ofPercent('4.2')->basisPoints())->toBe(4_200);
    expect(Rate::ofPercent('19.25')->basisPoints())->toBe(19_250);
    expect(Rate::ofPercent('1')->basisPoints())->toBe(1_000);
    expect(Rate::ofPercent('0.5')->basisPoints())->toBe(500);
});

it('rejects a percentage with more than two decimal places', function () {
    Rate::ofPercent('4.205');
})->throws(RateException::class, 'two decimal places');

it('rejects a negative rate', function () {
    Rate::ofBasisPoints(-1);
})->throws(RateException::class, 'negative');

it('applies to money with half-up rounding, once', function () {
    // CNPS PVID employee share: 4.2% of 750,000 = 31,500 exactly.
    expect(Rate::ofPercent('4.2')->applyTo(Money::of(750_000))->amount())->toBe(31_500);
});

it('rounds half up', function () {
    // 1% of 1,050 = 10.5 -> 11
    expect(Rate::ofPercent('1')->applyTo(Money::of(1_050))->amount())->toBe(11);
    // 1% of 1,049 = 10.49 -> 10
    expect(Rate::ofPercent('1')->applyTo(Money::of(1_049))->amount())->toBe(10);
});

it('rounds half up away from zero for negative amounts', function () {
    expect(Rate::ofPercent('1')->applyTo(Money::of(-1_050))->amount())->toBe(-11);
});

it('renders back to a percentage string', function () {
    // Scale is per 100 000, so 1 000 bp = 1%, not 10%.
    expect(Rate::ofBasisPoints(19_250)->toPercentString())->toBe('19.25');
    expect(Rate::ofBasisPoints(4_200)->toPercentString())->toBe('4.20');
    expect(Rate::ofBasisPoints(1_000)->toPercentString())->toBe('1.00');
    expect(Rate::ofBasisPoints(100_000)->toPercentString())->toBe('100.00');
});

it('is immutable and comparable', function () {
    $a = Rate::ofPercent('4.2');

    expect($a->equals(Rate::ofBasisPoints(4_200)))->toBeTrue();
    expect($a->basisPoints())->toBe(4_200);
});

it('never returns a float from applyTo', function () {
    $result = Rate::ofPercent('19.25')->applyTo(Money::of(350_000));

    expect($result)->toBeInstanceOf(Money::class);
    expect($result->amount())->toBeInt();
});
```

**Note on the scale:** basis points here are **per 100 000**, so 1% = 1 000 bp and 4.2% = 4 200 bp. This gives two decimal places of percentage precision, which is what the Cameroonian statutory tables need (19.25%, 5.5%, 3.70%).

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=RateTest
```

Expected: FAIL — `Class "App\Support\Rate\Rate" not found`.

- [ ] **Step 3: Write the exception class**

Create `app/Support/Rate/RateException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Rate;

use RuntimeException;

final class RateException extends RuntimeException
{
    public static function negative(): self
    {
        return new self('A Rate cannot be negative.');
    }

    public static function tooPrecise(string $percent): self
    {
        return new self(
            "Rate percentage \"{$percent}\" has more than two decimal places; "
            .'basis points carry exactly two.',
        );
    }

    public static function malformed(string $percent): self
    {
        return new self("Rate percentage \"{$percent}\" is not a valid decimal number.");
    }
}
```

- [ ] **Step 4: Write the `Rate` implementation**

Create `app/Support/Rate/Rate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Rate;

use App\Support\Money\Money;
use JsonSerializable;
use Stringable;

/**
 * A percentage held as integer basis points, where 100 000 bp = 100%.
 *
 * Floats are banned here for the same reason they are banned for money: a
 * statutory rate must produce the same franc on every run, forever, so that
 * re-rendering a payslip from a snapshot reproduces exactly
 * (docs/specs/00-core.md §7.2, 05-hr-payroll.md §7).
 */
final readonly class Rate implements JsonSerializable, Stringable
{
    public const SCALE = 100_000;

    private function __construct(private int $basisPoints)
    {
    }

    public static function ofBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw RateException::negative();
        }

        return new self($basisPoints);
    }

    /**
     * Parse a decimal percentage string. Deliberately a string, not a float —
     * (int) round(4.2 * 1000) is exactly the drift this class exists to avoid.
     */
    public static function ofPercent(string $percent): self
    {
        $trimmed = trim($percent);

        if (preg_match('/^(\d+)(?:\.(\d{1,3}))?$/', $trimmed, $m) !== 1) {
            if (preg_match('/^\d+\.\d+$/', $trimmed) === 1) {
                throw RateException::tooPrecise($percent);
            }

            throw RateException::malformed($percent);
        }

        $whole = (int) $m[1];
        $fraction = $m[2] ?? '';

        if (strlen($fraction) > 2) {
            throw RateException::tooPrecise($percent);
        }

        $fraction = str_pad($fraction, 2, '0');

        return new self($whole * 1_000 + (int) $fraction * 10);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function basisPoints(): int
    {
        return $this->basisPoints;
    }

    /**
     * Apply to an amount, rounding half up away from zero, exactly once.
     *
     * 00-core §7.3: round once, at component level. Callers must not round
     * again downstream.
     */
    public function applyTo(Money $money): Money
    {
        $sign = $money->amount() < 0 ? -1 : 1;
        $absolute = abs($money->amount());

        $numerator = $absolute * $this->basisPoints;
        $rounded = intdiv($numerator + intdiv(self::SCALE, 2), self::SCALE);

        return Money::of($sign * $rounded);
    }

    public function equals(self $other): bool
    {
        return $this->basisPoints === $other->basisPoints;
    }

    public function isZero(): bool
    {
        return $this->basisPoints === 0;
    }

    /**
     * Render as a percentage to two decimal places.
     *
     * intdiv throughout: `/` would return a float for any rate not a multiple
     * of 10 bp, which is exactly the type this class exists to keep out.
     * Sub-0.01% precision is truncated, which is lossless for every rate
     * produced by ofPercent().
     */
    public function toPercentString(): string
    {
        $whole = intdiv($this->basisPoints, 1_000);
        $fraction = intdiv($this->basisPoints % 1_000, 10);

        return sprintf('%d.%02d', $whole, $fraction);
    }

    public function __toString(): string
    {
        return $this->toPercentString().'%';
    }

    public function jsonSerialize(): int
    {
        return $this->basisPoints;
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```powershell
php artisan test --filter=RateTest
```

Expected: PASS, 10 tests.

- [ ] **Step 6: Commit**

```powershell
git add app/Support/Rate tests/Unit/Support/RateTest.php
git commit -m "feat: add Rate value object as integer basis points"
```

---

## Task 9: The `Score` value object

`00-core` §7.4: `DECIMAL(6,3)` semantics, computed in decimal, rounded half-up to 2 dp **once** at the end of aggregation, with rank and band derived from the **rounded** value so the printed number always explains the rank.

**Files:**
- Create: `app/Support/Score/Score.php`
- Create: `app/Support/Score/ScoreException.php`
- Test: `tests/Unit/Support/ScoreTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/ScoreTest.php`:

```php
<?php

declare(strict_types=1);

use App\Support\Score\Score;
use App\Support\Score\ScoreException;

it('constructs from thousandths', function () {
    expect(Score::ofThousandths(13_200)->thousandths())->toBe(13_200);
});

it('constructs from a decimal string', function () {
    expect(Score::of('13.2')->thousandths())->toBe(13_200);
    expect(Score::of('13.200')->thousandths())->toBe(13_200);
    expect(Score::of('9')->thousandths())->toBe(9_000);
});

it('rejects more than three decimal places', function () {
    Score::of('13.2005');
})->throws(ScoreException::class, 'three decimal places');

it('rejects a negative score', function () {
    Score::of('-1');
})->throws(ScoreException::class);

it('adds and multiplies without float drift', function () {
    $a = Score::of('13.333');
    $b = Score::of('0.667');

    expect($a->plus($b)->toString())->toBe('14.000');
});

it('divides with half-up rounding at three decimal places', function () {
    // 40 / 3 = 13.3333...
    expect(Score::of('40')->dividedBy(3)->toString())->toBe('13.333');
    // 20 / 3 = 6.6666...
    expect(Score::of('20')->dividedBy(3)->toString())->toBe('6.667');
});

it('rounds to two decimal places half up, once, for display and ranking', function () {
    expect(Score::of('12.345')->roundedToDisplay()->toString())->toBe('12.350');
    expect(Score::of('12.344')->roundedToDisplay()->toString())->toBe('12.340');
    expect(Score::of('9.995')->roundedToDisplay()->toDisplayString())->toBe('10.00');
});

it('formats for display at two decimal places', function () {
    expect(Score::of('13.2')->toDisplayString())->toBe('13.20');
    expect(Score::of('7')->toDisplayString())->toBe('7.00');
});

it('compares on the rounded value so two students printing the same rank equally', function () {
    // 00-core §7.4: rank on the ROUNDED value. Both print 12.34; neither may
    // outrank the other on invisible decimals.
    $a = Score::of('12.3449');
    $b = Score::of('12.3441');

    expect($a->toDisplayString())->toBe($b->toDisplayString());
    expect($a->equalsForRanking($b))->toBeTrue();
});

it('computes a weighted average conserving precision', function () {
    // Mathematics 14/20 coefficient 4, English 12/20 coefficient 2.
    // (14*4 + 12*2) / 6 = 80/6 = 13.333
    $average = Score::weightedAverage([
        [Score::of('14'), 4],
        [Score::of('12'), 2],
    ]);

    expect($average->toString())->toBe('13.333');
});

it('returns null for a weighted average with no assessed subjects', function () {
    // 01-assessment C3: sum of coefficients = 0 must be NULL, never 0, or the
    // student is banded Fail and ranked last.
    expect(Score::weightedAverage([]))->toBeNull();
});

it('returns null when every coefficient is zero', function () {
    expect(Score::weightedAverage([[Score::of('14'), 0]]))->toBeNull();
});
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=ScoreTest
```

Expected: FAIL — `Class "App\Support\Score\Score" not found`.

- [ ] **Step 3: Write the exception class**

Create `app/Support/Score/ScoreException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Score;

use RuntimeException;

final class ScoreException extends RuntimeException
{
    public static function negative(): self
    {
        return new self('A Score cannot be negative.');
    }

    public static function tooPrecise(string $value): self
    {
        return new self(
            "Score \"{$value}\" has more than three decimal places; "
            .'scores are stored as DECIMAL(6,3).',
        );
    }

    public static function malformed(string $value): self
    {
        return new self("Score \"{$value}\" is not a valid decimal number.");
    }

    public static function divisionByZero(): self
    {
        return new self('Cannot divide a Score by zero.');
    }
}
```

- [ ] **Step 4: Write the `Score` implementation**

Create `app/Support/Score/Score.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Score;

use JsonSerializable;
use Stringable;

/**
 * A mark or average, held as integer thousandths — DECIMAL(6,3) in MySQL.
 *
 * Computed in integers throughout, rounded half up to two decimal places once
 * at the end of aggregation. Rank and grade band are then derived from the
 * ROUNDED value, so the number printed on the bulletin always explains the
 * rank beside it (docs/specs/00-core.md §7.4, 01-assessment.md §2).
 */
final readonly class Score implements JsonSerializable, Stringable
{
    public const SCALE = 1_000;

    private function __construct(private int $thousandths)
    {
    }

    public static function ofThousandths(int $thousandths): self
    {
        if ($thousandths < 0) {
            throw ScoreException::negative();
        }

        return new self($thousandths);
    }

    public static function of(string $value): self
    {
        $trimmed = trim($value);

        if (str_starts_with($trimmed, '-')) {
            throw ScoreException::negative();
        }

        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $trimmed, $m) !== 1) {
            throw ScoreException::malformed($value);
        }

        $fraction = $m[2] ?? '';

        if (strlen($fraction) > 3) {
            throw ScoreException::tooPrecise($value);
        }

        $fraction = str_pad($fraction, 3, '0');

        return new self((int) $m[1] * self::SCALE + (int) $fraction);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Coefficient-weighted average. Returns null when nothing is assessable,
     * which the caller must treat as "not assessed" and never as zero.
     *
     * @param  list<array{0: Score, 1: int}>  $weighted  [score, coefficient] pairs
     */
    public static function weightedAverage(array $weighted): ?self
    {
        $numerator = 0;
        $denominator = 0;

        foreach ($weighted as [$score, $coefficient]) {
            $numerator += $score->thousandths * $coefficient;
            $denominator += $coefficient;
        }

        if ($denominator === 0) {
            return null;
        }

        return new self(self::divideHalfUp($numerator, $denominator));
    }

    public function thousandths(): int
    {
        return $this->thousandths;
    }

    public function plus(self $other): self
    {
        return new self($this->thousandths + $other->thousandths);
    }

    public function times(int $factor): self
    {
        return new self($this->thousandths * $factor);
    }

    public function dividedBy(int $divisor): self
    {
        if ($divisor === 0) {
            throw ScoreException::divisionByZero();
        }

        return new self(self::divideHalfUp($this->thousandths, $divisor));
    }

    /**
     * Round half up to two decimal places. Apply exactly once, at the end of
     * aggregation — never in an intermediate step.
     */
    public function roundedToDisplay(): self
    {
        $rounded = self::divideHalfUp($this->thousandths, 10) * 10;

        return new self($rounded);
    }

    public function equals(self $other): bool
    {
        return $this->thousandths === $other->thousandths;
    }

    /** Compare on the rounded value, per 00-core §7.4. */
    public function equalsForRanking(self $other): bool
    {
        return $this->roundedToDisplay()->thousandths === $other->roundedToDisplay()->thousandths;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->thousandths > $other->thousandths;
    }

    public function isGreaterThanForRanking(self $other): bool
    {
        return $this->roundedToDisplay()->thousandths > $other->roundedToDisplay()->thousandths;
    }

    public function isLessThan(self $other): bool
    {
        return $this->thousandths < $other->thousandths;
    }

    /** Full stored precision, e.g. "13.333". */
    public function toString(): string
    {
        return sprintf(
            '%d.%03d',
            intdiv($this->thousandths, self::SCALE),
            $this->thousandths % self::SCALE,
        );
    }

    /** Two decimal places, as printed on a bulletin, e.g. "13.33". */
    public function toDisplayString(): string
    {
        $rounded = self::divideHalfUp($this->thousandths, 10);

        return sprintf('%d.%02d', intdiv($rounded, 100), $rounded % 100);
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): string
    {
        return $this->toString();
    }

    private static function divideHalfUp(int $numerator, int $divisor): int
    {
        return intdiv($numerator + intdiv($divisor, 2), $divisor);
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

```powershell
php artisan test --filter=ScoreTest
```

Expected: PASS, 12 tests.

- [ ] **Step 6: Commit**

```powershell
git add app/Support/Score tests/Unit/Support/ScoreTest.php
git commit -m "feat: add Score value object with null weighted average for zero coefficients"
```

---

## Task 10: `BusinessDate` — one source of "what day is it"

`00-core` §7.5. Cameroon is UTC+1 with no DST, so between 00:00 and 01:00 local the UTC date is *yesterday*. Attendance registers and cash-desk day-close both hinge on this, and getting it wrong misfiles a day's collections.

**Files:**
- Create: `app/Support/Clock/BusinessDate.php`
- Test: `tests/Unit/Support/BusinessDateTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/BusinessDateTest.php`:

```php
<?php

use App\Support\Clock\BusinessDate;
use Illuminate\Support\Carbon;

afterEach(function () {
    Carbon::setTestNow();
});

it('returns the Douala date, not the UTC date', function () {
    // 00:30 on 8 August in Douala is still 23:30 on 7 August in UTC.
    Carbon::setTestNow(Carbon::parse('2026-08-07 23:30:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-08-08');
});

it('agrees with UTC during the rest of the day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 14:00:00', 'UTC'));

    expect(BusinessDate::today())->toBe('2026-08-07');
});

it('converts an arbitrary instant to a business date', function () {
    $instant = Carbon::parse('2026-12-31 23:45:00', 'UTC');

    expect(BusinessDate::from($instant))->toBe('2027-01-01');
});

it('exposes the timezone it uses', function () {
    expect(BusinessDate::timezone())->toBe('Africa/Douala');
});

it('returns a Carbon start-of-day in the business timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-07 23:30:00', 'UTC'));

    $start = BusinessDate::startOfToday();

    expect($start->format('Y-m-d H:i:s'))->toBe('2026-08-08 00:00:00');
    expect($start->timezone->getName())->toBe('Africa/Douala');
});
```

- [ ] **Step 2: Run it to verify it fails**

```powershell
php artisan test --filter=BusinessDateTest
```

Expected: FAIL — `Class "App\Support\Clock\BusinessDate" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/Clock/BusinessDate.php`:

```php
<?php

declare(strict_types=1);

namespace App\Support\Clock;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The single source of "what day is it" for attendance registers, cash-desk
 * close and daily collections.
 *
 * Cameroon is UTC+1 with no DST. Between 00:00 and 01:00 local, now()->toDateString()
 * in UTC returns YESTERDAY — which would file a payment taken at 00:30 into the
 * previous day's cash book (docs/specs/00-core.md §7.5).
 */
final class BusinessDate
{
    public const TIMEZONE = 'Africa/Douala';

    public static function timezone(): string
    {
        return self::TIMEZONE;
    }

    /** Y-m-d in the business timezone. */
    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public static function from(DateTimeInterface $instant): string
    {
        return Carbon::instance($instant)->setTimezone(self::TIMEZONE)->toDateString();
    }

    public static function startOfToday(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    public static function endOfToday(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->endOfDay();
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```powershell
php artisan test --filter=BusinessDateTest
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```powershell
git add app/Support/Clock tests/Unit/Support/BusinessDateTest.php
git commit -m "feat: add BusinessDate helper for Africa/Douala business dates"
```

---

## Task 11: Architecture tests — make the rules machine-checked

`00-core` §6.2 lists eight enforced rules. Three are checkable now; the rest need code that does not exist until later phases and are added in those plans.

**Files:**
- Create: `tests/Architecture/DomainPurityTest.php`
- Create: `tests/Architecture/ModuleBoundaryTest.php`
- Create: `tests/Architecture/NumericPolicyTest.php`

- [ ] **Step 1: Write the domain purity test**

Create `tests/Architecture/DomainPurityTest.php`:

```php
<?php

// 00-core §6.2 rule 1: Domain/ imports no Laravel and no Eloquent.

arch('domain layers are framework agnostic')
    ->expect('App\Modules')
    ->toUseNothing()
    ->ignoring([
        'App\Support',
        'App\Modules',
    ]);

arch('support value objects do not depend on Eloquent')
    ->expect('App\Support\Money')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades']);

arch('rate does not depend on Eloquent')
    ->expect('App\Support\Rate')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades']);

arch('score does not depend on Eloquent')
    ->expect('App\Support\Score')
    ->not->toUse(['Illuminate\Database', 'Illuminate\Support\Facades']);
```

- [ ] **Step 2: Write the module boundary test**

Create `tests/Architecture/ModuleBoundaryTest.php`:

```php
<?php

// 00-core §6.2 rule 2: cross-module access goes only through the owning
// module's Actions or published Events — never its Models.
//
// This test enumerates modules dynamically so it keeps working as modules
// gain code, and fails the moment one reaches into another's Models.

$modules = collect(scandir(app_path('Modules')))
    ->reject(fn (string $d) => in_array($d, ['.', '..', '.gitkeep'], true))
    ->values()
    ->all();

foreach ($modules as $module) {
    $others = array_values(array_filter($modules, fn (string $m) => $m !== $module));

    $forbidden = array_map(
        fn (string $other): string => "App\\Modules\\{$other}\\Models",
        $others,
    );

    if ($forbidden === []) {
        continue;
    }

    arch("{$module} does not reach into another module's Models")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($forbidden);
}
```

- [ ] **Step 3: Write the numeric policy test**

Create `tests/Architecture/NumericPolicyTest.php`:

```php
<?php

// 00-core §7.1: no float or decimal in money contexts, enforced rather than
// trusted. v1 mandated the rule in prose and contradicted itself four times.

arch('money value objects declare strict types')
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

it('has no float type hints in the money, rate or score value objects', function () {
    $files = [
        app_path('Support/Money/Money.php'),
        app_path('Support/Money/Allocator.php'),
        app_path('Support/Rate/Rate.php'),
        app_path('Support/Score/Score.php'),
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toMatch('/:\s*float\b/', "float return type in {$file}");
        expect($source)->not->toMatch('/\bfloat\s+\$/', "float parameter in {$file}");
        expect($source)->not->toMatch('/\(float\)/', "float cast in {$file}");
        expect($source)->not->toMatch('/\bround\s*\(/', "round() in {$file} — use integer division");
    }
});
```

- [ ] **Step 4: Run the architecture tests**

```powershell
php artisan test --testsuite=Architecture
```

If the suite is not registered, add to `phpunit.xml` inside `<testsuites>`:

```xml
<testsuite name="Architecture">
    <directory>tests/Architecture</directory>
</testsuite>
```

Then re-run.

Expected: PASS. The float sweep is the load-bearing one — it is what stops a well-meaning contributor reintroducing `round()` into payroll.

- [ ] **Step 5: Run the whole suite**

```powershell
composer check
```

Expected: PHPStan `[OK] No errors`, then all tests pass.

- [ ] **Step 6: Commit**

```powershell
git add tests/Architecture phpunit.xml
git commit -m "test: add architecture tests for domain purity, module boundaries and numeric policy"
```

---

## Task 12: CI with blocking gates

`00-core` §4 and `08-operations`: PHPStan level 8, `composer audit` and architecture tests all block the merge.

**Files:**
- Create: `.github/workflows/ci.yml`

- [ ] **Step 1: Write the workflow**

Create `.github/workflows/ci.yml`:

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest

    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4']
        mysql: ['8.0', '8.4']

    services:
      mysql:
        image: mysql:${{ matrix.mysql }}
        env:
          MYSQL_ALLOW_EMPTY_PASSWORD: 'yes'
          MYSQL_DATABASE: opeschool_test
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=5

    steps:
      - uses: actions/checkout@v4

      - name: Set up PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: pdo_mysql, mbstring, intl, bcmath, zip, gd, exif, fileinfo, openssl, curl, sodium
          coverage: none

      - name: Cache Composer packages
        uses: actions/cache@v4
        with:
          path: vendor
          key: composer-${{ matrix.php }}-${{ hashFiles('composer.lock') }}

      - name: Install dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Prepare environment
        run: |
          cp .env.example .env
          php artisan key:generate
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_DATABASE: opeschool_test
          DB_USERNAME: root
          DB_PASSWORD: ''

      - name: Preflight
        run: php artisan opes:preflight
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_DATABASE: opeschool_test
          DB_USERNAME: root
          DB_PASSWORD: ''

      - name: Static analysis (blocking)
        run: composer analyse

      - name: Dependency vulnerability audit (blocking)
        run: composer audit --no-dev

      - name: Tests (blocking)
        run: php artisan test
        env:
          DB_CONNECTION: mysql
          DB_HOST: 127.0.0.1
          DB_DATABASE: opeschool_test
          DB_USERNAME: root
          DB_PASSWORD: ''
```

The MySQL matrix covers 8.0 and 8.4 per `08-operations`. **MariaDB is deliberately absent** — the preflight step would fail it, which is the intended behaviour.

- [ ] **Step 2: Verify the workflow parses**

```powershell
php -r "echo 'yaml ext: ', extension_loaded('yaml') ? 'yes' : 'no', PHP_EOL;"
Get-Content .github\workflows\ci.yml | Select-Object -First 5
```

If the `yaml` extension is absent, visual inspection is sufficient here; GitHub validates on push.

- [ ] **Step 3: Run the same gates locally**

```powershell
composer analyse
composer audit --no-dev
php artisan test
```

Expected: all three succeed.

- [ ] **Step 4: Commit**

```powershell
git add .github/workflows/ci.yml
git commit -m "ci: add blocking gates for PHPStan level 8, composer audit and tests"
```

---

## Task 13: Document the foundation and tag it

**Files:**
- Create: `docs/DEVELOPMENT.md`
- Modify: `README.md`

- [ ] **Step 1: Write `docs/DEVELOPMENT.md`**

```markdown
# Development

## Toolchain — Laragon only

Do not use any other PHP or MySQL on this machine. Laragon also ships MariaDB,
which is explicitly unsupported (docs/specs/00-core.md §4).

| Tool | Path |
|---|---|
| PHP 8.3.30 | `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` |
| Composer | `C:\laragon\bin\composer\composer.bat` |
| MySQL 8.4.3 | `C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\` |

Set the session PATH before working:

```powershell
$env:PATH = "C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;C:\laragon\bin\composer;C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin;" + $env:PATH
```

Then verify: `php artisan opes:preflight`

## Numeric rules — non-negotiable

- **Money** is `BIGINT SIGNED`, whole FCFA, always through `App\Support\Money\Money`.
  Never `float`, never `DECIMAL`. Splitting an amount uses `allocate()`, which
  guarantees the parts sum to the whole.
- **Rates** are integer basis points through `App\Support\Rate\Rate`, where
  100 000 bp = 100%. Never a float — a statutory rate must reproduce exactly.
- **Scores** are integer thousandths through `App\Support\Score\Score`, rounded
  half up to two decimals **once**, at the end of aggregation. Rank and grade
  band derive from the rounded value.
- **Dates** for attendance and cash-desk close come from
  `App\Support\Clock\BusinessDate`, never `now()->toDateString()`.

The architecture tests enforce these. If one fails, the rule is right and the
code is wrong.

## Commands

| Command | Purpose |
|---|---|
| `php artisan opes:preflight` | Verify the machine can run the app |
| `composer test` | Run the suite |
| `composer analyse` | PHPStan level 8 |
| `composer check` | Both, in order |
```

- [ ] **Step 2: Replace `README.md`**

```markdown
# OPES SCHOOL

A school management platform for Cameroon. Laravel 12 + MySQL 8, API-first,
domain-driven, with a responsive Livewire frontend in the same codebase.

- **Specification suite:** [`docs/specs/README.md`](docs/specs/README.md)
- **Implementation plans:** [`docs/superpowers/plans/`](docs/superpowers/plans/)
- **Development setup:** [`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)

## Status

Phase 0A (foundation and kernel) — in progress.
```

- [ ] **Step 3: Run the full suite one last time**

```powershell
composer check
php artisan opes:preflight
```

Expected: PHPStan clean, all tests pass, preflight passes.

- [ ] **Step 4: Commit and tag**

```powershell
git add docs/DEVELOPMENT.md README.md
git commit -m "docs: add development guide and project README"
git tag -a phase-0a -m "Phase 0A: foundation and kernel complete"
```

---

## Definition of done

- [ ] `php artisan opes:preflight` passes on Laragon PHP 8.3.30 + MySQL 8.4.3
- [ ] Preflight **fails** if pointed at MariaDB (verify by temporarily setting `DB_PORT` to a MariaDB instance, then revert)
- [ ] `composer check` is green: PHPStan level 8 clean, all tests pass
- [ ] `Money::allocate()` conserves the total across the 60-combination sweep
- [ ] `Score::weightedAverage([])` returns `null`, not zero
- [ ] `Rate::ofPercent('4.2')->applyTo(Money::of(750_000))` is exactly `31_500`
- [ ] `BusinessDate::today()` returns the Douala date at 23:30 UTC
- [ ] Architecture tests reject a `float` type hint added to any value object
- [ ] 20 module directories exist and autoload
- [ ] CI workflow present with three blocking gates
- [ ] Tagged `phase-0a`

---

## Self-review notes

**Spec coverage.** This plan implements `00-core` §4 (fixed decisions — PHP, MySQL-not-MariaDB, database queue/cache, collations), §5 (naming is documentation-only at this stage; enforced when entities land in Phase 1), §6.2 rules 1, 2 and 8 (the others need code that does not yet exist — rules 3–7 are enforced in 0B when Actions appear), §7.1–7.5 (all four numeric policies), and the CI gates from §4 and `08-operations`.

**Deferred to later plans, deliberately:** auth, roles, audit, settings registry and i18n (0B); installer, TLS, backup, restore drill, health page, log rotation (0C); reference fixture and performance harness (0D). Each is named in the Scope table above rather than left implicit.

**Two bugs found and fixed during this review, both mine:**

1. `MoneyTest`'s float-rejection case would have **passed for the wrong reason** — actually it would have failed. `declare(strict_types=1)` governs the *call site*, not the class file, so without it in the test file PHP 8.3 coerces `1500.75` to `1500` with a deprecation notice rather than throwing `TypeError`. Added to `MoneyTest`, `RateTest` and `ScoreTest`. This is precisely the silent-money-loss the value object exists to prevent, so the test that guards it must be right.
2. `RateTest` asserted `ofBasisPoints(1_000)->toPercentString() === '10.00'`. At a scale of 100 000, 1 000 bp is **1%**, not 10%. Corrected, and a `100_000 → '100.00'` case added to pin the scale at both ends. Also changed `toPercentString()` from `/` to `intdiv()` — `/` returns a float for any rate not a multiple of 10 bp, which the numeric-policy test is meant to keep out of these files.

**Type consistency check:** `Money::of` / `Money::zero` / `Money::sum` / `allocate` / `split`; `Rate::ofBasisPoints` / `ofPercent` / `applyTo`; `Score::of` / `ofThousandths` / `weightedAverage` / `roundedToDisplay` / `toDisplayString`; `BusinessDate::today` / `from` / `startOfToday` — all used consistently across tasks and the definition of done.

**Known limitation.** `arch()->toUseNothing()` in `DomainPurityTest` is a coarse first approximation — it will need tightening to target only `Domain/` subdirectories once modules contain code. Plan 0B refines it to `expect('App\Modules\*\Domain')` once at least one module has a populated `Domain/`, which Pest's arch plugin cannot express against empty directories.
