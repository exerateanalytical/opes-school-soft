# OPES SCHOOL — Handover (2026-08-09, overnight phases 5/7-13 built, integration pending)

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
- **Phase 6 (Fees): DONE and GREEN (2026-08-09), full-suite integrated.** Commit `d5e6994` (INTEGRATOR) ran a SOLO full-suite integration pass and recorded: **1183 tests — 1182 passed, 1 skipped (by design), 0 failed** (4427 assertions, ~6.3 min). PHPStan level 8: **0 errors repo-wide, zero ignoreErrors**. All 13 fee migrations `2026_08_08_240001–240013` Ran on dev; `migrate:fresh` from scratch verified clean. ModuleBoundaryTest green + grep audit: zero cross-module Model imports, no ledger write bypassing `PostFromEvent`. This is the only phase from this session with a recorded green full-suite integration run.
- **Phases 5, 7, 8, 9, 10, 11, 12, 13: BUILT overnight (2026-08-09) by many parallel subagents, module-tested in isolation, but NONE have had a full-suite integration pass yet.** No commit after `d5e6994` contains an INTEGRATOR (or equivalent) message. Treat every phase below as "built, module/suite-tested standalone, integration pending" — not verified, not production-ready — until a dedicated integrator run is done and recorded here. See the phase table below.
- **Sidebar: every item clickable**; unbuilt-module placeholders come from `Identity\Support\Navigation::placeholderRoutes()`. Finance (Phase 6) and the Phase 8 domains (timetable/attendance/discipline/promotion) have nav wired live; other new phases may still need a wiring/nav pass verified — check each module's routes before assuming end-user reachability.
- Working branch in the sandbox: `claude/handoff-document-review-7ojy23`.

## Overnight build — phase-by-phase status (2026-08-09)

Migration series present under `database/migrations` (count by day-prefix): 25xx=33 (Phase 5), 26xx=13 (Phase 8), 27xx=23 (Phase 9), 28xx=13 (Phase 10), 29xx=18 (Phase 11), 30xx=7 (Phase 12), 31xx=10 (Phase 13).

| Phase | Sub-workstreams built (from commit log) | Full-suite integration? |
|---|---|---|
| **5 — Finance/Procurement** | F1 tax config (fiscal identity, withholding rules, prorata); F2 suppliers & P2P upstream; F3 supplier invoices, three-way match, credit notes, withholding attestations; F4 payables settlement (supplier payments, void cascade, retention, 4818 accrual, §4.9 reports); F5 finance nav wiring | **NOT integrated.** All Phase 5 commits land after the Phase 6 `d5e6994` INTEGRATOR commit; no Phase-5-scoped integrator commit exists in this log. Module-level tests only. |
| **7 — Operations (rollover/licensing)** | F1 schema (rollover + licensing, migrations 255001-255005); F2 rollover wizard engine (preflight, copy steps 1-5, flip, preview, undo); F3 rollover people & money (promotion decisions, steps 6-9, credit carry via PostFromEvent); F4 licensing tests + rollover entitlement gate | **NOT integrated.** Module/feature-suite tests only (e.g. LicenceVerificationTest, EntitlementGateTest). |
| **8 — Academics ops (timetable/attendance/discipline/promotion)** | F1 school calendar + timetable; F2 attendance (registers, exception rows, summaries, rate doors, screens); F3 welfare discipline (categories/cases/sanctions, suspension via Students door); F4 promotion engine (criteria sets, evaluate-hash-apply, wizard); F5 permissions + nav/route wiring (two passes) | **NOT integrated.** Wired into nav/dashboard KPIs, but no full-suite integrator run recorded. |
| **9 — Assets/Inventory/Library** | F1 assets register (migrations 270001-270005); F2 depreciation engine, disposals, investment subsidies (270006-270010); F4 library (memberships, circulation, fines, lost books) | **NOT integrated.** Built via salvage/recovery commits after session interruptions; module-tested only. |
| **10 — Welfare (transport/hostel/medical/visitors/insurance)** | W1 transport (routes/stops, fleet, allocations, logs, roster); W2 hostel (hostels/rooms/beds, allocations, inspections, occupancy); W3 medical (consultations + referrals, encrypted); W4 visitor gate register (check-in/out, encrypted ID ref); W5 insurance (policies, bulk enrolment, claims lifecycle, uninsured report) | **NOT integrated.** Each workstream has its own tests; no combined solo run recorded. |
| **11 — HR/Payroll** | F1 HR core (staff identity extension, contracts, employer profile, cost allocations, migrations 290001-290005); F2 rates (salvaged + verified green standalone, StaffCompensation table fix); F4 declarations/leave finish (PHPStan fixes, posting schema, fixture bugs) | **NOT integrated.** Payroll computation/CNPS/IRPP declaration correctness has only been checked at the sub-suite level so far. |
| **12 — Portals/API** | P0 schema deps (portals/outbox/webhooks 3000xx, portal roles hold exactly portal.access); P1 guardian portal core (scope-matrix door, invitations, activation); P3 read-only v1 API (Sanctum wiring, token management, OpenAPI coverage) | **NOT integrated.** Note: Guardian portal Documents/Discipline Livewire screens contain explicit "NEEDS-VERIFICATION"/"see remaining_issues in the P12-P2 build report" comments — some guardian-portal surface is known incomplete/unverified (see Known issues below). |
| **13 — Documents/Reporting** | D1 document platform core (RenderDocument pipeline, PdfRenderer/dompdf, models, shared blocks); D2 reporting domain utilities (AmountInWords, AdmissionNumber, QR token stack, `/documents/verify`) | **NOT integrated.** Built on top of the Phase 12/13 shared schema commit (`8088cab`); no full-suite run recorded. |

