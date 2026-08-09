# Phase 6 (Fees) — resume state after session-limit kill

Worktree: `C:\laragon\www\opeschool-worktrees\phase-6-fees`, branch `phase-6-fees` (based on b0af871).
Verified so far: **all 13 migrations (2026_08_08_240001–240013) apply cleanly** on a fresh chain (opeschool_test_f1). PHPStan level 8 reports **49 errors in 9 files** — full JSON in `phpstan-findings.json` at worktree root.

Standing rules unchanged: Laragon PHP 8.3.30 + MySQL 8.4.3 only; PHPStan level 8, ZERO ignoreErrors; module boundary test is absolute (no cross-module Model imports; PostFromEvent is the ONLY ledger door); Payment immutability observer; SequenceAllocator in transaction; exact-path `git add`; per-agent DBs `opeschool_test_f1..f5`; `function_exists`-guard Pest helpers; `class_exists`-guard concurrently-built Livewire route refs.

## F1 (structures) — resume point: "Now the tests"
- FeeItemTest + FeeStructureTest exist but have PHPStan errors (see findings JSON).
- Fix those, finish any missing structure/installment-plan coverage (CreateFeeCategory, CreateFeeItem, CreateFeeStructure, UpdateFeeStructure, SaveInstallmentPlan, ThirdPartyFund), run on opeschool_test_f1, commit exact paths.

## F2 (invoicing) — resume point: verifying F1 schemas
- GenerateInvoices.php has ~8 undefined-property errors (untyped `DB::table` rows — annotate with object shapes or fetch into typed locals). InvoiceGenerationTest has errors too.
- Missing: InvoiceTest, CreditNoteTest (AdjustInvoice, ApproveFeeAdjustment, IssueCreditNote, IssueInvoice coverage). DB: opeschool_test_f2.

## F3 (payments) — resume point: mid-helper-dedup ("guarded helper copies per file")
- PaymentTest, ReceiptTest, VoidTest exist. Finish the function_exists-guarded helper split, get suites green (RecordPayment, AllocatePayment, VoidPayment, ReissueReceipt). DB: opeschool_test_f3.

## F4 (statements/tax) — resume point: committed chunk 1 (TaxCode)
- AgedBalances.php (2 errors: list<int> cast + stdClass return shape), StudentStatement.php, ThirdPartyFundsReport.php have PHPStan errors.
- Missing: AgedBalancesTest, ThirdPartyFundsTest, StatementTest polish. DB: opeschool_test_f4.

## F5 (UI/routes) — resume point: "Now routes and the nav flip"
- Livewire\Cashier.php and Livewire\Invoices\Index.php have PHPStan errors.
- Uncommitted: Navigation.php finance flip (built => true), routes/web.php fees routes (use class_exists guards until integration), Permission.php/Role.php additions, AppServiceProvider, lang files, cashier/invoices/statement blades (committed 11f028c/3f989d0).
- Missing: 3 UI tests (cashier, invoices index, statement screens). Pixel-fidelity vs `frontend images\`. DB: opeschool_test_f5.
