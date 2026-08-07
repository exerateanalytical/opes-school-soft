# 08 — Operations

**Version:** 2.0
**Date:** 2026-08-07
**Status:** Draft for review
**Binding parent:** `00-core.md`. Where this document and 00-core disagree, 00-core wins.

> The v1 audit verdict on this material was: *a domain model had been mistaken for a system.* v1 had
> no installer, no migration-safety rule, one sentence of backup design, zero words on licensing,
> zero words on academic-year rollover, no data volumes, one index, and no upgrade test. This
> document is the system half.

**This document owns:** installation and deployment mechanics · the update and migration-safety
contract · backup, restore and disaster recovery · licensing and entitlement · data import and
onboarding · academic-year rollover and the dual-calendar runbook · observability and support ·
performance budgets and the index appendix · privacy, retention and erasure · the test strategy ·
the remaining non-functional requirements (SMS, WhatsApp, printers, barcodes, accessibility,
browsers, maintenance, help).

**This document does not own:** screens' visual design (`09-ui`), document layouts (`10-documents`),
or any domain rule (`01`–`07`). Where an operational mechanism has a UI surface, the *contract* is
here and the *layout* is in `09-ui`.

**Reading rule for implementers.** Every subsection below states a mechanism, a schedule, a
threshold and an acceptance criterion. If you cannot point at the number, the section is not
implemented. Phrases like "automated backups with integrity verification" were the entire v1 backup
design; they are banned from this document and from its acceptance criteria.

---

## 1. Deployment

### 1.1 Topology recap

00-core §3 fixes the topology: **LAN-default, VPS-option, both designed for from Phase 0**, no core
function requiring the internet. This section specifies the mechanics.

### 1.2 The installer decision

**Decision: a native Windows installer (Inno Setup) wrapping a bundled portable stack, with a
Docker Compose bundle as the supported Linux/VPS path.** Both produce the same application; they
differ only in how the runtime is delivered.

| | **Windows LAN bundle (primary)** | **Docker Compose bundle (VPS + Linux LAN)** |
|---|---|---|
| Artifact | `OpesSchool-Setup-<version>.exe`, single file, ~350 MB | `opes-school-<version>.tar` (images) + `compose.yml` + `.env.example` |
| Contains | PHP 8.3 (NTS + Windows service runner), MySQL 8.0.x, Caddy, the application with `vendor/` and built assets, the queue-worker service, the scheduler service, `mkcert`-style local CA tooling | `opes/app` (PHP-FPM + app), `opes/web` (Caddy), `mysql:8.0`, `opes/queue`, `opes/scheduler` |
| Installs | Four Windows services: `OpesMySQL`, `OpesWeb`, `OpesQueue`, `OpesScheduler` | Four containers with `restart: unless-stopped` |
| Operator steps | Next → accept → choose data folder → set proprietor password → Finish → browser opens the wizard | `docker compose up -d` (vendor-run, not school-run) |

