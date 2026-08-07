# Development

## Toolchain — Laragon only

Do not use any other PHP or MySQL on this machine. Laragon also ships MariaDB,
which is explicitly unsupported (`docs/specs/00-core.md` §4) because the
required `utf8mb4_0900_*` collations are MySQL-exclusive.

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
  100 000 bp = 100%. Parsed from strings, never floats — a statutory rate must
  reproduce exactly on every run, forever.
- **Scores** are integer thousandths through `App\Support\Score\Score`, rounded
  half up to two decimals **once**, at the end of aggregation. Rank and grade
  band derive from the rounded value, so the printed number always explains the
  rank beside it. `weightedAverage()` returns `null` when no subject is
  assessable — never zero.
- **Dates** for attendance and cash-desk close come from
  `App\Support\Clock\BusinessDate`, never `now()->toDateString()`. Cameroon is
  UTC+1, so between 00:00 and 01:00 local the UTC date is yesterday.

The architecture tests enforce these. If one fails, the rule is right and the
code is wrong.

## Commands

| Command | Purpose |
|---|---|
| `php artisan opes:preflight` | Verify the machine can run the app |
| `composer test` | Run the suite |
| `composer analyse` | PHPStan level 8 |
| `composer check` | Both, in order |

## Rules of the road

- PHPStan runs at level 8 with **zero suppressions**. If it reports an error,
  fix the code — do not add an `ignoreErrors` entry. A suppression outlives the
  thing it was added for and quietly weakens the gate.
- Tests run against **real MySQL 8**, never SQLite. SQLite would let collation
  and strict-mode defects through, which an accounting system cannot afford.
- Every module lives under `app/Modules/`. Cross-module access goes through the
  owning module's Actions or published Events, never its Models.

## One documented PHPStan carve-out

`phpstan.neon` excludes `tests/Architecture` from analysis. That directory is
Pest's architecture DSL — `arch()->expect(...)->not->toUse(...)` — which is a
chain of magic `__call` methods. Level 8 enables `reportMagicMethods`, so every
link in the chain reads as an undefined method. The alternative, setting
`reportMagicMethods: false`, would disable the check for the whole application.
This is a path scope, not an `ignoreErrors` entry and not a baseline; the rule
above stands unchanged.

## Identity, audit and settings

- **Roles and permissions are enums**, not strings: `App\Modules\Identity\Domain\Role`
  and `Permission`. A typo is an analysis error, not a silent access denial.
  Later phases ADD permission cases; they must never rename existing ones,
  because seeds and granted permissions reference the values.
- **Audit rows have exactly one writer**, `WriteAuditEntry`. It serialises on
  the tail of the chain under a row lock — two concurrent writers reading the
  same predecessor would fork the chain and make verification meaningless. An
  architecture test enforces the single-writer rule.
- **The audit log is hash-chained AND anchored.** A genesis-only chain cannot
  detect truncation: delete the newest rows and the remainder still verifies.
  `AuditChainAnchor` records the expected head, so tail deletion is evident.
  `opes:audit:verify` runs nightly and detects tampering, mid-chain deletion
  and truncation. If it fails, the table was modified outside the application.
- **Attribution crosses module boundaries as `Support\Audit\Actor`**, never as
  an `Identity\Models\User`. Passing the model would force every module to
  import another module's Model, which §6.2 forbids while §14 requires the
  attribution.
- **Never log a credential.** Passwords and recovery codes must not appear in
  `before`/`after`. Tests assert this explicitly.
- **Settings have three classes.** Engine-behaviour settings are lockable:
  once a period using them is published, they cannot change, because doing so
  would retroactively alter numbers already printed and handed to parents.
- **Password reset is admin-driven.** Most Cameroonian schools have no SMTP
  server, so no admin recovery path may depend on email.
- **Permission labels are looked up as a group, not by dotted key.** Permission
  values contain a dot (`user.view`), which the translator would otherwise read
  as nested-array segments; `Permission::label()` fetches `opes.permissions`
  and indexes the flat key directly.
