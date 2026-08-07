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
| `php artisan opes:health` | Every health check, with what to do about anything wrong |
| `php artisan opes:audit:verify` | Walk the audit hash chain |
| `php artisan opes:backup:run` | Take a full backup now |
| `php artisan opes:backup:verify` | Re-check backup checksums, oldest first |
| `php artisan opes:backup:prune` | Apply GFS retention |
| `php artisan opes:backup:drill` | Restore the newest healthy backup and prove it works |
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

## Operations: backup, restore drill and health

**A backup is not a backup until the drill has restored it.** `mysqldump`
exiting zero proves the process ran, not that the file loads: charset
mismatches, `DEFINER` clauses and missing routines all produce clean-looking
dumps that fail on restore. `opes:backup:drill` restores the newest healthy
backup into a throwaway schema, asserts every table in the manifest exists,
compares row counts within tolerance, checks the audit anchor table survived,
and drops the schema again — including on failure, so a repeatedly failing
drill cannot fill the disk it exists to protect. Only a drill that has *passed*
counts; `RestoreDrillCheck` reports on passes, never on attempts.

**The commands.** `opes:backup:run` takes the dump, records a SHA-256 and
writes a manifest (schema version, per-table row counts, a ledger fingerprint).
`opes:backup:verify` re-hashes a **bounded** number of backups per run —
`opes.backup.verify_budget_per_run`, default 1, least-recently-verified first.
Unbounded verification on a nightly timer re-hashes a year of dumps and thrashes
the disk for hours; that was a real shipped bug in the reference
implementation. `opes:backup:prune` applies retention. `opes:health` prints
every check and exits non-zero if any is red.

**GFS retention** (`config/opes.php`): 7 daily, 4 weekly, 12 monthly, 10 yearly.
Corrupt copies are dropped before healthy ones of the same age. Above all of
that sits one invariant: **the last healthy backup is never pruned**, whatever
the retention numbers say. A policy that can delete your only good copy is worse
than no policy.

**`/up` is unauthenticated** so a monitor can poll it without holding a
credential — which makes it the endpoint where a leak costs most. It replaces
Laravel's stock health route (`health: '/up'` is deliberately absent from
`bootstrap/app.php`; two definitions would have the stock one win on
registration order, and the stock one answers "PHP is running" while the backups
have silently stopped). `HealthController` redacts every string it returns:
known roots become plain names like "the backup folder", and anything still
shaped like an absolute path is blanked. Checks must say "the backup folder",
never `C:\...`. `AppVersionCheck` also withholds the PHP and Laravel patch
versions — an open endpoint advertising `PHP 8.3.30` is a shopping list of
applicable CVEs — and `opes:health` prints them instead, since reaching that
already requires server access.

**Health checks answer four things**: key, status, plain-language detail, and
what the operator should do about it. A red light with no instruction is just
anxiety for a bursar who cannot read a stack trace (`08-operations` §7). Every
non-green result carries a remedy, and a test enforces it. `CollectHealth`
guards each check individually: the moment one explodes is exactly when the
operator needs to read the other ten.

**`mysqldump` and the drill both run on an isolated connection**
(`Support\IsolatedConnection`), and for two separate reasons. `mysqldump`
connects as its own client and sees only *committed* data, so a manifest
computed on the application's connection would describe uncommitted rows the
dump does not contain, and the drill would then report a row-count mismatch
that does not exist on disk. And `CREATE DATABASE` / `DROP DATABASE` carry an
**implicit commit** in MySQL, so running the drill's scratch-schema DDL on the
application's connection would silently commit whatever transaction the caller
had open. Both are fixed by a second PDO, hence a second MySQL session.

**The schedule** (`routes/console.php`) runs the backup at 01:00, verification
at 03:00, pruning at 03:30 and the drill monthly on the 1st at 04:00, plus a
five-minute queue heartbeat. The heartbeat is not decoration: without it a dead
scheduler is invisible — nothing errors, nothing is logged, and the nightly
backup simply stops happening. `QueueHeartbeatCheck` is what makes that silence
visible.