**Why not "install PHP, MySQL, composer, npm" (v1's implicit path):** v1's §3.2 setup wizard began
*after* someone had installed a runtime, a database, run Composer, built assets and configured a web
server. No Cameroonian school bursar can do that, and no vendor can afford to do it by hand fifty
times. **Why not Docker as the primary Windows path:** Docker Desktop requires WSL2 or Hyper-V,
licences commercially above a threshold, and fails on the Windows Home installs that mini-PCs ship
with. **Why Inno Setup over MSI:** silent-install support, unattended service registration,
per-machine install without a domain, and a single self-contained `.exe` that copies to a USB stick.

**Acceptance criterion (blocking, Phase 0):** an operator who has never seen a command line, given a
USB stick and a clean Windows 11 machine, reaches **setup-wizard step 1 in ≤ 15 minutes**, measured
end-to-end, with zero command-line interaction and zero downloads. Tested on a clean VM image on
every release; the timing is recorded in the release notes.

### 1.3 What the installer does, in order

1. Preflight: OS build, free disk, RAM, an existing install, a conflicting listener on 80/443/3306.
   Any failure aborts **before** writing anything.
2. Choose the **data folder** (default `D:\OpesSchoolData` if a second volume exists, else
   `C:\OpesSchoolData`). Database files, `storage/`, backups and logs live here, never under
   `Program Files`, so they survive an uninstall and are not touched by application updates.
3. Install the runtime and the application into `C:\Program Files\Opes School\<version>\`
   (version-pinned; see §2.6 rollback).
4. Generate `APP_KEY`, the backup encryption key, and the instance UUID. Write `.env` with
   `0600`-equivalent ACLs (SYSTEM + Administrators only).
5. Initialise MySQL: create the data directory, the `opes` schema, a dedicated `opes_app` user with
   no `SUPER`, `FILE` or `PROCESS`, and a separate `opes_backup` user with `SELECT, LOCK TABLES,
   RELOAD, REPLICATION CLIENT, SHOW VIEW, EVENT, TRIGGER` used only by the backup job.
6. Write the shipped `my.ini` (§1.6) and start `OpesMySQL`.
7. Run `php artisan migrate --force` and seed reference data (**empty where 00-core §16 gates it**).
8. Generate the local CA and the server certificate (§1.5); install the CA into the machine trust
   store on this PC.
9. Register and start the four services; register the Windows Firewall rules for 443 (private
   profile only, never public).
10. Run `php artisan opes:preflight`; show the pass/fail table.
11. Open `https://<hostname>.local/setup` in the default browser.

**Uninstall never deletes the data folder** and says so on its final screen, with the folder path.

### 1.4 Server requirements and the PHP extension manifest

**Reference hardware (00-core §3):** 4-core CPU, 8 GB RAM, SSD, **UPS required**. The UPS is a
correctness requirement, not a convenience: see §1.6.

**Minimum:** 2 cores / 4 GB / 60 GB SSD, up to 400 students, single cash desk.
**Recommended (the reference fixture, 1,200 students):** 4 cores / 8 GB / 240 GB SSD.
**Disk-headroom rule:** the installer refuses to install with < 40 GB free; the health page turns
**amber at 20 % free or 15 GB, red at 10 % or 5 GB**, whichever is hit first. Below the red
threshold the scheduler stops backup *pruning* but never stops backup *creation*, and the
application logs at `warning` only.

**Sizing basis** (from §8 volumes, 1,200 students, 7 years retained): database ≈ 9 GB, uploaded
files ≈ 25 GB (photos + scanned documents at the §10.3 caps), generated PDFs ≈ 12 GB, backups under
the §3.3 GFS schedule ≈ 4× the database ≈ 36 GB. **Budget 120 GB at 7 years**; 240 GB is the
recommended disk because a school will not resize it.

**PHP extensions (all mandatory, asserted by preflight):** `intl`, `mbstring`, `pdo_mysql`,
`mysqlnd`, `bcmath`, `zip`, `gd` **or** `imagick` (one required; `imagick` preferred where the PDF
engine needs it), `exif`, `fileinfo`, `openssl`, `curl`, `sodium`, `zlib`, `pcntl` (Linux only),
`opcache`. Preflight also asserts `opcache.enable=1`, `opcache.validate_timestamps=0` in production,
`memory_limit >= 512M`, `max_execution_time >= 120`, `upload_max_filesize >= 8M`,
`post_max_size >= 16M`, and `date.timezone` unset or `Africa/Douala`.

### 1.5 TLS on the LAN — a decision, not an omission

**Decision: the installer generates a per-school local CA and issues a server certificate for the
`.local` hostname. HTTP is refused for anything but the ACME/redirect listener.**

Sanctum session cookies traversing a school's shared Wi-Fi in cleartext are harvestable by any
student with a phone; the bursar's session is the one that collects money. The alternative — "the
LAN is trusted, HTTP is fine" — is explicitly **rejected**, and this sentence is the record of that
rejection.

Mechanics:

- The installer creates a CA keypair (P-256) with a **10-year** validity, `CN = <School Name> OPES
  Local CA`, and stores the private key encrypted under a key derived from the machine DPAPI store,
  readable only by SYSTEM. The CA private key never leaves the server and is **excluded from
  backups** (a re-install regenerates it; only client trust must be re-established).
- The server certificate is issued for `opes.local`, `<hostname>.local`, and the server's LAN IP,
  with a **398-day** validity, auto-renewed by the scheduler at 30 days remaining and hot-reloaded
  into Caddy.
- **Client trust is one click.** Browsing to `http://<ip>/trust` on any device on the LAN serves a
  short page with the CA `.crt`, a QR code, and three-step instructions in EN/FR for Windows,
  Android, iOS and ChromeOS. That page is the *only* thing served over plain HTTP besides the 301.
- Cookies: `Secure`, `HttpOnly`, `SameSite=Lax`, `__Host-` prefix where the path allows.
- VPS mode uses Caddy's automatic Let's Encrypt issuance instead; everything else is identical.

**Acceptance criterion:** on a clean Android phone joined to the school Wi-Fi, a non-technical
operator establishes trust and reaches the login page in ≤ 3 minutes following only the `/trust`
page.

### 1.6 MySQL configuration and power durability

00-core §3 makes these durability settings a **correctness requirement**. The shipped `my.ini`:

| Setting | Value | Why |
|---|---|---|
| `innodb_flush_log_at_trx_commit` | `1` | A commit that returns must survive an immediate power cut. Cameroon's grid makes this non-negotiable. |
| `sync_binlog` | `1` | Binlog must not lag the redo log; §3 RPO depends on the binlog being complete. |
| `log_bin` | `ON`, `binlog_expire_logs_seconds = 1209600` (14 d) | Point-in-time recovery (§3.2). |
| `binlog_row_image` | `FULL` | Reconstructable PITR; the volume cost at school scale is trivial. |
| `innodb_buffer_pool_size` | 40 % of RAM (3 GB on an 8 GB box) | Leaves room for PHP, the queue workers and the PDF engine. |
| `innodb_log_file_size` | `512M` | Report-card batches and payroll runs write in bursts. |
| `max_connections` | `150` | 20 concurrent users × Livewire round-trips + 2 queue workers + scheduler. |
| `character_set_server` / `collation_server` | `utf8mb4` / `utf8mb4_0900_ai_ci` | 00-core §4. |
| `sql_mode` | `STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` | A silently truncated mark or amount is a data-integrity defect. |
| `transaction_isolation` | `READ-COMMITTED` | Matches the `FOR UPDATE` strategy in 00-core §11 and avoids gap-lock surprises on the sequence tables. |
| `innodb_lock_wait_timeout` | `10` | A cashier waiting on a stuck lock must get an error, not a hung browser. |
| `default_time_zone` | `+00:00` | 00-core §7.5 stores UTC. |

`artisan opes:preflight` **asserts every row of this table against the live server** and fails,
red, naming the setting and the file to edit, if any differs. A school that "tuned" MySQL by
following a blog post is a data-loss incident waiting to happen.

**Receipt rule (restated from 00-core because operations owns the enforcement):** the receipt PDF
is rendered and the printer dispatched **only after the database commit returns**. Never
optimistically, never inside the transaction. Enforced by an architecture test asserting no document
renderer is invoked from inside `DB::transaction()`.

### 1.7 Queue worker supervision, watchdog, heartbeat

- **Driver:** database (00-core §4). Two workers by default (`--queue=high,default,low
  --max-jobs=500 --max-time=3600 --tries=3 --backoff=10,60,300`), restarted by the service manager
  on exit. `--max-jobs`/`--max-time` bound PHP memory drift.
- **Heartbeat:** each worker writes `ops.queue.heartbeat.<worker_id>` (timestamp, pid, jobs
  processed) to the settings/kv store every 30 s. The health page reads it; **amber at 120 s
  stale, red at 300 s**.
- **Watchdog:** the scheduler (running every minute) checks the heartbeat. If red for two
  consecutive minutes it restarts the worker service once, logs it, and raises a health alert. It
  never restarts more than **3 times per hour** — a crash-looping worker must be visible, not
  hidden.
- **Deploy safety:** `queue:restart` is issued after every update; workers pick up new code at their
  next job boundary.
- **Failed jobs** are retained 30 days with the full payload (PII-scrubbed per §7.2), surfaced on
  the health page with count, class name, exception head, and a Retry / Retry-all / Delete action
  gated on the Administrator role.
- **Long jobs** (report-card batches, imports, rollover) run on the `low` queue with the concurrency
  cap in §8.4 so a batch never starves the interactive queue.

---

## 2. Updates and migration safety

### 2.1 Versioning and visibility

SemVer `MAJOR.MINOR.PATCH`. **MAJOR** = a migration that is not reversible or an operator-visible
breaking change; **MINOR** = features; **PATCH** = fixes. A maintained `CHANGELOG.md` in EN and FR,
rendered inside the app at `/admin/updates`, grouped by module, with a **"what you must do"** block
per release (e.g. "confirm your CNPS regime after this update").

The running version appears in the UI footer, on the About screen, in `/up`, in the support bundle
(§7.3) and in the check-in payload (§4.4).

### 2.2 Update sources

| Source | Use | Mechanism |
|---|---|---|
| **USB folder** (default for LAN) | Schools with no internet | Operator points the admin UI at a folder containing `opes-update-<version>.zip` + `.sig` |
| **HTTPS feed** (opt-in) | Connected LAN and all VPS | Signed manifest at the vendor endpoint, polled daily by the scheduler; **download only, never apply** |

Both artifacts are **signed with the offline licence-file key (ECDsa P-256 / SHA-256, the same key
custody model as §4.1)** and refused on signature failure with no partial extraction. The manifest
carries `version`, `min_upgradable_from`, `sha256`, `size`, `requires_php`, `migration_count`,
`breaking: bool`, and the CHANGELOG excerpt.

**Updates are never auto-applied.** The admin sees "Version X is available", the changelog, the
pending-migration count, and must confirm. This is a product commitment: a school's only server is
not a place for surprise restarts.

### 2.3 The migration-safety mandate

**This is a critical regression risk carried over from the reference implementation.** The .NET
reference ran migrations on SQLite, where **DDL is transactional** — a failed migration rolls back
whole. On **MySQL 8, DDL implicitly commits**. A migration file with three `ALTER` statements that
fails on the third leaves two applied, records nothing in `migrations`, and re-running dies with
"column already exists". The instance is stuck half-migrated on a school's only copy of five years
of ledger data, at 19:00, offline, 300 km from the vendor.

Six binding rules:

1. **One DDL statement per migration file.** No exceptions. Enforced by a Pest architecture test
   that parses each migration's `up()` and counts schema-affecting statements. A file that adds two
   columns is two files. This makes "which statement failed" answerable from the `migrations` table
   alone.
2. **Automatic verified pre-migration backup that refuses to migrate if it fails.** The update
   Action runs §3 `CreateBackup` with **full verification** (restore-parse, checksum sidecar) and
   aborts the whole update if verification does not return healthy. There is no override flag.
3. **Expand/contract only.** A release may add a column, backfill it, and start writing it. A
   *different, later* release drops the old one. **No destructive `ALTER` may ship in the same
   release as the code that stops using the column.** Renames are expand/contract, never `CHANGE
   COLUMN`. Enforced by review checklist plus a CI check that flags `dropColumn`, `dropIfExists`,
   `renameColumn` and `dropForeign` for explicit sign-off with the release that deprecated the usage
   named in the migration docblock.
4. **No data mutation inside migrations.** Backfills are separate, **idempotent, resumable, chunked
   queued jobs** with a `BackfillRun` record (name, chunk size, last processed id, status,
   started_at, completed_at) and a health-page row while incomplete. A 1.2M-row mark backfill inside
   a migration is a 40-minute lock and a guaranteed timeout.
5. **`migrate --pretend` surfaced in the admin UI** as "N pending changes" with the statement list,
   shown before the operator confirms.
6. **Post-migration integrity check with automatic rollback-to-backup.** After migrating, the update
   Action runs the §2.5 check set. Any failure triggers an automatic restore of the pre-migration
   backup, and the operator is shown "The update was undone. Your data is as it was at
   HH:MM. Send the support bundle to your vendor." Enforced with the operator's confirmation only
   for the *retry*, never for the *rollback*.

### 2.4 Update sequence

```
1  Verify signature and sha256 of the artifact
2  Check min_upgradable_from against the installed version   → refuse with the required intermediate
3  Preflight (PHP version, extensions, disk headroom ≥ 3× DB size)
4  CREATE + VERIFY pre-migration backup                      → abort on any failure
5  Enter maintenance mode (php artisan down --render=...)     — bilingual page, retry-after
6  Stop queue workers (drain up to 60 s, then terminate)
7  Extract to  <install>\<new-version>\  (never over the running tree)
8  Swap the "current" junction/symlink to the new version
9  php artisan migrate --force --step                         — --step so a failure names one file
10 Post-migration integrity check (§2.5)
11 php artisan config:cache route:cache view:cache event:cache
12 Start queue workers; php artisan queue:restart
13 Leave maintenance mode
14 Enqueue any backfill jobs
15 Write the UpdateRun record and the audit entry
```

`UpdateRun`: `from_version`, `to_version`, `artifact_sha256`, `backup_id`, `started_at`,
`completed_at`, `status (running|succeeded|rolled_back|failed)`, `failed_migration`, `operator_id`,
`log_excerpt`.

### 2.5 Post-migration integrity check

Run inside step 10, all six, all blocking:

1. `migrate:status` reports zero pending.
2. **Row-count parity** on the protected tables (`marks`, `invoices`, `payments`,
   `journal_entries`, `journal_entry_lines`, `payroll_items`, `enrollments`, `audit_log`) against
   the counts captured before step 5. Equal, or greater only where the migration documents an
   insert.
3. **Every journal entry balances**: no `journal_entries` row where `Σdebit ≠ Σcredit` over its
   lines.
4. **No orphaned foreign keys**: a generated query walks `information_schema` and asserts zero
   orphans across every FK.
5. **Audit hash chain verifies** for the last 1,000 rows (00-core §14).
6. **Startup canary decrypts** (00-core §9.4) — a wrong `APP_KEY` after a restore must refuse to
   boot, not corrupt.

### 2.6 Rollback contract and RTO

**Stated contract, published to customers:**

| Scenario | Mechanism | Target |
|---|---|---|
| Migration fails at step 9 or the check fails at step 10 | Automatic restore of the step-4 backup, junction swapped back to the previous version tree | **Automatic, ≤ 20 minutes** on the reference hardware with a 9 GB database |
| Application defect discovered after the update, no schema change since | Operator selects "Return to version X" in the admin UI; junction swap + `queue:restart`; no data touched | **≤ 5 minutes**, self-service |
| Application defect discovered after the update, schema changed | Restore the pre-migration backup — **data entered since the update is lost** and the UI says so in those words, with the exact timestamp, before confirming | **≤ 60 minutes**; the operator decides |
| Vendor-side bad release | The HTTPS feed can mark a version `yanked`; connected instances stop offering it and warn if it is installed | Next daily poll |

The previous two version trees are retained on disk; older ones are pruned. "Downgrade the schema"
is **not offered** — reverse migrations on a live financial database are a bigger risk than the
restore.

---

## 3. Backup and disaster recovery

v1 gave this one sentence. The .NET reference gives it a 413-line hardened service whose specific
lessons — page-copy not file-copy, integrity check **plus** a SHA-256 sidecar, health-first pruning
that never deletes the last verified-good backup, a **bounded** verification budget because an
unbounded sweep on a timer was a real shipped bug, path-traversal guards on the restore filename,
and three pre-restore safety copies — are ported below, translated from SQLite to MySQL.

### 3.1 Published objectives

| Objective | Value | Meaning |
|---|---|---|
| **RPO — financial data** | **≤ 1 hour** | At most one hour of collections can be lost. |
| **RPO — all other data** | ≤ 24 hours | The nightly full. |
| **RTO — with spare hardware on site** | **≤ 4 hours** | Restore, verify, resume. |
| **RTO — no spare hardware** | Best effort, stated as "depends on procurement" | Honesty; do not publish a number the vendor cannot hold. |

*Why the one-hour RPO is not negotiable.* Disk dies at 15:00; the last backup is 02:00. The school
has issued paper receipts all morning against a ledger that no longer exists. There is no
reconciliation path — you cannot know which of 180 parents paid, or how much, or against which
instalment. That is an unreconcilable accounting incident, and under OHADA it is also a books
failure. Both RPO and RTO are **published in the support contract**, not just in this document.

### 3.2 What runs when

| Job | Schedule | Artifact | Notes |
|---|---|---|---|
| **Binlog ship** | Every **15 minutes** | Flushed binlog segments copied to the backup targets | Gives PITR to the minute; the 1-hour RPO holds even if a 15-minute run is missed three times. |
| **Nightly full** | **01:30** local | `mysqldump --single-transaction --routines --triggers --events --hex-blob --set-gtid-purged=OFF --column-statistics=0`, gzipped, then encrypted | `--single-transaction` avoids locking; InnoDB-only schema makes it consistent. |
| **File-storage sync** | **02:15** local | Incremental copy of `storage/app/private` (student documents, photos, crest) + generated PDFs | §3.5. |
| **Verification** | **02:45** local | **Exactly one file** — the newest backup | §3.4. Bounded by design. |
| **Prune** | **03:00** local | GFS enforcement | §3.3, health-first. |
| **Restore drill** | **Monthly**, first Sunday **04:00** | Scratch schema | §3.6. The highest-value control in the product. |
| **Yearly archive** | Once, at fiscal close | Immutable archive copy | Part of the `YearEndChecklist` in `02-accounting`. |

All schedules are configurable; the **defaults are what ships**, and the health page reports any
deviation from the shipped schedule as informational so support can see it.

### 3.3 Retention — GFS

**7 daily · 4 weekly · 12 monthly · 10 yearly.** Weekly = the Sunday full; monthly = the 1st;
yearly = the fiscal-close archive.

The ten yearly archives exist because **AUDCIF Art. 24 requires 10-year retention** of accounting
records (`02-accounting` C5). A yearly archive is written **once**, at fiscal close, marked
immutable (read-only ACL + recorded checksum), listed on the year-end checklist, and **never
pruned by any automatic rule** — deleting one requires the Super Admin role, a typed confirmation,
and an audit entry.

**Health-first pruning (ported).** A backup is deleted only when **at least `keep_count`
verified-healthy backups newer than it remain**. The consequence is the property that matters: *the
last known-good backup can never be pruned*, even if every backup newer than it is damaged. Pruning
also deletes each file's checksum sidecar with it and sweeps sidecars whose backup is gone —
an orphan sidecar is at best clutter and at worst a checksum waiting to be matched against a
different file. Pruning never throws into the scheduled path; a file held open by an antivirus
scanner is skipped and logged, not escalated.

### 3.4 Integrity, verification and the bounded budget

Every backup carries **two independent proofs**:

1. **Structural soundness** — the dump is streamed through a parse/restore check into a scratch
   schema for the *newest* backup only, or at minimum `gunzip -t` + a decrypt round-trip for
   others. `mysqldump` completing with exit 0 does **not** mean the dump restores: charset
   mismatches, `DEFINER` clauses referencing a user that does not exist on the restore host, and
   missing routines all produce clean dumps that fail on restore. This is why §3.6 exists.
2. **A SHA-256 sidecar** written beside the file as `<backup>.sql.gz.enc.sha256` — one line, 64
   lowercase hex characters. A sidecar needs no schema, no index and no migration; it travels with
   the file when a school drags the folder onto a USB stick; and an administrator can read it.

Sidecar semantics, ported exactly:

| State | Meaning | Restore allowed? |
|---|---|---|
| `Matches` | Recomputed hash equals the recorded one | Yes |
| `Mismatch` | The file changed after it was written | **No** — refused with its own distinct message, *not* "corrupt" |
| `NotRecorded` | No sidecar, unreadable sidecar, or not 64 hex chars | **Yes** — a backup made before sidecars existed is still a good backup |

**The verification budget is bounded, and this is load-bearing.** The scheduled daily check opens
**exactly one file** — the newest, chosen from file metadata alone. Listing the backup folder opens
**zero** files. Pruning opens a bounded handful. Only an explicit "Verify Backups" click sweeps the
folder, and it runs off the request thread with progress. *An unbounded verification sweep on a
timer was a real shipped bug in the reference*; the counter that proves the bound is therefore part
of the implementation, not just the spec: the backup service exposes `files_verified_count` and Pest
asserts **"the daily check touches exactly one file"** and **"pruning opens ≤ keep_count + 3 files,
independent of folder size"**. A promise nothing measures is a promise that comes back.

Verification never throws. A backups folder is a plain folder on a school PC: an antivirus scanner
truncates files mid-copy, copies get interrupted, and staff drop unrelated files in it. Any of those
must render as "Damaged" in the list, never as a crash on the very screen that exists to deal with a
bad backup.

### 3.5 Targets, encryption and what is excluded

- **A second physical target is required.** Default configuration: local folder on the data volume
  **plus** a rotated USB target **plus** optional encrypted cloud (S3-compatible) when online.
- **The health page shows amber, never green, when the only copy is on the same volume as the
  database**, and names the problem in plain language: *"Your backups are on the same disk as your
  records. If that disk fails you lose both. Plug in a USB drive and set it as the second
  destination."* The backup location report distinguishes *configured* from *effective*: a
  configured folder that is not there right now (unplugged USB, downed share) silently falls back to
  the default folder — backups must never be lost to a bad setting — and the health page then
  honestly reports the default, **including that it is same-volume**.
- **Dumps are encrypted** (AES-256-GCM) under a dedicated backup key, distinct from `APP_KEY`. A
  dump on a USB stick contains every student's name, DOB, photo, guardian phone, discipline record,
  genotype and blood group. An unencrypted dump on a lost stick is a reportable breach involving
  minors' health data.
- **`.env` and `APP_KEY` are excluded from the routine backup set** and handled separately: they are
  on the recovery sheet in the proprietor's safe and escrowed with the vendor (00-core §9.4).
  Backing up the encryption key beside the ciphertext makes the encryption decorative against the
  threat it exists for. The **backup encryption key is likewise on the recovery sheet** — a school
  that loses it holds ciphertext.
- **The local CA private key is excluded** (§1.5).
- **File storage is in scope.** StudentDocument files, student and staff photos, the school crest,
  and generated snapshot-backed PDFs are all backed up. A database-only restore yields a working
  system with broken images and missing scanned birth certificates — the documents a school is
  legally asked to produce.

### 3.6 The automated restore drill

**This is the highest-value operational control in the product.** It converts a hope into a
measurement.

Monthly, first Sunday 04:00, automatically:

1. Create a scratch schema `opes_drill_<timestamp>`.
2. Decrypt and restore the newest healthy full backup into it.
3. Run `migrate --force` against it (proves the dump is migratable, which is what a real recovery
   would do).
4. **Assert:** row counts for the protected tables are within tolerance of the live counts as of the
   dump time; **the ledger balances** (`Σdebit = Σcredit` globally and per entry); the audit hash
   chain verifies over a sample; a checksum over `(account_id, Σdebit, Σcredit)` per account matches
   the value recorded in the backup manifest.
5. Drop the scratch schema.
6. Record a `RestoreDrill` row: `backup_id`, `started_at`, `duration_seconds`, `status`,
   `assertions_passed`, `failure_detail`.

The health page shows **"Last successful restore drill: 12 days ago ✓"** or, on failure, red with
the failing assertion. **Amber at 40 days, red at 60 days** since the last success.

**Acceptance criterion:** the drill completes in ≤ 45 minutes on the reference hardware with the
1,200-student fixture, and a deliberately corrupted dump is detected by the drill on the next run.

### 3.7 Restore — the human path

Restoring is the most destructive operation the product offers. It is gated accordingly:

- A dedicated permission (`backup.restore`), held by **Super Admin only** by default (00-core §9.1).
- The operator must **type the school's name exactly** to confirm. Not "yes", not a checkbox.
- The screen states, before confirmation: the backup's timestamp, its verification state, and
  **"Everything entered after <timestamp> will be lost"** with the count of payments, marks and
  journal entries that will be discarded, computed live.
- A **mandatory pre-restore snapshot** of the current database is taken first. **Three** pre-restore
  copies are kept (ported constant): a restore that turns out to be the wrong one is noticed within
  a session or two, and three copies of a school-sized database is a bounded amount of disk. The
  newest is never pruned, so the copy taken by the restore currently running always survives.
- **Path-traversal guard**: the requested filename is resolved to a full path and must start with
  the resolved backups root, and must exist. This is not theoretical — the filename arrives from a
  form.
- Integrity is checked **before** the destructive step; a `Mismatch` checksum refuses with its own
  message.
- **The restore audit entry is written to the log file outside the database**, structured, before
  and after — a database-resident audit entry is destroyed by the operation it records. It is also
  written into the restored database afterwards, so both halves of the story exist.
- Active user sessions are invalidated; the app enters maintenance mode for the duration.
- PITR: where binlogs are available the operator may choose **"restore to a point in time"**, which
  restores the full then replays binlogs to a chosen minute. This is the mechanism that delivers the
  1-hour RPO and it must be exercised in the drill at least quarterly.

### 3.8 The Backup & Restore screen — contract

Layout per `09-ui`; the data contract is here.

- **Last-backup card:** timestamp, age (green ≤ 26 h, amber ≤ 50 h, red beyond), size, destination,
  verification state, and the same-volume warning where it applies.
- **Create Backup Now** — runs the full job on the queue with progress; the button disables while
  running.
- **History table:** filename, created, size, destination, and a **state column with exactly four
  values**: `Verified` · `Damaged` · **`Changed since made`** (checksum mismatch — a distinct state,
  not "damaged") · `Not checked`.
- **Verify Backups** — the explicit full sweep, with progress and a per-file result.
- **Restore** — the §3.7 flow.
- **Automatic backup settings:** interval, retention (the GFS counts), destinations (add/remove,
  test-write button), encryption key status, and the **same-disk warning** rendered inline in the
  destination editor, not only on the health page.
- **Restore drill card:** last run, result, next scheduled.

---

## 4. Licensing

v1 had **zero words** on licensing, for a product sold to many schools. The reference's design is
ported; its *enforcement* is not, because it had none (00-core §17.1: `CanAddMoreStudents()` has
zero call sites — porting it as-is ports nothing).

### 4.1 Cryptographic design — two keys, deliberately split

| Key | Signs | Private half | Public half |
|---|---|---|---|
| **Licence-file key** — **ECDsa P-256 / SHA-256** | Offline licence files **and update artifacts** (§2.2) | Offline, with the vendor; never on any server | Embedded in the application |
| **Activation key** — **RSA-2048, PKCS#1 v1.5, SHA-256** | Activation-server API responses | The licence server's environment | Embedded in the application |

**A compromise of the internet-facing activation server cannot forge offline licence files, and vice
versa.** That split is the entire point and must not be collapsed into one key "for simplicity". No
private key of any kind lives in the application repository; tests generate a throwaway pair in
memory.

*(Not Ed25519 — 00-core §17.1 records that v1 repeated that from the reference's design document
rather than its code.)*

### 4.2 The two routes to a licensed state

| Route | Needs internet | Machine-bound |
|---|---|---|
| **Licence file** — a signed `.opeslic` file the school imports | **Never** | No |
| **Online activation** — key + machine fingerprint → signed, machine-bound licence | **Once**, at activation | Yes |

Both must keep working. **Activation requires the internet exactly once**; the signed licence is
cached and verified **offline on every status check, with no network call ever**. There is no
start-up ping, no heartbeat, and — this is the important one — **no grace period that can run out**.
A school with no internet must never be locked out for the crime of having no internet.

`next_check_after` and `grace_days` from the server are parsed and stored, and used for exactly one
thing: deciding whether an **opportunistic** re-check is worth attempting. Passing either date never
changes whether the licence is valid.

**Acceptance criterion (ported test):** a simulated 36 months of offline use produces **exactly one**
network call — the one made at activation.

### 4.3 Verification, fingerprint, and failure behaviour

Every status check, offline, in order: canonicalise the payload (keys sorted ordinal, compact, no
escaped slashes or unicode, integers and `null` unquoted) → **verify the signature** → parse →
assert the product slug → assert the fingerprint in constant time → assert `expires_at`. *The
network is how a licence arrives; the signature is what makes it trustworthy. There is no "but it
came from our server" exemption.*

**Machine fingerprint:** `SHA-256("opes-machine-fingerprint-v1|" + source)`, lowercase hex, where
source is the OS machine GUID, falling back to the system volume serial. It contains **no** school
name, user name, address, MAC address, or any student or staff data. If neither source is readable
the fingerprint is **empty, never random**, and no API call is made.

**Opportunistic re-check** (fired only when the operator opens the Licence panel, only when a
licence is cached, a server is configured, and `next_check_after` has passed): on `revoked` or
`invalid_key` it clears the local licence — the one case that matters. On **anything else** — no
internet, DNS failure, timeout, 5xx, captive portal, no seats — it changes nothing and the school
never learns it ran.

**Deactivation frees a seat.** The local clear is **unconditional** — a school moving to a new PC
must not be trapped by having no internet on the old one — but where the seat could not be released
the school is told plainly: *"the licence has been removed from this computer, but this computer
still counts against your licence; deactivate it in your vendor account."* Saying nothing is how a
three-seat school quietly runs out of seats.

Every failure mode has a **distinct localized EN and FR sentence**, with a test that fails the build
if two collapse onto the same text. The licence key is **never logged**, never placed in a URL, and
never echoed in an error message.

### 4.4 Entitlement enforcement — graduated and seasonal

This is where the reference's design must be *improved*, not merely ported.

Trial: **30 days or 25 students**, whichever first.

| Licence state | What happens |
|---|---|
| **Valid** | Everything. |
| **Expiring** (≤ 30 days) | A dismissible banner for admins; a non-dismissible line on the health page. |
| **Expired — grace (30 days)** | Persistent banner on every screen. Nothing blocked. |
| **Expired — enforced** | **Blocked:** creating a new **AcademicYear**; **report-card publication**; the rollover wizard; bulk document generation. **Never blocked:** fee collection, receipt printing, attendance, marks entry, payroll, the ledger, and **every export**. |
| **Revoked** | Same as enforced. |

The two blocked operations are chosen deliberately: they are the moments where the school most needs
the product and is most willing to pay, and they are **annual/termly**, so an expired licence never
stops a Tuesday. **Daily fee collection and attendance are never blocked under any circumstance** —
blocking a cashier queue at a Cameroonian school gate converts a billing conversation into a
reputational event.

**Data export is never blocked, under any circumstance, in any state, including revoked.** This is
stated in this specification **and in the customer contract**. In this market word of mouth is the
entire distribution channel; a vendor who holds a school's data hostage is finished in a term.

**Enforcement lives in Actions, not the UI.** `CreateAcademicYear`, `PublishPeriod`,
`RunRolloverWizard` and `BulkGenerateDocuments` each call the entitlement gate before mutating.
Pest asserts each of them refuses under an expired licence, and asserts that `RecordPayment`,
`TakeAttendance`, `EnterMarks`, `RunPayroll` and every export Action **do not** call the gate.
Hiding a menu item is not enforcement.

### 4.5 Editions

Editions key off axes the domain already has, so no new modelling is required:

| Axis | Values |
|---|---|
| **Section count** | 1 · 2 · unlimited |
| **Student cap** | 250 · 600 · 1,500 · unlimited |
| **Module bundles** | Core (academics, students, fees) · Finance (accounting, tax, procurement, assets) · People (HR, payroll) · Reach (portals, communication) |

Caps are enforced at the enrolment Action with a **soft ceiling**: exceeding the student cap raises a
persistent warning and blocks *new academic year creation*, but never blocks enrolling a student
mid-year. A school that turns away a paying pupil because of a licence counter will not renew.

### 4.6 The honest position on protection, and where the moat actually is

**Self-hosted PHP cannot be technically protected.** The source ships to the customer's machine.
Obfuscation, ionCube-style encoders and licence checks scattered through the code buy weeks and cost
maintainability, debuggability and the ability for the vendor's own support engineer to read a stack
trace. This specification does not pretend otherwise.

The real moat is contractual and operational:

1. **The support relationship** — a school that cannot restore a backup calls the vendor.
2. **The update feed** — bug fixes and new statutory documents.
3. **Signed report-card template packs** — a cracked instance cannot receive a new MINESEC bulletin
   layout.
4. **Statutory rate updates when the Finance Law changes.** Cameroon's Finance Law moves IRPP
   brackets, CNPS parameters and TVA treatment. A school running payroll on last year's brackets is
   personally liable for the shortfall. Shipping verified, dated, signed `StatutoryRate` packs the
   day the law changes is **the strongest recurring-revenue hook in the product** — v1 did not
   recognise it as one, and it should be priced as a subscription, not a favour.

### 4.7 Instance check-in registry

Opt-in, minimal, and simultaneously the licensing answer and half the fleet-observability answer.

**Payload — exactly these fields, and nothing else:** instance UUID, application version, licence
id, **student-count bucket** (`<250 | 250–600 | 600–1500 | 1500+`, never an exact number),
last-backup age in hours, last-restore-drill age in days, failed-job count, pending-migration count,
PHP and MySQL versions, OS family.

**Never sent:** student, guardian or staff data of any kind; school name; amounts; marks; IP-derived
location; the licence key.

Daily, best-effort, over HTTPS. **Silent no-op when offline**, queued into an outbox and coalesced
so a school that reconnects after a month sends one current record, not thirty. The school can see
the exact payload at `/admin/privacy/check-in` and switch it off; switching it off never affects
licence validity.

---

## 5. Data import and school onboarding

**Phase 2 blocker (00-core §15).** Every prospect arrives with 1,200 existing students, staff,
unpaid balances and an opening trial balance — in Excel, on paper, or in a competitor's system.
**Without opening balances the accounting module is unusable in year one.** This is a sales blocker
wearing a technical costume.

### 5.1 The import suite

| Importer | Target | Notes |
|---|---|---|
| **Students** | Student + Enrollment | Matricule (or generate), names, DOB, gender, class group, admission date, status, `is_repeat` |
| **Guardians** | Guardian + StudentGuardian | Link by matricule; supports one guardian to many students; sets the §07 flag matrix explicitly, never by default |
| **Staff** | StaffMember + StaffContract | CNPS number, NIU, contract type, working time, grade; blind-index columns populated on write |
| **Class assignments** | SubjectAllocation | Teacher × class group × subject × coefficient, academic-year scoped |
| **Opening invoices / balances** | Invoice + InvoiceLine, `is_migration` | Per-student outstanding by fee item, or a single "brought forward" line |
| **Opening trial balance** | `OpeningBalanceImport` → one balanced AN entry | **With partner detail** on collective accounts; validated against the supplied trial balance total before commit (`02-accounting`) |
| **Attendance (device)** | Attendance | The forward-compatible format in §11.7 |

### 5.2 The importer contract — identical for all seven

1. **Downloadable template** per importer: `.xlsx` with a locked header row, a data-validation
   sheet listing legal values for every enumerated column, and an `INSTRUCTIONS` sheet in EN and FR.
   CSV accepted too.
2. **Tolerant parsing, strict validation.** Delimiter auto-detected (`,` `;` `\t`); encoding
   detected with a UTF-8/UTF-8-BOM/Windows-1252 fallback chain (French accents from a Cameroonian
   Excel install are Windows-1252 more often than not); dates accepted as `dd/mm/yyyy`,
   `yyyy-mm-dd`, `dd-mm-yyyy` and the Excel serial, with the interpreted value echoed back in the
   preview so `03/04/2010` is never silently guessed; amounts tolerant of spaces, non-breaking
   spaces and `FCFA`, parsed to whole francs per 00-core §7.1.
3. **Preview before apply**, always. A row-level table: row number, status (`ok | warning | error`),
   and **field-level messages** — "Row 412, `class_group`: 'Form 1 A' not found. Did you mean 'Form
   1A'?" A file with errors can still be applied **only** if the operator explicitly chooses "skip
   invalid rows", and the skipped set is downloadable as a corrected-template file.
4. **Atomic apply.** One transaction per import run (chunked internally at 500 rows with a savepoint
   strategy for files above 5,000 rows; the run either completes or leaves nothing).
5. **Reversible.** Every import writes an `ImportRun` (importer, filename, sha256, row counts,
   operator, started/completed) and every created row records `import_run_id`. **Undo** deletes
   exactly and only those rows, in FK-safe order, and refuses if any of them has since been
   referenced by a payment, mark, journal entry or any other protected record — naming the first
   blocker. Undo is available for **14 days**, then the run is sealed.
6. **Idempotent.** Re-importing the same file (same sha256) is refused with "this file was already
   imported on <date> by <user>". Within a file, a duplicate business key (matricule, CNPS number,
   account code) is an error, not a silent overwrite.
