# Build Log

Every build round is documented here (user mandate).

## 2026-08-09 — Phase 6 (Fees) completed and green

Environment: Linux sandbox (PHP 8.4, MySQL 8.0), branch `claude/handoff-document-review-7ojy23`. Parallel fixer agents on per-agent DBs `opeschool_test_f1..f5`, final SOLO integration run on `opeschool_test`.

### Fixed this round
- **piece_no sequence series**: `ReverseJournalEntry` used series `piece_no.*` while `PostJournalEntry` used `journal_entry_piece.*` — parallel counters produced duplicate piece_no on post-then-reverse. Unified on `journal_entry_piece.*`. A divergent `function_exists`-guarded `ledgerPostEntry` copy in `tests/Feature/Accounting/LetteringTest.php` still carried the old series and resurrected the bug cross-suite (6x `uq_je_piece` 1062); all guarded helper duplicates are now byte-identical FQCN bodies, and ReversalTest gained its missing require of AccountingTestHelpers.
- **Invoice idempotency key**: `uq_invoices_issue_idem` generated column collided cancelled/draft invoices; it is now NULL unless status='issued' (MySQL uniques ignore NULL). Dev DB carries the fixed `uq_enrollment_key` column.
- **PHPStan cleanup**: level 8, 0 errors repo-wide, zero ignoreErrors, no baseline. Stale `phpstan-findings.json` removed.
- **New fee tests verified**: InvoiceTest, CreditNoteTest, AgedBalancesTest, ThirdPartyFundsTest, FeesScreensTest all green; fee+tax subset 92/92 (373 assertions).
- **Finance navigation wiring**: finance `built => true` in `Navigation.php`, removed from `placeholderRoutes()`; routes `/finance/invoices` (can:fee.view), `/finance/cashier`, `/finance/statement/{student}`; fee permissions seeded in RolePermissionSeeder; ShellTest green.
- **Cross-suite integration fixes** (first solo run: 24 failures + 42 errors, all cross-suite): PublicationTest's truncate-all reset no longer wipes migration-seeded tables (OHADA chart, journals, axes); `RecordPayment` boundary fix (no `Students\Models\Student` import — `DB::table` read + ValidationException); NumericPolicyTest ignores pint's bundled `App\Support\Prettier`/`PhpFragmentFormatter` (documented); HealthEndpointTest password probe skips product-vocabulary substrings.
- **Environment-only (not committed)**: bcmath built/installed for PHP 8.4; `.env` mysql binary paths switched from Laragon to `/usr/bin/{mysqldump,mysql}`.

### Migration status
81 migrations, all Ran on dev `opeschool` (zero pending), including all 13 fee migrations `2026_08_08_240001–240013`. `migrate:fresh --force` from scratch verified clean. Deploy steps run: migrate --force (nothing to migrate), RolePermissionSeeder, `SKIP_REMOTE_FONTS=1 npm run build`, migrate:status.

### Final numbers
- Full SOLO suite: **1183 tests — 1182 passed, 1 skipped (by design), 0 failed, 0 errors** (4427 assertions, ~6.3 min).
- PHPStan level 8: **0 errors**, zero ignoreErrors.
- ModuleBoundaryTest: 13 tests / 266 assertions green; grep audit found zero cross-module Model imports and no ledger write bypassing `Accounting\Actions\PostFromEvent`.

### Outstanding
Push + live browser verification of the cashier flow at opeschool.test must happen from the Windows box (sandbox cannot push, has no browser/vhost). Debt: consolidate guarded test-helper duplicates into shared helper files; consider a composer-level exclusion for pint's bundled App\ classes.

## 2026-08-09 — Overnight multi-phase build session (Phases 5, 7, 8, 9, 10, 11, 12, 13)

Environment: same Linux sandbox, branch `claude/handoff-document-review-7ojy23`, continuing directly from the Phase 6 INTEGRATOR commit (`d5e6994`) and the `6c9693c` planning commit ("full implementation plans for phases 5,7-13 + overnight orchestration run-sheet").

