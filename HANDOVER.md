# OPES SCHOOL — Handover (2026-08-08, end of Phase 6 salvage)

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
- **Phases 0–4b: DONE, verified, deployed** (1072 tests green as of last solo run, PHPStan 0 errors). Ledger invariants L1–L15 via MySQL triggers + nightly `opes:ledger:verify` at 02:45; PostingRule engine (`app/Support/Expression`), analytics, opening balances all merged.
- **Sidebar: every item clickable**; unbuilt modules serve a "Scheduled module" placeholder page generated from `Identity\Support\Navigation::placeholderRoutes()`.
- **Phase 6 (Fees): ~90% built, merged to main as WIP commit 656cdbb, schema deployed** to the dev DB (all 13 fee migrations `2026_08_08_240001–240013` Ran). NOT yet green — see "Finish Phase 6" below.
- Branch `phase-6-fees` pushed; main == phase-6-fees tip right now.

## Finish Phase 6 (the immediate task)
Baseline: 68 fee/tax tests, 62 green. Everything below is small and mechanical:
1. **Re-run the void/payment suites** — the root-cause fix is already committed: `ReverseJournalEntry` used sequence series `piece_no.*` while `PostJournalEntry` used `journal_entry_piece.*` (parallel counters → duplicate piece_no on first real post-then-reverse). Both now use `journal_entry_piece.*` (also updated `tests/Feature/Accounting/ReversalTest.php` fixture, and int-cast in `tests/Feature/Fees/VoidTest.php:201`). Run: `DB_DATABASE=opeschool_test_f3 php artisan migrate:fresh --force` then `php artisan test tests/Feature/Fees/PaymentTest.php tests/Feature/Fees/ReceiptTest.php tests/Feature/Fees/VoidTest.php tests/Feature/Accounting/ReversalTest.php` (same DB env var).
2. **Invoice idempotency migration defect**: `2026_08_08_240005_create_invoices_table.php` — unique key `uq_invoices_issue_idem` collides cancelled/draft invoices (duplicate '11-0-0' in StatementTest). Make the generated idempotency column NULL unless status='issued' (MySQL uniques ignore NULL). Edit the migration in place (unreleased), then migrate:fresh test DBs AND `php artisan migrate:fresh` is NOT for the dev DB — for dev, write a follow-up migration or accept a rebuild since no production data exists.
3. **PHPStan**: `phpstan-findings.json` (repo root) lists the original 49; the Livewire screens are fixed (0 errors). Remaining: `AgedBalances.php` (list<int> cast at :75, return-shape at :144), `StudentStatement.php`, `ThirdPartyFundsReport.php`, `GenerateInvoices.php` (~8 untyped `DB::table` row properties — add object-shape `@var`), plus FeeItemTest/FeeStructureTest were fixed by F1 (committed), InvoiceGenerationTest may still have some.
4. **New test files exist but are unverified**: InvoiceTest, CreditNoteTest, AgedBalancesTest, ThirdPartyFundsTest, FeesScreensTest — run each, fix, and check helper-name collisions across files (must be function_exists-guarded + unique).
5. **F5 wiring incomplete**: `Navigation.php` finance flip and fees routes in `routes/web.php` are partially done on the branch — verify: finance item `built => true` pointing at the invoices screen, 'finance' REMOVED from `placeholderRoutes()`, routes `/finance/invoices` (can:fee.view), `/finance/cashier`, `/finance/statement/{student}`; Permission/Role fee permissions seeded in RolePermissionSeeder; `tests/Feature/Ui/ShellTest.php` must stay green after the flip.
6. **When all green**: SOLO full suite (`php artisan test` on `opeschool_test`, nothing else running), PHPStan 0 across the repo, `composer deploy`, push, then verify the cashier flow live at opeschool.test (demo login → Finance).
7. `RESUME-BRIEFS.md` (repo root) has the original per-agent scopes/details.

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