7. **Bounded.** 20,000 rows or 10 MB per file, whichever first; larger files are rejected with
   "split this file" rather than being run and timing out.
8. Runs on the queue with progress, resumable after a power cut.

**Acceptance criterion:** 1,200 students + 1,600 guardians + 90 staff + a 140-line opening trial
balance import, preview to committed, in **≤ 15 minutes** on the reference hardware, with the trial
balance producing a **balanced** AN entry that ties to the supplied totals to the franc.

### 5.3 The onboarding runbook

A written, versioned service runbook, because the vendor will price against it:

| Step | Owner | Est. effort |
|---|---|---|
| Discovery call: sections, sub-systems, student count, fee structure, accounting state | Vendor | 1 h |
| Hardware check / procurement advice, UPS confirmation | Vendor | 1 h |
| Install + TLS + client trust on 3 reference devices | Vendor, on site | 2 h |
| First-run wizard: school identity, **fiscal identity** (NIU, RCCM, accreditation), sections, academic year | Vendor + proprietor | 2 h |
| Blocking-gate collection (00-core §16): CNPS notification letter, bulletin specimens, accountant session | School | 1–3 weeks elapsed |
| Data collection and template filling | School, vendor-supported | 8–20 h school-side |
| Import dry runs and corrections | Vendor | 4 h |
| Opening trial balance with the school's accountant | Vendor + accountant | 3 h |
| Training: bursar, registrar, exams officer, principal | Vendor | 6 h |
| Parallel-run term | School | 1 term |