## Known cross-phase issues (grep audit, 2026-08-09)
- `app/Modules/Fees/Actions/IssueInvoice.php:168` — accountant configuration paths flagged "NEEDS-VERIFICATION ... BLOCK when" unresolved; treat as an open correctness question, not settled.
- `app/Modules/Assets/Actions/CapitaliseAsset.php:82` — "NEEDS-VERIFICATION discipline: never guess an account" — capitalisation account selection needs a human/accountant check before trusting it in production data.
- `app/Modules/Assets/Actions/ImpairAsset.php` — contains an unresolved `TODO`.
- `app/Modules/Guardians/Livewire/Portal/Documents.php`, `Discipline.php`, `Support/Portal/ChildFeeStatement.php`, `app/Modules/HR/Livewire/Portal/Show.php` — all explicitly defer parts of their behaviour to "remaining_issues in the P12-P2 build report" (that report is not in this repo snapshot; someone needs to track it down or reconstruct the gap list before treating the guardian/HR portals as complete).
- No `NEEDS-VERIFICATION` or `remaining_issues` markers were found in Phases 7, 9, 10, or 13 code, but absence of a marker is not proof of correctness — none of these phases have had an integrator pass either.

## Remaining work
1. **Sequential full-suite integrations, one phase at a time**, in roughly build order: Phase 5, then 7, 8, 9, 10, 11, 12, 13 — each needs a SOLO full-suite run (`opeschool_test`), a PHPStan level-8 pass, and a `ModuleBoundaryTest` + cross-module grep audit, the same process Phase 6's `d5e6994` INTEGRATOR commit followed. Record results in this file and in `docs/BUILD-LOG.md` per phase, the same way Phase 6 was recorded.
2. **Resolve the known NEEDS-VERIFICATION items** above (IssueInvoice withholding config, CapitaliseAsset account selection, ImpairAsset TODO) before relying on Phase 5/9 accounting output.
3. **Track down or reconstruct the "P12-P2 build report"** referenced by four Guardian/HR portal files — the guardian portal Documents/Discipline tabs and the HR portal Show screen are explicitly partial pending it.
4. **Nav/route wiring audit** for phases 9–13: Phase 8 got an explicit two-pass wiring commit (`2955617` permissions, `2e88744` routes/nav); confirm the newer phases (9-13) have equivalent nav wiring rather than living only as backend code + placeholder pages.
5. **Push to remote** — this session (like the Phase 6 session before it) worked in a Linux sandbox that cannot push; the accumulated commits need pushing from a box with push access once integration is judged acceptable.
6. Prior tracked debts from Phase 6 remain open (see "Tracked debts" below) and were not touched this session.
7. `RESUME-BRIEFS.md` (repo root) has the original per-agent scopes/details for reference.

## Phase 6 integration gotchas (learned 2026-08-09, keep)
- Assessment/PublicationTest's truncate-all reset was wiping MIGRATION-seeded tables (OHADA chart, journals, analytic axes) for the whole process — RefreshDatabase migrates only once. It now skips migration-seeded tables. Never truncate those in test resets.
- `RecordPayment` no longer imports `Students\Models\Student` (boundary violation); it reads the student label via `DB::table` and throws ValidationException on a missing student.
- NumericPolicyTest ignores laravel/pint's bundled `App\Support\Prettier`/`PhpFragmentFormatter` (pint's composer.json maps `App\` to its own app/; vendor objects have no path — crashed pest-arch). A composer-level exclusion would remove the hazard entirely.
- HealthEndpointTest's DB-password containment probe skips passwords that are substrings of product vocabulary (sandbox pass 'opes' ⊂ 'php artisan opes:backup:run'); re-arms automatically for real passwords.
- Full-suite results differ from per-file runs — cross-suite state leaks are real; always finish with a SOLO full run.

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