### Approach
Many parallel Claude subagents worked simultaneously, staged by dependency: each phase was broken into sub-workstreams (schema/migrations first, then domain Actions, then screens/wiring, then tests) and assigned to separate agents/worktrees, mirroring the Phase 0-6 pattern (per-agent test DBs, pre-assigned migration filenames, worktrees rather than checkouts in the live tree). Several agent runs were interrupted by session/context limits and picked back up by later "salvage" commits (`bd2d652`, `5ef42cd`, `4bc84ff` wip-salvage commits; `2ef7091` phpstan cleanup across salvaged WIP). A shared Phase 12/13 schema/dependency commit (`8088cab`, `1064e24`) was landed early so the portal and document-platform work later in the night could build on common tables, permissions, and composer packages (sanctum/dompdf/qr/barcode).

### What got built
- **Phase 5 (Finance/Procurement)**: tax configuration core, suppliers & P2P, supplier invoices/three-way match/credit notes/withholding attestations, payables settlement (payments, void cascade, retention, 4818 accrual), finance nav wiring. Migrations `2026_08_09_25xx` (33 files).
- **Phase 7 (Operations)**: rollover + licensing schema, rollover wizard engine (preflight/copy/flip/preview/undo), rollover people & money (promotion decisions, credit carry via `PostFromEvent`), licensing/entitlement gate tests. (Note: Phase 7's migrations landed under the 255xxx prefix, predating the 08-09 dated series used from Phase 5 onward; no 08-09-dated migration block is attributed to Phase 7 in the count table.)
- **Phase 8 (Academics ops)**: school calendar/timetable, attendance, welfare discipline, promotion engine, two wiring passes (permissions then routes/nav/dashboard KPIs). Migrations `26xx` (13 files).
- **Phase 9 (Assets/Inventory/Library)**: assets register, depreciation engine + disposals + investment subsidies, library (memberships/circulation/fines/lost books), built partly via salvage commits after interruptions. Migrations `27xx` (23 files).
- **Phase 10 (Welfare)**: transport, hostel, medical, visitor gate register, insurance — five workstreams, each with its own migrations, models, actions and screens. Migrations `28xx` (13 files).
- **Phase 11 (HR/Payroll)**: HR core (staff identity, contracts, employer profile, cost allocations), compensation/rates, declarations/leave — with PHPStan and fixture fixes folded in. Migrations `29xx` (18 files).
- **Phase 12 (Portals/API)**: shared portal/outbox/webhook schema, guardian portal core (scope-matrix door, invitations, activation), read-only v1 API (Sanctum, tokens, OpenAPI). Migrations `30xx` (7 files).
- **Phase 13 (Documents/Reporting)**: document rendering platform core (RenderDocument pipeline, dompdf), reporting domain utilities (amount-in-words, admission numbers, QR tokens, `/documents/verify`). Migrations `31xx` (10 files).

### Integration status
**Only Phase 6 has a recorded full-suite INTEGRATOR run (`d5e6994`, before this overnight session began).** Every phase built tonight (5, 7, 8, 9, 10, 11, 12, 13) was module/suite-tested by its own agents but has **not** been through a solo full-suite integration pass, a repo-wide PHPStan run, or a fresh `ModuleBoundaryTest` + cross-module audit since landing. HANDOVER.md's "Overnight build — phase-by-phase status" table has the sub-workstream breakdown and a known-issues list (grep-found `NEEDS-VERIFICATION`/`remaining_issues`/`TODO` markers in Fees `IssueInvoice`, Assets `CapitaliseAsset`/`ImpairAsset`, and several Guardian/HR portal Livewire screens referencing an untracked "P12-P2 build report").

### What's left
Sequential per-phase full-suite integrations (Phase 5 first, then 7 through 13 in order), resolving the flagged NEEDS-VERIFICATION items, locating/reconstructing the missing P12-P2 build report, a nav/route wiring audit for phases 9-13, and a push to remote once integration is judged acceptable (this sandbox cannot push). See HANDOVER.md "Remaining work" for the full list.