**Total vendor effort: 20–24 hours per school**, excluding travel and the parallel term. Price
against that number.

---

## 6. Academic year rollover, and the dual-calendar runbook

### 6.1 Why this gets a wizard

Rollover is **the single most consequential annual operation in the product**, performed once a year
by someone who has done it at most twice, on the school's only server, usually in the week before
term starts. v1 gave it zero words. Doing it wrong duplicates fee structures, orphans subject
allocations, loses parent credit balances, and puts 1,200 students in the wrong classes.

### 6.2 The wizard

Ten steps, each previewable, the whole run resumable and reversible:

| # | Step | What it does | Guard |
|---|---|---|---|
| 0 | **Pre-flight** | Mandatory verified backup (§3); asserts the outgoing year has no unpublished periods, no open cash desk, no draft journal entries in a closed month | Refuses to proceed on any failure, naming it |
| 1 | **Create the new year** | `AcademicYear` with `starts_on = outgoing.ends_on + 1 day` — **contiguity is enforced, not suggested** (00-core §8) | Overlap or gap → refuse |
| 2 | **Class levels & groups** | Copy `ClassGroup` shells to the new year, preserving names, streams, capacities; class teachers copied but flagged for review | Duplicate `(year, level, name)` → refuse |
| 3 | **Subject allocations & coefficients** | Copy `SubjectAllocation` into the new `academic_year_id` (`01-assessment` requires year scoping); coefficients copied, editable in the new year only | RESTRICT-protected source rows are never touched |
| 4 | **Assessment periods** | Copy the period structure (trimestres/sequences/months) with dates shifted by the year offset, presented for date correction | Σweights invariant re-validated |
| 5 | **Fee structures & instalment plans** | Copy with an **uplift option** (flat % or per-item), instalment due dates shifted, sum constraint re-validated (`04-fees`) | Percentage residual absorbed by the last instalment (00-core §7.3) |
| 6 | **Promote students** | Consumes the promotion decisions from `07-students`; creates the new `Enrollment` rows with `is_repeat` set from the decision, not from a person flag | Refuses if any class group has undecided students; lists them |
| 7 | **Carry balances forward** | Per student: **credit balances carry forward** to the new year's student account; **debit balances remain on the old year's invoices** and appear in a "students still owing" list with a per-student action (carry as opening debt / write off / block enrolment) | Never nets across students (`04-fees` C9) |
| 8 | **Graduates & leavers** | Final-year students → `graduated`; withdrawn → `WithdrawalSettlement` (`04-fees`); their enrolments archived, never deleted | A graduate with an unsettled balance is listed, not silently archived |
| 9 | **Teacher reassignment** | Timetable and allocation reassignment grid, with departed staff flagged and the `01-assessment` delegation rule applied to any unvalidated marks | Refuses to leave a required allocation unassigned |
| 10 | **Flip the active year** | Sets `is_current`; the outgoing year moves to `closed` **only** when its last period is published and its fees are settled or explicitly carried | Two `is_current` years is impossible by constraint |

### 6.3 Rollover safety properties

