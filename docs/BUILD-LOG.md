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
