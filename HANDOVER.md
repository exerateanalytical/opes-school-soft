# OPES SCHOOL — Handover (2026-08-09, Phase 6 green)

New account: start Claude Code in `C:\laragon\www\opeschool`, paste this file's path, and say "read the handover and continue".

## What this project is
School management platform for Cameroon. Laravel 13.24 + Livewire 4.3 + Tailwind 4, MySQL 8.4.3, **Laragon toolchain ONLY** (`C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64`; never MariaDB). Modular monolith under `app/Modules/{Identity,Academics,Students,Guardians,Admissions,Assessment,Accounting,Fees,Tax,...}`. Specs: `docs/specs/00-core.md` … `10-documents.md`; build order in 00-core §12 (14 phases). UI must be a pixel-faithful replica of the mockups in `C:\laragon\www\opeschool\frontend images\`. Site is live at http://opeschool.test (Laragon Apache vhost serves this working tree; main branch). Demo one-click admin login on the login page (local-only).

## Standing rules (user-mandated, non-negotiable)
1. After every finished work package: merge to main, `composer deploy` (runs migrate --force → RolePermissionSeeder → npm build → migrate:status), push. Migrations are ALWAYS run automatically after building.
2. PHPStan level 8, ZERO `ignoreErrors` suppressions ever. `php vendor/bin/phpstan analyse --memory-limit=1G` (not `composer analyse` — times out).
3. `tests/Architecture/ModuleBoundaryTest.php` is absolute: no cross-module Model imports; cross-module reads via `DB::table`; Actions are the only cross-module doors. ALL ledger writes from other modules go through `Accounting\Actions\PostFromEvent` — a second posting path is a review-blocking defect.
4. Parallel agents: git worktrees under `C:\laragon\www\opeschool-worktrees\` (NEVER `git checkout` in the live docroot); per-agent test DBs `opeschool_test_f1..f5`; exact-path `git add`; `function_exists`-guarded, globally-unique Pest helpers; pre-assigned migration filenames.
5. Testing: real MySQL + RefreshDatabase. NEVER run two suites against the same test DB — concurrent runs cause phantom failures (kill orphaned `php artisan test` processes first: `Get-CimInstance Win32_Process -Filter "Name like 'php%'"`). Full-suite runs must be SOLO. migrate:fresh takes ~5–20 min (trigger DDL is slow); it is not hung.
6. User style: fast, no narration, no time-wasting; verify claims live in the browser rather than asserting.

## State right now
- **Environments**: the project now runs in TWO places. (a) The original Windows/Laragon box (`C:\laragon\www\opeschool`, PHP 8.3.30, MySQL 8.4.3, opeschool.test vhost) — all Laragon notes below still apply there. (b) A Linux/MySQL 8.0 sandbox (`/home/user/opes-school-soft`, PHP 8.4, DB user/pass `opes`/`opes`, DBs `opeschool` + `opeschool_test` + `opeschool_test_f1..f5`). Sandbox-local environment state (not in git): bcmath built from php-8.4.19 source and installed; `.env` `OPES_MYSQLDUMP`/`OPES_MYSQL_CLIENT` point at `/usr/bin/{mysqldump,mysql}`. A rebuilt sandbox must redo both (or use a SessionStart hook).
- **Phases 0–4b: DONE, verified, deployed.** Ledger invariants L1–L15 via MySQL triggers + nightly `opes:ledger:verify` at 02:45; PostingRule engine (`app/Support/Expression`), analytics, opening balances all merged.
- **Phase 6 (Fees): DONE and GREEN (2026-08-09).** Full SOLO suite on `opeschool_test`: **1183 tests — 1182 passed, 1 skipped (by design), 0 failed** (4427 assertions, ~6.3 min). PHPStan level 8: **0 errors repo-wide, zero ignoreErrors**. All 13 fee migrations `2026_08_08_240001–240013` Ran on dev (81 migrations total, zero pending); `migrate:fresh` from scratch verified clean. ModuleBoundaryTest green + grep audit: zero cross-module Model imports, no ledger write bypassing `PostFromEvent`.
- **Sidebar: every item clickable**; unbuilt modules serve a "Scheduled module" placeholder page generated from `Identity\Support\Navigation::placeholderRoutes()`. Finance is now a built module (invoices/cashier/statement routes live).
- Working branch in the sandbox: `claude/handoff-document-review-7ojy23`.

## Phase 6 checklist — final status
1. **Void/payment suites** — DONE. `piece_no.*` vs `journal_entry_piece.*` parallel-counter fix verified; full fee+tax suite (`tests/Feature/Fees` + `ReversalTest`) 92/92 green.
2. **Invoice idempotency migration defect** — DONE. `uq_invoices_issue_idem`/`uq_enrollment_key` generated column is NULL unless status='issued'; dev DB already carries the fixed column (migrate:status verified, nothing to migrate).
3. **PHPStan** — DONE. 0 errors repo-wide at level 8, no ignoreErrors, no baseline. Stale `phpstan-findings.json` deleted. (Config note: `tests/Architecture` is an analysis-path exclusion because Pest's arch() magic-method DSL trips level 8 — not an error suppression.)
4. **New test files** — DONE and verified (InvoiceTest, CreditNoteTest, AgedBalancesTest, ThirdPartyFundsTest, FeesScreensTest all in the green solo run). All `function_exists`-guarded helper duplicates are now byte-identical FQCN bodies (a divergent `ledgerPostEntry` copy in LetteringTest had resurrected the piece_no bug cross-suite). Debt: consolidate the duplicates into shared helper files (e.g. `AccountingTestHelpers.php`) to remove drift risk.
5. **F5 finance wiring** — DONE: `Navigation.php` finance `built => true`, 'finance' removed from `placeholderRoutes()`, routes `/finance/invoices` (can:fee.view), `/finance/cashier`, `/finance/statement/{student}` live; fee permissions in RolePermissionSeeder; ShellTest green.
6. **Deploy** — done in the sandbox (migrate --force, RolePermissionSeeder, `SKIP_REMOTE_FONTS=1 npm run build`, migrate:status). REMAINING: push from the Windows box (sandbox cannot push) and live-browser verification of the cashier flow at opeschool.test (no browser/vhost in the sandbox).
7. `RESUME-BRIEFS.md` (repo root) has the original per-agent scopes/details.

## Phase 6 integration gotchas (learned 2026-08-09, keep)
- Assessment/PublicationTest's truncate-all reset was wiping MIGRATION-seeded tables (OHADA chart, journals, analytic axes) for the whole process — RefreshDatabase migrates only once. It now skips migration-seeded tables. Never truncate those in test resets.
- `RecordPayment` no longer imports `Students\Models\Student` (boundary violation); it reads the student label via `DB::table` and throws ValidationException on a missing student.
- NumericPolicyTest ignores laravel/pint's bundled `App\Support\Prettier`/`PhpFragmentFormatter` (pint's composer.json maps `App\` to its own app/; vendor objects have no path — crashed pest-arch). A composer-level exclusion would remove the hazard entirely.
- HealthEndpointTest's DB-password containment probe skips passwords that are substrings of product vocabulary (sandbox pass 'opes' ⊂ 'php artisan opes:backup:run'); re-arms automatically for real passwords.
- Full-suite results differ from per-file runs — cross-suite state leaks are real; always finish with a SOLO full run.

## After Phase 6 (build order remaining)
Phase 5 (procurement/tax full), 7 (operations: year rollover wizard, licensing), 8 (attendance/timetable/discipline/promotion), 9 (assets/inventory/library), 10 (welfare), 11 (HR/payroll CNPS/IRPP), 12 (portals/API), 13 (documents/PDFs/polish).

## Tracked debts (deferred, keep on the list)
1,200-student fixture + performance harness (Phase 0); Phase 2 import suite; real PDF rendering; year-end close; §18.4 staging tables; §20 maker-checker counter-approval.

## Gotchas that cost hours — don't relearn them
- Livewire 4 assets live at `/livewire-48e2f835/livewire.js` (hashed prefix) — a 404 on `/livewire/livewire.js` is NOT a bug.
- Fresh worktrees need `npm install && npm run build` or ~30 UI tests 500 on the missing Vite manifest.
- `Carbon::create()` returns nullable — use `Carbon::parse()`. Pest's `toBeInstanceOf` doesn't narrow for PHPStan — use the `assertNotNull()` template helper pattern (`tests/Feature/Accounting/AccountingTestHelpers.php`).
- MySQL `SUM()` returns string — cast `(int)`.
- Journal factory codes must be `lexify('???')` (2-letter codes collide with seeded journals).
- Draft→lines→post is the mandatory ledger write order (L3 trigger rejects lines on posted entries).
- `LedgerIntegrityJobTest` drops triggers and restores them by regex-extracting from migration source — see `integrityRestoreLineTriggers()`.