- **Previewable.** Every step renders a **full diff of what will be created** — counts by entity,
  plus the row-level list for anything under 200 rows — before anything is written. A dry-run mode
  runs all ten steps against a scratch schema and produces the same diff with zero writes.
- **Resumable after a power cut.** A `RolloverRun` record (`academic_year_from`, `academic_year_to`,
  `current_step`, `step_states`, `inputs_hash`, `status`, `operator_id`) plus per-step idempotency
  keys. Restarting resumes at the first incomplete step and re-validates the earlier ones.
- **Reversible within a window.** Every row created carries `rollover_run_id`. Undo is available
  until the new year records its **first payment, first mark, or first journal entry**, and the UI
  states which of those three closed the window and when.
- **Mandatory pre-rollover backup.** Step 0, verified, no override.
- **Printable checklist** — the ten steps with sign-off boxes, generated as a PDF, for the school's
  file. `10-documents` owns the layout.
- **Idempotent.** `UNIQUE(academic_year_from_id, academic_year_to_id)` on `RolloverRun`.

**Acceptance criterion:** a full rollover of the 1,200-student fixture completes in ≤ 10 minutes;
killing the process at any step and restarting produces a byte-identical result to an uninterrupted
run.

### 6.4 The September–December dual-calendar runbook

Cameroon's academic year starts in September; the OHADA exercice is fixed at **1 January –
31 December** (`02-accounting`). For four months every year, an old academic year may still be
partially open, a new one is running, and a new exercice begins mid-term. This is the period when
bursars make the errors that take an auditor a week to unpick. The runbook ships as an in-app help
topic and a printable.

**Order of operations, September:**

1. Complete the outgoing year: publish all periods, settle or carry all balances, run the
   `WithdrawalSettlement` for leavers. *Only then* close the academic year.
2. Run the rollover wizard (§6.2). The new academic year is now `active`.
3. Issue the new year's invoices. **They are dated in the current exercice** (the one that ends
   31 December) but their `InvoiceLine.service_period_start/end` spans into the next
   (`04-fees` C4) — this is what makes the December cut-off work.
4. Continue collecting against **both** years' receivables. The student account is per student, not
   per year; the aged-receivables report is by instalment due date with an explicit `as_of`.

**Order of operations, 31 December – 15 January:**

1. **Soft-lock** December (`02-accounting` C8). Operational modules stop posting into it; the
   academic year continues untouched — *closing the exercice does not close the school*.
2. Physical inventory, cut-off entries, **revenue deferral** (`Dr 7xxx / Cr 477` for the portion of
   September's annual tuition that is delivered after 31 December), doubtful-debt review,
   depreciation, provisions, trial balance, tax provision.
3. Close, appropriate the result, post the à-nouveaux, **hard-lock**.
4. **Write the yearly immutable backup archive** (§3.3) as a checklist step.
5. Reverse the `477` deferral on 1 January.

**What the system refuses, and says why in plain language:**

| Attempt | Refusal |
|---|---|
| Posting into a hard-locked month | "January 2027 is closed. This transaction will be posted on the first day of the first open period, keeping its original value date of 28/12/2026." (AUDCIF Art. 22 forward-posting, not rejection.) |
| Creating a second `is_current` academic year | Blocked by constraint. |
| Creating an academic year with a gap or overlap | Blocked; the wizard offers the corrected dates. |
| A `FiscalYear` that is not 1 Jan – 31 Dec | Blocked, with the legal reason, in the setup wizard (`02-accounting`). |
| Publishing a report card for a period in a year that is `closed` | Blocked; un-publication and amendment go through `01-assessment` C10. |
| Rollover while an unpublished period exists | Blocked, listing the class groups. |

**The "What's open right now" panel** (top-level, visible to Bursar, Accountant, Principal and
Administrator) shows, at all times: the **active academic year** and its date range · the **active
exercice** and its date range · the **current accounting period** and its lock state · a list of
soft- and hard-locked months · the next scheduled forced quarterly closure (AUDCIF Art. 22) · and
whether any assessment period is open for marks entry. Four questions a bursar asks daily, answered
in one glance, in one place.

---

## 7. Observability

### 7.1 Logging

- **Channel:** `daily`, **14 files retained**, **size cap 50 MB per file with rotation on the cap as
  well as on the day**, and a **disk-free guard** that switches to `error`-level only below 10 %
  free and stops writing entirely below 2 %, recording that fact in the health page.
  *The reference has exactly the bug this rule exists to prevent: rolling logs with no retained-file
  limit and no size cap, which eventually fills a school PC's disk and takes MySQL down with it.*
- **Format:** structured JSON — `timestamp`, `level`, `message`, `correlation_id`, `user_id`,
  `module`, `action`, `duration_ms`, `context`. A **correlation id** is generated per request
  (Livewire round-trips inherit the component's id) and propagated into queued jobs, so one parent's
  visit to the cash desk is one greppable identifier across the web request, the posting job and the
  PDF render.
- **Never logged:** student names, marks, amounts, licence keys, passwords, tokens, national ID or
  CNPS numbers, medical data. Enforced by the §7.2 scrubber, which applies to the log channel as
  well as to error reporting.
- Slow-query log enabled at **1 s**; slow-request log at **2 s**, both feeding the health page's
  "recent slow operations" list.

### 7.2 Error tracking

Self-hosted **GlitchTip** (Sentry-compatible), **opt-in per school**, vendor-hosted endpoint.

- **PII scrubber is a whitelist, not a blacklist.** Only explicitly allow-listed keys are
  transmitted (`module`, `action`, `entity_type`, `entity_id`, `version`, `php_version`,
  `correlation_id`, `route`, `sql_fingerprint`). Everything else in request bodies, query strings,
  session, headers and exception context is replaced with `[scrubbed]` **before** the payload leaves
  the process. A blacklist misses the field added next sprint; that field will be a guardian's phone
  number.
- Stack traces are sent; **local variable values are not**.
- **Offline outbox:** events are queued to disk when the endpoint is unreachable, capped at 500
  events / 20 MB, oldest dropped, flushed on reconnect.
- **Why this is non-negotiable at fleet scale:** without it the vendor learns about a bug by
  telephone, weeks later, from a bursar, with no stack trace and no reproduction. With fifty
  schools that is not a support model.

### 7.3 The health page

`/admin/health`, **written for a bursar, not an engineer.** One line per check: a green / amber / red
dot, a plain-language statement of what is true, and where it is not green, **a plain-language
remedy**. No JSON, no stack traces, no metric names.

| Check | Green | Amber | Red |
|---|---|---|---|
| Application version | Current | Update available | Yanked version installed |
| Database reachable + size | Reachable | — | Unreachable |
| **Pending migrations** | Zero | — | **Any (always red)** |
| Queue worker heartbeat | < 120 s | < 300 s | ≥ 300 s or no worker |
| Failed jobs | 0 | 1–9 | ≥ 10 |
| **Last successful backup** | ≤ 26 h and verified | ≤ 50 h, or verification not run | > 50 h, or last verification failed |
| Backup destination | ≥ 2 targets, one off-volume | Second target unreachable | **Only copy is same-volume** (amber-floor per §3.5; red if also stale) |
| **Last restore drill** | ≤ 40 days, passed | ≤ 60 days | > 60 days or failed |
| Disk free | ≥ 20 % and ≥ 15 GB | ≥ 10 % | < 10 % or < 5 GB |
| PHP extensions | All present | — | Any missing |
| MySQL durability settings | Match §1.6 | — | Any deviation |
| Licence status | Valid | Expiring ≤ 30 d | Expired / revoked |
| Scheduler last run | ≤ 2 min | ≤ 15 min | > 15 min |
| Backfill jobs | None pending | Running | Stalled > 24 h |
| Storage by category | Informational: DB, documents, photos, generated PDFs, backups, logs — with sizes and growth per month |

Plus a machine-readable **`/up`** returning `{status, version, schema_version, checks:{...}}` — 200
when everything is green or amber, 503 when any check is red — for VPS monitoring and for the
check-in registry.

### 7.4 The support diagnostics bundle

**One button** on the health page produces `opes-support-<instance>-<timestamp>.zip` containing:
the last 3 days of logs (scrubbed), the health snapshot as JSON, `migrate:status` output, the schema
version and table row counts, the `UpdateRun` and `RestoreDrill` history, the failed-jobs list with
scrubbed payloads, `php -i` filtered to the relevant sections, the MySQL variables from §1.6, and
**`.env` with every secret replaced by `[redacted]`** — the redaction is a whitelist of *shown* keys,
not a blacklist of hidden ones.

Capped at 25 MB. Ready to email or copy to a USB stick. **Supporting fifty remote schools with no
VPN requires the school to be able to send you the truth in one action**; anything requiring the
bursar to find a file path will not happen.

---

## 8. Performance

### 8.1 The volumes v1 never stated

For the reference school — **1,200 students**, 12 subjects, 6 sequences, 40 class groups:

| Data | Per year | At 7 years |
|---|---|---|
| Marks | ~173,000 | **~1.2 M** |
| Daily attendance | ~216,000 | ~1.5 M |
| **Per-lesson attendance** (mandatory for MINESEC — `07-students` C6) | **~1.7 M** | ~12 M |
| Audit log | **> 400,000** | ~2.8 M (3 y hot, then archived) |
| Journal entry lines | ~120,000 | ~840,000 |
| Invoices / lines | ~3,600 / ~18,000 | ~126,000 |
| Payments | ~9,000 | ~63,000 |
| Generated PDFs | ~15,000 | ~105,000 |

v1 stated none of this and specified **exactly one index**, which did not serve the hot query.

### 8.2 Index appendix — keyed to access paths, not to guesses

Each index below names the query it exists for. An index without a named access path does not ship.

| Table | Index | Serves |
|---|---|---|
| `marks` | `(assessment_period_id, subject_allocation_id)` | Marks-entry grid for one class × subject × period |
| `marks` | `(enrollment_id, assessment_period_id)` | One student's report card |
| `marks` | `(subject_allocation_id, assessment_period_id, status)` | Pending-publication gate; "which teacher has not submitted" |
| `marks` | `(assessment_period_id, workflow_state)` | HOD verification queue |
| `enrollments` | `(class_group_id, academic_year_id, status)` | Class roster, the single most-run query in the product |
| `enrollments` | `(student_id, academic_year_id)` + the generated active-key UNIQUE (00-core §10.1) | Student history; duplicate prevention |
| `enrollment_segments` | `(enrollment_id, starts_on)` | Mid-year transfer resolution |
| `attendance` | `(attendance_register_id)` and `(enrollment_id, date)` | Register rendering; per-student absence hours |
| `attendance_registers` | `(class_group_id, date, session)` UNIQUE | Denominator counting (`07-students` C5) |
| `journal_entry_lines` | `(account_id, entry_date, id)` | Grand livre, balance |
| `journal_entry_lines` | `(partner_type, partner_id, account_id)` | Aged receivables / payables, auxiliary reconciliation |
| `journal_entry_lines` | `(lettering_code)` | Unlettered-items report |
| `journal_entries` | `(fiscal_year_id, journal_id, piece_no)` UNIQUE | Gapless sequence check |
| `journal_entries` | `(accounting_period_id, status)` | Period close, trial balance |
| `invoices` | `(enrollment_id, status)` | Student fee statement, balance card |
| `invoice_lines` | `(invoice_id)`, `(fee_item_id, service_period_start)` | Line allocation; revenue recognition |
| `payments` | `(business_date, payment_method_id)` | Daily collections, cash-desk close |
| `payment_allocations` | `(invoice_line_id)`, `(payment_id)` | Balance formula |
| `payroll_items` | `(payroll_month, staff_member_id)` UNIQUE | 00-core §10.4 |
| `audit_log` | `(auditable_type, auditable_id, created_at)` | **"Who changed this mark"** — the only audit query that is actually run |
| `audit_log` | `(created_at)` partitioned by year | Retention and archival |
| `document_print_log` | `(document_type, subject_type, subject_id, printed_at)` | Bulk-print "Last Printed / Printed By" |
| `stock_movements` | `(item_id, moved_at, id)` | Weighted-average cost recomputation under lock |

**Rule:** every index is justified in the migration docblock by the access path it serves and the
query plan before/after. `EXPLAIN` output for the top 20 queries against the reference fixture is a
CI artifact, and a plan regressing to `ALL` on a table above 100,000 rows **fails the build**.

### 8.3 The blanket pagination rule

No query returning an unbounded collection reaches a Blade view (00-core §6.2 rule 8). Default page
size 25, maximum 200. Exports stream through a chunked cursor and never materialise. Enforced by a
Pest architecture test that flags `->get()` and `->all()` in Livewire components and controllers
without an enclosing pagination or explicit `->limit()`, with a reviewed allow-list.

### 8.4 Report-card batch generation — the marquee feature

v1 never mentioned it. **1,200 report cards at 0.3–2 s each is 6–40 minutes of solid CPU** on a
machine simultaneously serving 20 users. Synchronously it times out; asynchronously it needs the
supervised queue worker from §1.7.

Design:

- **Chunked queued job, 50 students per chunk**, on the `low` queue.
- **Concurrency capped at 2 workers** for document generation so the interactive UI stays usable.
  The cap is enforced with a queue-level semaphore, not by hoping.
- **Resumable after a power cut.** A `DocumentBatch` record (`type`, `scope`, `snapshot_batch_id`,
  `total`, `completed`, `failed`, `status`, `artifact_path`) plus per-student idempotency; restarting
  regenerates only what is missing.
- **Per-chunk progress** surfaced live (chunk n of 24, 1,150 of 1,200 done, ~4 min remaining).
- **Durable artifacts:** one PDF per student (the printable of record, hash-logged per 00-core §14)
  **plus** one merged print file per class group, retained until the next generation for that
  snapshot.
- Renders read the **snapshot** (`01-assessment`), never recompute. A batch is therefore
  reproducible and safely restartable.

**Acceptance criterion (blocking, Phase 3): 1,200 report cards in ≤ 10 minutes on the reference
hardware, with a second user's page loads staying within their §8.6 budgets throughout.** This is
also blocking gate 11 in 00-core — the PDF engine is chosen by benchmarking against this number, not
by preference.

### 8.5 Specific algorithmic requirements

- **Ranking is a single windowed pass.** MySQL 8's `RANK()`, `DENSE_RANK()` and `ROW_NUMBER()` map
  exactly onto the three tie rules in `01-assessment`. The O(n²) "count students with a higher
  average" query per student that v1 implied is 1,440 queries per class group and is a
  review-blocking defect.
- **Class statistics are persisted, not cached.** Published statistics must reproduce exactly, years
  later, on a machine where no cache exists. A Redis-cached mean is a different number from a
  recomputed one the moment a mark is amended.
- **Period-closing balance snapshots.** Ledger reports read `last_closed_snapshot + movements_since`
  rather than summing seven years of lines. A nightly job recomputes a sample of snapshots from
  scratch and **alarms on any drift**, because a wrong cached balance is worse than a slow correct
  one.
- **Weighted-average stock cost** is maintained on the item row under `FOR UPDATE`, changed only by
  receipts (`06-assets-stores`) — never recomputed by scanning the movement history at read time.
- **Audit log partitioning** by `YEAR(created_at)`, 3 years hot, older partitions exported to a
  signed compressed archive and dropped.

### 8.6 Screen budgets, and the honest Livewire tension

v1 avoided an internal HTTP hop on bandwidth grounds. That reasoning is incomplete: **Livewire is
itself a round-trip per interaction**, carrying serialized component state both ways. On a school
Wi-Fi with 40 devices, or a guardian on a 3G phone, that is the dominant cost.

Rules:

- **`wire:model.live` is forbidden on grids and on any input inside a repeating row.** Use
  `wire:model.blur`, `wire:model.debounce.500ms`, or Alpine-local state with one batched commit.
  Enforced by a static check over Blade templates.
- Components declare `#[Locked]` on identifiers and keep payloads minimal; large collections are
  computed properties, never public state.
- **Budgets for the top five screens**, measured on the reference fixture, at the 95th percentile,
  **on a throttled "Fast 3G" profile (400 kbps, 400 ms RTT) as a CI gate**:

| Screen | Server time | Livewire payload (round trip) | Interaction to update |
|---|---|---|---|
| Dashboard (first paint) | ≤ 400 ms | ≤ 60 KB initial HTML | ≤ 2.5 s on 3G |
| Student list (25 rows, filtered) | ≤ 250 ms | ≤ 40 KB | ≤ 1.5 s |
| **Fee collection (cashier)** | ≤ 200 ms | ≤ 25 KB | **≤ 1.0 s** — a queue of parents |
| **Marks entry grid (40 students × 3 components)** | **≤ 300 ms per save of 40 marks**, **≤ 1 request per save** | ≤ 35 KB | ≤ 1.2 s |
| Report card preview (one student) | ≤ 800 ms | ≤ 50 KB | ≤ 3 s |

- **Marks grid specifics:** Alpine-local state for every cell, one batched save for the whole grid,
  optimistic UI with a per-cell saved indicator, an unsaved-changes navigation guard, and **full
  keyboard navigation** (arrows, Enter to advance, Tab across components, paste a column from
  Excel). Keyboard navigation here is a **productivity** requirement before it is an accessibility
  one: a teacher entering 480 marks with a mouse will use paper instead.

### 8.7 The load-cost test pattern (ported)

The single most valuable engineering artifact in the reference implementation, and v1 dropped it
entirely: the reference asserts that **a class broadsheet costs exactly 3 repository calls
regardless of class size** — verified at 1, 5 and 40 students — and that **view-model constructors
issue zero queries**.

Ported as Pest tests using a query counter:

- `broadsheet(class_group)` issues **exactly 3** queries at 1, 5, 40 and 120 students.
- `report_card(student)` issues a **constant** number of queries independent of subject count.
- `student_list(page)` issues a constant number independent of page size.
- **Every Livewire `mount()` and every view-model constructor issues zero queries.**
- The class-roster, fee-statement, broadsheet and payslip paths each have a named query-count
  ceiling, asserted, and lowering it requires editing the test — so an N+1 introduced by a
  well-meaning eager-load removal **fails CI** rather than being discovered by a school.

---

## 9. Privacy and data protection

**Every data subject in this product is a minor or the parent of one.** That framing decides the
close calls.

### 9.1 The blocking legal review

**A Cameroonian data-protection legal review is a blocking open item (00-core §16 gate 10, blocks
Phase 12).** That v1 did not even list it is the finding, independent of what the current statutory
detail turns out to be. The review must return: the applicable statute and regulator; whether
registration or notification of processing is required; lawful basis for processing minors' data and
whether guardian consent must be recorded per data class; breach-notification obligations and
deadlines; cross-border transfer rules (which decide whether a VPS outside Cameroon is permissible);
and retention limits that override §9.4. **NEEDS VERIFICATION** — nothing in this section asserts a
Cameroonian legal requirement; it asserts engineering positions that are defensible under any
plausible regime.

### 9.2 Student and staff document storage — a fixed decision

v1 never said whether uploads sit on the private disk behind a policy-checked controller or in
`public/` behind `storage:link`. If the latter — **Laravel's path of least resistance** — every
student photo and scanned birth certificate is **world-readable by URL guess**, with the parent
portal internet-reachable on VPS deployments.

**Fixed decision, not a recommendation:**

- Student and staff documents and photos live on the **private disk only**
  (`storage/app/private/...`), served exclusively through a **policy-checked controller**.
- **`storage:link` is never used for student or staff documents.** Only the school crest and other
  deliberately public branding assets may be public. A CI check fails the build if
  `storage/app/public` contains any path under `students/`, `staff/`, `guardians/` or `medical/`.
- Served URLs are **signed with a short TTL (5 minutes)** and are bound to the authenticated user;
  a signed URL leaked into a chat log expires before it is useful.
- Filenames on disk are opaque ULIDs; the original filename is metadata. `student-12345-birth-
  certificate.pdf` in a URL is itself a disclosure.
- **Test (blocking):** a logged-out request for a known document path returns **404, not 403** —
  403 confirms existence — and a logged-in guardian's request for another family's document returns
  404. Part of the deny-by-default enumeration suite (00-core §9.2).

### 9.3 Upload limits — an ops failure and an RCE path

The reference had no size guards; neither did v1.

| Rule | Value |
|---|---|
| Per-file cap | **5 MB** documents, **2 MB** photos |
| Accepted types | Documents: `pdf, jpg, jpeg, png`. Photos: `jpg, jpeg, png, webp` |
| Validation | **MIME sniffed server-side *and* extension whitelisted** — both, because either alone is bypassable |
| **Image re-encoding** | Every accepted image is **decoded and re-encoded** server-side before storage. This strips EXIF (including **GPS coordinates of a child's home**), ICC payloads and any polyglot/embedded content. A file that fails to decode is rejected |
| PDFs | Rejected if they contain `/JavaScript`, `/OpenAction`, `/Launch` or `/EmbeddedFile`; page count capped at 30 |
| Per-student quota | 40 MB |
| Per-instance quota | Configurable, default 60 % of the data volume; uploads refuse above it with a clear message rather than filling the disk |
| Disk-headroom guard | Uploads refuse below the §1.4 red threshold |
| Storage | Never executable; the web server is configured to refuse to serve anything from the storage tree directly |

### 9.4 Retention schedule per data class

Reviewable, audited, **scheduled purge jobs — never silent**. Each purge writes a
`RetentionRun` (data class, criteria, rows affected, operator or `system`, dry-run flag) and produces
an admin notification 14 days before it executes, listing what will go.

| Data class | Retention | Rationale |
|---|---|---|
| Academic records (enrolment, marks, report-card snapshots, transcripts) | **Indefinite** | Schools issue attestations decades later; this is the product's core promise |
| Accounting records (entries, invoices, payments, statutory books) | **10 years minimum, hard-blocked from deletion** | AUDCIF Art. 24 (`02-accounting` C5) |
| Payroll records | 10 years | Statutory alignment; CNPS quarters surface decades later |
| Medical records | **N years post-departure — `N` NEEDS VERIFICATION** (default 5, configurable, never below the legal floor once known) | Health data about minors |
| Discipline cases | **Graduation + 1 year**, then purged | A school-age sanction must not follow an adult |
| Message / SMS / WhatsApp logs | **12 months** | Delivery evidence, not a permanent record |
| Audit log | **3 years hot, then signed compressed archive** (00-core §14) | Volume and utility both fall off |
| **Rejected admission applications** | **12 months** | Permanently retaining the data of a child the school did not admit is the classic data-protection audit finding (`07-students`) |
| Session and authentication logs | 12 months | |
| Generated PDFs | Regenerable from snapshots; retained 24 months then pruned | Disk, not law |
| Backups | §3.3 GFS, with the 10-year yearly archives | |

### 9.5 Erasure — pseudonymise, don't delete

Three of this product's correctness guarantees make literal deletion impossible: **the audit log is
append-only and hash-chained**, **payments are never deleted**, and **enrolment history is never
deleted**. All three are correct. All three must be reconciled with any erasure obligation now,
because **retrofitting erasure into an immutable-by-design schema is a rewrite**, not a feature.

**`PseudonymiseSubject` Action:**

- Replaces, on the `Student` / `Guardian` / `StaffMember` row: given and family names → `[Effacé]`
  plus a stable tombstone token (`SUBJ-01J8…`); photo → deleted from storage; phone, email, address,
  national ID, medical notes, genotype, blood group, religion → deleted; documents → files deleted,
  metadata rows retained with a tombstone.
- **Preserves:** the enrolment skeleton (which class group, which year, promotion outcome), the
  ledger skeleton (invoices, payments, allocations, journal entries — amounts and dates intact,
  partner reference now pointing at the tombstone), the audit chain, and academic snapshots'
  *numbers*. Report-card snapshots are re-rendered as tombstoned on reprint but their hashes remain
  valid for the original issue.
- **Refuses** while any accounting record involving the subject is inside the 10-year window
  **unless** the operator confirms the documented legal basis for retention, which is then recorded
  on the run. The refusal message names the basis rather than saying "not allowed".
- **Permissioned** (Super Admin), **audited** (the audit entry records who, when, which subject
  token, and the stated ground — never the erased values), and **irreversible**, with the
  irreversibility stated on the confirmation and a mandatory pre-run backup.
- A `PseudonymisationRun` register lists every run, so a school can demonstrate compliance.

### 9.6 Data export by the school, without the vendor

Runnable by the school's own Administrator, no vendor involvement, **never blocked by licence state
(§4.4)**:

- Full encrypted database dump (the §3 artifact).
- The complete file store (documents, photos), as a structured archive mirroring the database keys.
- **A generated schema document** — tables, columns, types, relationships, and the enum meanings —
  so the dump is intelligible to whoever the school hires next.
- **CSV extracts** of the entities a school actually needs portable: students, guardians, staff,
  enrolments, marks, invoices, payments, journal entries, chart of accounts, payroll items.
- A `MANIFEST.json` with counts, checksums, the schema version and the export timestamp.

Produced as one queued job with progress and a downloadable/USB-copyable artifact.

### 9.7 Cross-border transfer, breach runbook, privacy notice

- **Cross-border position:** LAN deployments keep all data in Cameroon by construction. **VPS
  deployments must default to a Cameroonian or, failing that, a nearest-region host**, and the
  hosting location is disclosed in the contract. Whether a non-Cameroonian host is permissible is
  part of the §9.1 review; until it returns, VPS-outside-Cameroon is offered only with written
  customer acknowledgement.
- **Breach runbook** (shipped as a document, not just a policy): detect → contain (revoke sessions,
  rotate `APP_KEY` per the 00-core §9.4 procedure, isolate the host) → assess scope from the audit
  log and access logs → notify the school proprietor within **24 hours** → notify the regulator and
  data subjects per the §9.1 findings → post-incident report with remediation. Includes the exact
  queries that establish which records were accessible, and a rehearsal requirement (tabletop, once
  per year, vendor-side).
- **A template privacy notice for guardians**, EN and FR, listing what is collected, why, who sees
  it, how long it is kept (from §9.4), and how to ask for correction — issued at admission and
  referenced from the admission form (`10-documents`).

---

## 10. Testing

### 10.1 The upgrade test — the most important test in a self-hosted product

It did not exist in v1. **Blocking on every release.**

CI restores a **production-shaped v(N−1) database** (the reference fixture, aged, with a published
year, a closed exercice and real volumes), migrates to N, and asserts:

1. **Row counts preserved** on every protected table (the §2.5 list), exactly, except where the
   release documents an insert.
2. **Every journal entry still balances**, and the trial balance total is unchanged to the franc.
3. **Computed averages are unchanged for published periods** — recompute from the snapshot and
   compare; a grading-engine change that silently alters a published bulletin is the single worst
   regression this product can ship.
4. **No orphaned foreign keys.**
5. Audit hash chain still verifies.
6. Payslip re-render from snapshot is **byte-identical** to the stored artifact.
7. The migration completes within **20 minutes** on CI hardware at reference volumes.

The matrix runs the upgrade test from **each of the last three minor versions**, not just N−1.

**Per-school staging procedure for risky releases** (any release marked `breaking`, any release
touching the grading engine, payroll or the ledger): the vendor restores that school's most recent
backup into a scratch instance, runs the upgrade test against **their** data, and reports the result
before offering the update. Documented, with the expected effort (≈ 1 hour per school).

### 10.2 Environments and CI

| Environment | Purpose |
|---|---|
| Local | Developer, `sail`/Laragon, seeded fixture subset |
| CI | Ephemeral, MySQL 8 service container, full gate set |
| Staging | Vendor-run VPS mirroring a real school's shape; the parallel-run pilot's twin |
| Per-school scratch | Created on demand from a school's backup for §10.1 staging |

**CI stages, all blocking (00-core §4):** lint/format → **PHPStan level 8** → **Pest architecture
tests** → unit + feature tests with coverage floors (**90 % on `Domain/`, 80 % on `Actions/`**) →
**`composer audit`** → the offline-boot test → **performance-budget harness** → the **upgrade test**
→ build artifacts.

**The offline-boot test** (00-core §3): CI boots the application with **all outbound network
blocked** and runs the core workflows — enrol a student, collect a fee, print a receipt, enter and
publish marks, run payroll, post a journal entry, take a backup. Any hang, any 500, any error page
fails the build. Degradation must be a queued outbox or a disabled menu item with a clear message,
never a blocking error and never a hang.

**Matrix:** PHP **8.3** and **8.4** × MySQL **8.0** and **8.4**. MariaDB is not tested because it is
not supported (00-core §4); a startup check refuses to boot on MariaDB with a clear message rather
than failing later on a collation.

### 10.3 Demo and seed data

The reference's **manifest approach** is ported, and its release notes record that the risk below
was realised in practice:

- Every row inserted by the demo seeder records its id in a **`DemoManifest`**, so removal is
  **exact** — not "delete where created_at < X", which eventually deletes a school's real data.
- Removal runs in **FK-safe delete order**, derived from the schema, not hand-maintained.
- Generated avatars and documents go into a **separate folder** (`storage/app/demo/`) so real
  uploads are untouched by removal.
- A **persistent, non-dismissible "DEMO DATA PRESENT" banner** on every screen while any manifest
  row exists.
- **Every PDF generated while demo data is present carries a `DÉMONSTRATION / DEMO` watermark.** A
  demo receipt that looks like a real receipt is a control failure.
- Removal is a single admin action, transactional, audited, and reports what it removed.

### 10.4 The reference school fixture

Built in **Phase 1**, used by CI budgets from **Phase 3** (00-core §15):

1,200 students · 40 class groups across nursery, primary and secondary · 12 subjects · 6 sequences ×
3 trimestres · **7 academic years** · ~1.2 M marks · ~3 M audit rows · 90 staff with contracts and
12 months of payroll · a full chart of accounts with 7 years of entries · 1,600 guardians · library,
inventory and asset registers.

Generated deterministically from a seeded RNG so every developer and every CI run has the *same*
fixture, checked by a manifest hash. Built once and cached as a dump; rebuilt when the schema
changes.

### 10.5 Security testing

- **`composer audit`** blocking on every build; a weekly scheduled run so a newly-disclosed CVE in
  an unchanged dependency surfaces without a commit.
- **SAST** (Psalm taint analysis or equivalent) on every PR, focused on the two named injection
  surfaces: the payroll **formula grammar** (`05-hr-payroll`) and **PostingRule conditions**
  (`02-accounting`). Both must be a whitelisted expression grammar parsed at save — never `eval()`.
- **The guardian deny-by-default route enumeration suite** (00-core §9.2): walk every route and
  every Action, assert a guardian is denied unless explicitly allow-listed against the cell-by-cell
  matrix in `07-students`.
- **An architecture test can only prove an `authorize()` call exists, not that it is the right
  one.** The enumeration suite is what proves the second half; neither test is sufficient alone and
  both ship.
- Rate limiting on login, password reset, the QR verification endpoint and the API, with lockout and
  audit.
- **A penetration test before the first paying customer**, scoped to: the guardian portal boundary,
  file storage (§9.2), the upload path (§9.3), session handling over the local-CA TLS, and the
  restore/backup surface. Findings triaged with fix deadlines by severity.

### 10.6 Acceptance and the pilot

Each phase in 00-core §15 has **written acceptance criteria and a named sign-off**. The
operationally-owned ones are collected in §12 below.

**A named pilot school runs in parallel with paper for one full term before general sale.** Not a
week. **The operations that break are annual and termly, not daily** — rollover, publication, term
invoicing, the December close. A one-week pilot exercises none of them. The pilot's exit criteria:
one full term published from the system, one term's fees collected and reconciled against the
school's paper receipts to the franc, one payroll month reproduced to the franc against the school's
own payslips (`05-hr-payroll`'s ~20-payslip gate), and one successful unannounced restore drill.

---

## 11. Remaining non-functional requirements

### 11.1 SMS

- **Gateway choice and billing model are a blocking decision** (00-core §16 gate 12) — **per-school
  prepaid** versus **vendor-billed with a margin** is a business decision hiding as a technical one,
  and it changes the credit model, the reconciliation, and who the operator calls when messages stop.
  Decide before building.
- **Pre-send confirmation** on every broadcast: recipient count, message length in segments,
  **estimated cost in FCFA**, and the sender id.
- **Hard credit check before dispatch.** If the credit will not cover the whole batch, the send is
  refused, not started — with the shortfall stated.
- **What happens at recipient 600 of 1,200:** the batch is **resumable, not restartable**. Each
  recipient row carries a state (`queued | sent | delivered | failed | skipped`); exhausted credit
  pauses the batch at the current row and raises an alert; topping up resumes at row 601. A batch is
  never silently truncated and never re-sends a delivered row.
- **Per-recipient delivery status persisted and viewable**, with the gateway's reference, retained
  12 months (§9.4).
- **Two-person approval above a threshold** (default: 100 recipients, configurable): a second user
  with an approval permission must confirm. **An accidental all-guardians broadcast is unrecallable
  and reputationally expensive**, and it will happen — someone will filter wrongly at 18:00 on a
  Friday.
- Offline: the outbox queues and flushes on reconnect; the composer says "will send when the
  internet returns", never an error.
- Opt-out honoured per guardian communication preference (`07-students`), and never bypassed by a
  "system" message.

### 11.2 WhatsApp

WhatsApp Business requires a Business account, a **dedicated number**, and **pre-approved message
templates per school** — free-text outside a 24-hour customer-initiated window is not permitted.
The reference implemented **templates only, with no free-text method at all**, for exactly this
reason. That constraint changes the whole message-composition UX: the operator picks a template and
fills variables; they do not type a message.

**Decision: scope full WhatsApp Business API to v2.** v1 ships **`wa.me` deep links** — a per-guardian
"Message on WhatsApp" button that opens the operator's own WhatsApp with a pre-filled text. It needs
no API, no number, no template approval and no per-school onboarding cost, and it covers the
realistic v1 use case (a bursar chasing one parent). If the full API is later scoped, the per-school
onboarding — account verification, number provisioning, template submission and approval latency —
must be costed into the onboarding runbook (§5.3) as a separate multi-week line item.

### 11.3 Thermal receipt printers

A multi-cash-desk cashiering system printing A4 PDF receipts to a shared office laser is slow,
costly and wrong for a queue of thirty parents.

**Cheapest credible path, and the one specified:** an **80 mm page-size PDF profile** (and a 58 mm
variant) for the receipt document — narrow layout, monospaced amounts, no logo halftones — printed
to an ESC/POS printer via its Windows driver. No direct ESC/POS byte generation in v1; no printer
SDK; no browser print dialogue for the cashier path (a silent print target is configured per
workstation).

Rules: the receipt renders **only after the commit returns** (§1.6); a reprint is watermarked
`DUPLICATA` and logged as a duplicate (00-core §14); the 80 mm profile is a golden-file test like
every other document (`10-documents`). Direct ESC/POS and cash-drawer kick are explicitly **v2**.

### 11.4 Barcodes and scanner input

- **Symbology:** **Code 128** for asset tags, library copies and inventory items (dense, alphanumeric,
  universally readable by cheap scanners); **QR** for documents, ID cards and verification tokens
  (00-core §13.5).
- **v1 generated barcodes that nothing ever scanned.** The scanner *input* design is therefore part
  of this specification:
  - Scan fields **auto-focus** on screen entry and re-acquire focus after every scan.
  - **Scan-versus-type discrimination by inter-keystroke timing**: a burst with gaps < 30 ms
    terminated by Enter is a scan and submits immediately; slower input is treated as typing and
    requires an explicit action. This is what makes a single field serve both a scanner and a
    keyboard.
  - **Audible confirmation** — distinct success and failure tones — because the operator is looking
    at the book, not the screen.
  - **Continuous-scan mode** for stock-takes and library returns: each scan appends to a list and
    clears the field, with an undo per row and a running count.
  - Unknown code → an inline "not found, register it?" affordance, never a page-level error.

### 11.5 Accessibility

**WCAG 2.2 AA** as the target, verified with automated checks (axe) in CI on the top 20 screens plus
a manual keyboard-only pass per phase.

The specific rule this domain needs: **colour always paired with a letter or a label.**
`GradeBand.colour` and `CompetencyLevel.colour` carry meaning. Colour alone fails colour-blind users
**and fails greyscale printing on the mono laser printers schools actually own** — a bulletin whose
"red = failing" prints as grey is a defective bulletin. Every coloured status in the UI and in every
printable carries its text label or letter code.

Also: focus visible on every interactive element; form errors associated programmatically; the marks
grid fully keyboard-operable (§8.6); minimum 4.5:1 contrast; no information conveyed by hover alone
(touch devices); respect `prefers-reduced-motion`.

### 11.6 Browser support matrix

| Browser | Support |
|---|---|
| Chrome / Edge (Chromium) | Last 2 major versions — **primary** |
| Firefox | Last 2 major versions |
| Safari / iOS Safari | Last 2 major versions |
| Samsung Internet | Last 2 major versions |
| Android WebView | Current |
| **Opera Mini (extreme-saving proxy mode)** | **Not supported — interstitial** |
| IE / legacy Edge | Not supported |

**Opera Mini's extreme-saving proxy mode renders server-side and strips the JavaScript Livewire
depends on: the application does not work at all, not merely imperfectly.** It is widely used in
this market precisely because it saves data, so guardians *will* arrive on it. The unsupported-browser
interstitial detects it and says, in EN and FR: *"This site needs Opera's normal mode. Open Opera's
menu and turn off Extreme data saving, or use Chrome."* — a remedy, not a rejection.

### 11.7 Concurrent-editing indicator

Alongside the optimistic locking in 00-core §10.6 (`version` columns on `Mark` and `Invoice`), the
UI shows presence: **"Mme Fotso is also editing Form 1A — Mathematics"**, driven by a short-TTL
presence record (30 s heartbeat, database-backed, no Redis requirement) written when a marks grid or
an invoice is opened. It is advisory — the locking is what guarantees correctness — but it prevents
most collisions before they happen, and on collision the conflict dialogue shows **what changed and
who changed it** rather than overwriting.

### 11.8 Database Maintenance screen

MySQL equivalents of the reference's SQLite maintenance, all safe to run on a live school database,
all queued with progress, all audited:

| Action | Mechanism | Guard |
|---|---|---|
| Update statistics | `ANALYZE TABLE` on the largest 20 tables | Non-blocking; nightly by default |
| Reclaim space | `OPTIMIZE TABLE` (InnoDB → online rebuild) | **Manual only**, requires free disk ≥ table size, refuses during school hours (07:00–18:00) by default |
| Index health | Report unused indexes (`sys.schema_unused_indexes`), redundant indexes, and tables above 100,000 rows without an index serving a known access path | Report only; never auto-drops |
| Log pruning | Application logs beyond §7.1 retention; failed jobs beyond 30 days | Scheduled |
| Table sizes | Report per-table rows, data and index size, and 30-day growth | Read-only |
| Orphan sweep | Report orphaned uploaded files (on disk, no metadata row) and orphaned metadata (row, no file) | **Report only**; deletion is a separate confirmed action |
| Integrity spot-check | Ledger balance, audit chain, sequence gaps, auxiliary-vs-collective reconciliation | Nightly; results on the health page |

### 11.9 In-app help

The reference ships a **19-topic manual**, including a *"what this product does not do"* topic, and
that topic **materially reduces support load in this market** by preventing the calls that begin
"why can't it…". Ported and extended:

- Context help on every complex screen (rollover, publication, period close, payroll, backup).
- Searchable, bilingual, versioned with the application, **available offline** (it is bundled, not
  fetched).
- Mandatory topics owned by this document: *Taking and checking a backup* · *Restoring a backup* ·
  *Updating the software* · *The September–December calendar* (§6.4) · *Rolling over to a new year* ·
  *What to do when the internet is down* · *What this product does not do*.

### 11.10 Biometric / RFID attendance — scoped out, with a forward-compatible seam

**Explicitly out of scope for v1.** Devices vary wildly, integration is per-vendor, and the domain
value over a class register is small at Cameroonian school sizes.

**But the seam is defined now**, so a device integrates later without a schema change: a documented
**CSV/JSON attendance import format** and an idempotent import endpoint.

```json
{ "source": "device", "device_id": "GATE-01", "records": [
  { "external_ref": "e7c1…", "matricule": "HA/2021/00045",
    "timestamp": "2026-09-14T07:42:11+01:00", "direction": "in", "session": "morning" } ] }
```

Rules: `external_ref` is the idempotency key (`UNIQUE(device_id, external_ref)`); records resolve to
an **enrollment** via matricule + business date (`07-students` C3); a record for a non-teaching day
or an unknown matricule is reported, never silently dropped; a device import creates or updates an
`AttendanceRegister` marked `source = device` so the §07 denominator rule still holds. The importer
follows the §5.2 contract like every other.

---

## 12. Acceptance criteria owned by this document

Every row is blocking for its phase and has a named sign-off.

| # | Criterion | Phase |
|---|---|---|
| O1 | Non-technical operator: USB stick → setup wizard step 1 in **≤ 15 minutes**, zero command line, clean Windows 11 VM | 0 |
| O2 | `opes:preflight` passes on the reference hardware and **fails, naming the setting**, when any §1.6 MySQL value is altered | 0 |
| O3 | Client TLS trust established on a clean Android phone in **≤ 3 minutes** from the `/trust` page | 0 |
| O4 | **Offline-boot CI job**: all core workflows complete with outbound network blocked; no hang, no 500 | 0 |
| O5 | Backup created, verified, checksummed; **daily verification opens exactly one file**, asserted by counter | 0 |
| O6 | **Automated monthly restore drill** passes end-to-end in ≤ 45 min; a corrupted dump is detected | 0 |
| O7 | Restore refuses on checksum mismatch with its own message; **three pre-restore copies retained**; restore audit written **outside** the database | 0 |
| O8 | Health page renders every §7.3 check with a plain-language remedy; `/up` returns 503 on any red | 0 |
| O9 | Support bundle produced in one click, ≤ 25 MB, **no secret and no PII present** (asserted by a scanner test) | 0 |
| O10 | Reference fixture (1,200 students, 7 years, ~1.2 M marks) builds deterministically; manifest hash stable | 1 |
| O11 | **Load-cost tests**: broadsheet = exactly 3 queries at 1/5/40/120 students; every view-model constructor = 0 queries | 1 |
| O12 | Import suite: 1,200 students + 1,600 guardians + 90 staff + 140-line trial balance, preview→commit in **≤ 15 min**, balanced AN entry tying to the franc; undo exact | 2 |
| O13 | **1,200 report cards in ≤ 10 minutes** on the reference hardware, resumable after a kill, UI budgets held throughout | 3 |
| O14 | Screen budgets (§8.6) met at p95 on a throttled Fast-3G profile, as a CI gate | 3 |
| O15 | Marks-grid save: **≤ 1 request and ≤ 300 ms server time for 40 marks** | 3 |
| O16 | **Upgrade test** green from each of the last three minor versions; published averages unchanged; every entry balances | every release |
| O17 | Migration rules enforced: one DDL per file, no data mutation, pre-migration backup verified, automatic rollback on integrity failure — all asserted by CI | 2 onward |
| O18 | Licence: **36 simulated offline months = exactly one network call**; expiry blocks year creation and publication and **blocks nothing else**; export works while revoked — all asserted at the Action layer | 7 |
| O19 | Rollover of the fixture in ≤ 10 min; kill-and-resume produces a byte-identical result; undo window enforced | 7 |
| O20 | Document storage: logged-out and cross-family requests both return **404**; no student path under `storage/app/public`; EXIF stripped on every uploaded image | 12 |
| O21 | `PseudonymiseSubject` preserves the ledger and enrolment skeleton, keeps the audit chain valid, and refuses inside the 10-year window without a recorded basis | 12 |
| O22 | Pilot school: one full term in parallel with paper — term published, fees reconciled to the franc, one payroll month to the franc, one unannounced restore drill | before general sale |

---

## 13. Open items

| # | Item | Status | Blocks |
|---|---|---|---|
| 1 | **Cameroonian data-protection legal review** (§9.1) — statute, regulator, minors' consent, breach deadlines, cross-border, retention floors | **NEEDS VERIFICATION**; 00-core gate 10 | Phase 12 |
| 2 | **PDF engine decision**, benchmarked against O13 and required to install unattended on a school PC | Open; 00-core gate 11 | Phase 3 |
| 3 | **SMS gateway and billing model** — per-school prepaid vs vendor-billed | Open; 00-core gate 12 | Phase 12 |
| 4 | **Medical-record retention period** post-departure (§9.4) | **NEEDS VERIFICATION** | Phase 10 |
| 5 | Whether a Cameroonian-hosted VPS of adequate quality exists at an acceptable price, and whether non-Cameroonian hosting is lawful (§9.7) | Open, depends on item 1 | VPS offering |
| 6 | WhatsApp Business API per-school onboarding cost and approval latency, if scoped in (§11.2) | Open | v2 |
| 7 | Whether the school's insurer or the ministry imposes any further record-retention floor | **NEEDS VERIFICATION** | Phase 12 |

*Nothing in this document seeds a Cameroon-specific legal value. Where one is required, it is listed
above as NEEDS VERIFICATION and the dependent feature refuses to run until configured, per 00-core
§16.*
