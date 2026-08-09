# Phase 5 Implementation Plan — Procurement & Tax Full (`docs/specs/03-tax-procurement.md`)

## 0. Context and constraints confirmed from the repo

- Phase 4 already shipped the Tax kernel: `tax_codes` table (`2026_08_08_240012`), `App\Modules\Tax\Models\TaxCode`, `App\Modules\Tax\Actions\ConfigureTaxCode` (gated on `ledger.configure`, append-only versioning), `App\Modules\Tax\Domain\TaxType`. Phase 5 builds on these — do not recreate them.
- `app/Modules/Procurement` and `app/Modules/Tax` scaffolds exist but are (nearly) empty.
- `SchoolProfile` is a key-value `Setting` store only — there is **no** `school_profiles` row table. The spec's §2 "columns on the singleton row" therefore requires a new singleton table (see F1).
- Ledger writes: **only** via `App\Modules\Accounting\Actions\PostFromEvent` (pattern: `Fees\Actions\RecordPayment`). Posting rules are data (`PostingRule`/`PostingRuleLine`); Phase 5 adds new event names, not a second posting path.
- Cross-module reads: `DB::table(...)` only; cross-module writes: Actions only (`tests/Architecture/ModuleBoundaryTest.php` enforces).
- Migration convention: `2026_08_0X_2NNNNN_*`; Fees used the `240001–240013` block. Phase 5 pre-assigns the `2026_08_09_250001–250031` block below.
- Sequences via the existing `sequences` table, row-locked; series `piece_no.*` lesson from Phase 6 applies — each document series gets ONE series name.
- Nothing marked NEEDS VERIFICATION in the spec is seeded — modules ship empty-and-blocking with a "configure with your accountant" state.
- Nav: flip `procurement`-relevant keys in `Identity\Support\Navigation`. There is no `procurement` key today; plan adds one (see F5) and removes it from `placeholderRoutes()` by construction (`built => true`).

Sequencing dependency: **F1 and F2 schema must merge before F3/F4 start their FK-bearing work; F5 wires last.** Migration timestamps are pre-assigned so files interleave correctly regardless of commit order.

---

## 1. Migrations (pre-assigned filenames)

All under `database/migrations/`, prefix `2026_08_09_`:

### Block A — Tax configuration (Agent F1: 250001–250006)
| File | Contents |
|---|---|
| `250001_create_fiscal_identity_table.php` | Singleton `fiscal_identities` (CHECK id=1): all §2.1 columns (`legal_name`, `legal_form`, `niu` UNIQUE `as_cs`, `rccm_*`, `tax_centre_*`, `tax_regime`, `is_tva_registered`, `ministry_accreditation_*`, `fiscal_year_end_month/day` CHECK-pinned 12/31, `fiscal_identity_confirmed_by/at`). BEFORE UPDATE trigger: `niu` immutable once confirmed (correction Action bypass via session flag not possible in trigger — enforce immutability in model observer + Action instead; trigger only guards `id=1`). |
| `250002_create_tax_settings_table.php` | Singleton `tax_settings`: `withholding_recognition` (nullable = unconfigured/blocking), `prorata_rounding` (nullable), `confirmed_by/at`. |
| `250003_create_withholding_rules_tables.php` | `withholding_rules` (§6.2, `UNIQUE(code, effective_from)`, nullable `base` = cannot activate, `supplier_condition` JSON, `confirmed_by/at`), `withholding_profiles`, `withholding_profile_rules` (`UNIQUE(profile_id, sequence)`). |
| `250004_create_vat_proratas_table.php` | `vat_proratas` (`UNIQUE(fiscal_year_id, basis)`), plus `vat_prorata_regularisations` (nullable `asset_id`, `regularisation_type` — schema only, §5.4.4). |
| `250005_create_tax_obligations_table.php` | `tax_obligations` reference data + `tax_declaration_types` reference table (§7.1/§7.4). Archive-flag, no seed of rates; DSF due-rule rows CAN be seeded (verified §7.5 dates) as `due_rule` data. |
| `250006_add_dsf_columns_to_fiscal_years_table.php` | `dsf_filed_at`, `dsf_reference`, `dsf_declaration_id` (FK→tax_declarations added later as follow-up index? — no: make it a plain BIGINT here with the FK added in 250026 after `tax_declarations` exists, or simply place this migration content in 250026; **decision: keep column creation here without FK, FK constraint added in 250026**), `dsf_filed_by`. |

### Block B — Suppliers & P2P upstream (Agent F2: 250007–250014)
| File | Contents |
|---|---|
| `250007_create_supplier_categories_table.php` | §3.4. |
| `250008_create_suppliers_table.php` | §3.1 full column set, encrypted bank/momo columns + `_bidx` blind indexes, `UNIQUE(code)`, `UNIQUE(niu)`, indexes per spec. FK to `supplier_categories`, `withholding_profiles` (Block A), `tax_codes`, `chart_of_accounts`. |
| `250009_create_procurement_settings_table.php` | Singleton: `requisition_required_above`, `po_required_above`, `receipt_required_for_goods`, `over_receipt_tolerance_bp`, `price_tolerance_bp`, `price_tolerance_absolute`, `quantity_tolerance_bp`, `budget_enforcement`; plus `approval_thresholds` table (`min_amount`, `max_amount`, `required_role`, `sequence`). |
| `250010_create_purchase_requisitions_tables.php` | Header + lines (§4.1); BEFORE DELETE trigger rejecting non-draft delete. |
| `250011_create_purchase_orders_tables.php` | Header + lines + `purchase_order_amendments` (`UNIQUE(po_id, amendment_no)`); draft-only-delete trigger; `version` optimistic lock. |
| `250012_create_goods_receipts_tables.php` | Header + lines (§4.3); draft-only-delete trigger. |
| `250013_create_purchase_order_line_analytics_table.php` | Analytic pivot for PO lines (mirrors `journal_entry_line_analytics`). |
| `250014_seed_procurement_sequences.php` | Insert sequence rows for series `REQ`, `BC`, `BR`, `FF`, `AVF`, `PF`, `ATT` (gaps-permitted config, mirroring how existing sequences were seeded). |

### Block C — Supplier invoices, credit notes, attestations (Agent F3: 250015–250019)
| File | Contents |
|---|---|
| `250015_create_supplier_invoices_tables.php` | Header (§4.5) `UNIQUE(supplier_id, supplier_invoice_no)` **at the database**, `UNIQUE(internal_no)`, match/withholding columns, `withholding_unresolved` flag; lines with `tax_code_id`+`tax_rate_bp_applied` snapshot, `deductible/non_deductible_tax_amount`, withholding columns, `UNIQUE(supplier_invoice_id, line_no)`; BEFORE DELETE trigger on header (draft-only cascade). |
| `250016_create_supplier_invoice_line_analytics_table.php` | Analytic pivot summing to `amount_ht`. |
| `250017_create_supplier_credit_notes_tables.php` | §4.8 header + lines, series `AVF`, `original_invoice_id` nullable RESTRICT. |
| `250018_create_withholding_attestations_table.php` | §6.6 with `CHECK ((supplier_payment_id IS NULL) <> (supplier_invoice_id IS NULL))` — note `supplier_payments` (Block D, 250020) has a later timestamp, so create the `supplier_payment_id` column WITHOUT its FK here; **F4's 250022 adds that FK**. `replaced_by_attestation_id` UNIQUE self-FK. |
| `250019_add_tax_code_defaults_to_catalogs.php` | Adds nullable `tax_code_id` defaults where §5.6 requires and the table exists today: `fee_items` already has it (verify — if present, no-op that part). Skip Assets/Inventory tables (Phase 9) — document as deferred debt. |

### Block D — Payments & payables (Agent F4: 250020–250024)
| File | Contents |
|---|---|
| `250020_create_supplier_payments_table.php` | §4.7 header incl. `clearing_state`, `fee_*` columns, `version`, `idempotency_key`. |
| `250021_create_supplier_payment_allocations_table.php` | `UNIQUE(supplier_payment_id, supplier_invoice_id)`, `letter_code`. |
| `250022_create_supplier_payment_voids_and_batches_table.php` | `supplier_payment_voids` (`payment_id` UNIQUE, reason mandatory, `reversal_journal_entry_id`), `supplier_payment_batches`; **also adds the deferred FK `withholding_attestations.supplier_payment_id → supplier_payments`**. |
| `250023_create_purchase_accruals_table.php` | 4818 working-paper table: `purchase_accruals` (fiscal_year_id, goods_receipt_line_id, valued amount, `journal_entry_id`, `reversal_journal_entry_id`) for the cut-off run (§3.3). |
| `250024_seed_procurement_posting_events.php` | No rate seeding — but the `PostingRule` engine is data-driven; this migration seeds NOTHING monetary. If the existing engine requires registered event names, register: `supplier.invoice.posted`, `supplier.invoice.cancelled`, `supplier.credit_note.issued`, `supplier.payment.made`, `supplier.payment.voided`, `supplier.retention.withheld`, `supplier.retention.released`, `withholding.recognised`, `withholding.remitted`, `purchase.accrual.recognised`, `purchase.accrual.reversed`. (Check `posting_rules` — if events are free strings, drop this migration and keep the list in a Domain enum instead; F4 confirms at build time and renumbers is NOT allowed — leave the file as a no-op enum-doc migration if unused.) |

### Block E — Declarations & wiring (Agent F5: 250025–250031)
| File | Contents |
|---|---|
| `250025_create_tax_declarations_tables.php` | `tax_declarations` (`UNIQUE(declaration_type, period_year, period_month)`, `inputs_hash`, `amends_declaration_id` UNIQUE), `tax_declaration_lines`, `tax_declaration_entries` pivot, `tax_credits`. |
| `250026_add_dsf_fk_to_fiscal_years.php` | FK `fiscal_years.dsf_declaration_id → tax_declarations.id` (UNIQUE), completing 250006. |
| `250027_create_tax_declaration_events.php` | Event names `tax.declaration.generated`/`.filed`, `tax.tva.declared`, `tax.tva.settled`, `tax.credit.carried_forward` — same caveat as 250024 (no-op if events are free strings). |
| `250028_add_procurement_indexes.php` | Cross-cutting reporting indexes: `supplier_invoices(due_date, status)`, `supplier_invoices(supplier_id, status)`, `withholding_attestations(period_year, period_month, status)`. |
| `250029`–`250031` | **Reserved spares** for defects found mid-build (any agent may claim one via a note in `RESUME-BRIEFS.md`; never renumber). |

---

## 2. Models (all `final`, no SoftDeletes, `$guarded` per repo convention, PHPStan level 8 property docblocks)

- `app/Modules/Tax/Models/`: `FiscalIdentity`, `TaxSettings`, `WithholdingRule`, `WithholdingProfile`, `WithholdingProfileRule`, `VatProrata`, `VatProrataRegularisation`, `TaxObligation`, `TaxDeclarationType`, `TaxDeclaration`, `TaxDeclarationLine`, `TaxDeclarationEntry`, `TaxCredit`, `WithholdingAttestation`.
- `app/Modules/Procurement/Models/`: `Supplier`, `SupplierCategory`, `ProcurementSettings`, `ApprovalThreshold`, `PurchaseRequisition(+Line)`, `PurchaseOrder(+Line, Amendment)`, `GoodsReceipt(+Line)`, `SupplierInvoice(+Line)`, `SupplierCreditNote(+Line)`, `SupplierPayment`, `SupplierPaymentAllocation`, `SupplierPaymentVoid`, `SupplierPaymentBatch`, `PurchaseAccrual`.
- `app/Modules/Tax/Domain/` enums: `WithholdingType`, `WithholdingBase`, `TaxDirection` (if missing), `DeclarationStatus`, `DeclarationTypeCode`, `ProrataBasis`, `AttestationStatus`, `TaxRegime`, `TaxCentreType`, `LegalForm`.
- `app/Modules/Procurement/Domain/` enums: `SupplierType`, `NiuStatus`, `RegimeFiscal`, `RequisitionStatus`, `PurchaseOrderStatus`, `GoodsReceiptStatus`, `SupplierInvoiceStatus`, `MatchStatus`, `MatchMode`, `MatchExceptionReason`, `SupplierPaymentStatus`, `CreditNoteReasonType` (procurement variant — name it `SupplierCreditNoteReasonType` to avoid clashing with Fees), `BudgetEnforcement`.
- Model observers: `TaxCode` append-only observer already exists via Action; add observers forbidding in-place edit of referenced `WithholdingRule`, issued `WithholdingAttestation`, approved `PurchaseOrder`; extend the global accounting-record no-hard-delete observer scope to the §9 RESTRICT list.

## 3. Actions (the cross-module doors)

### Tax (`app/Modules/Tax/Actions/`)
- `ConfirmFiscalIdentity` / `CorrectFiscalIdentity` (permission-gated, reason + document, emits `school.fiscal_identity.changed`).
- `AssertDocumentIdentityComplete` (§2.2 inv. 5 hard gate — called by Fees/Procurement print paths).
- `ConfigureWithholdingRule`, `ConfigureWithholdingProfile` (append-only discipline mirroring `ConfigureTaxCode`; equal-top-priority rejection at save).
- `ResolveTaxCodeFor(subject, date)` + batch `forLines()`; `ComputeLineTax(amount_ht, tax_code_id, date, direction)` returning `{tax_amount, deductible, non_deductible}` using confirmed `VatProrata` (refuse when unconfirmed/unset — empty-seed refusal §11.16).
- `EvaluateTaxCode` (exemption gating §5.2 vs accreditation).
- `ResolveWithholding(supplier_id, lines, date)` (§6.4 algorithm, batch).
- `ComputeVatProrata`, `ConfirmVatProrata`, `RegulariseVatProrata` (posts via `PostFromEvent`, event `tax.tva.declared`-family; stores working paper).
- `GenerateTvaDeclaration`, `GenerateWithholdingDeclaration` (§7.2/§7.3; advisory lock, soft-lock precondition, `inputs_hash`, 447 reconciliation blocking), `FileTaxDeclaration` (re-verify hash, mandatory `external_reference`, emits `tax.declaration.filed` → PostFromEvent), `AmendTaxDeclaration`.
- `GenerateDsf` (mapper over `dsf_line_code`; unmapped-account block; pre-filing checklist), `RecordDsfFiling` (sets `fiscal_years.dsf_*`; **`ReopenFiscalYear` in Accounting gets the unconditional DSF block — a one-line guard added by F5 with Accounting-owner coordination**).
- `IssueWithholdingAttestation`, `CancelWithholdingAttestation`, `ReplaceWithholdingAttestation`.
- `ComplianceCalendar` (query action: upcoming obligations, T−15/7/1 alerts).

### Procurement (`app/Modules/Procurement/Actions/`)
- `SaveSupplier` (duplicate similarity check §3.2, hard block on niu/bidx match, override permission), `ArchiveSupplier`, `SaveSupplierCategory`, `SaveProcurementSettings`.
- `SubmitRequisition`, `ApproveRequisition` (budget check, requester≠approver), `RejectRequisition`, `CancelRequisition`.
- `CreatePurchaseOrder` (from requisition or blank), `ApprovePurchaseOrder` (threshold routing, SoD), `SendPurchaseOrder`, `AmendPurchaseOrder`, `ClosePurchaseOrder`, `CancelPurchaseOrder`.
- `ConfirmGoodsReceipt` (FOR UPDATE on PO lines, `qty_received` tolerance, emits `procurement.goods.received`; Inventory/Assets calls stubbed behind interface no-ops until Phase 9 — record intent rows in `purchase_accruals` input set only).
- `CaptureSupplierInvoice`, `MatchSupplierInvoice` (§4.4 modes/tolerances, per-line match state), `OverrideMatchException`, `ApproveSupplierInvoice` (SoD: creator≠approver; withholding_unresolved gate), `PostSupplierInvoice` (computes TVA split via `Tax::ComputeLineTax`, withholding via `Tax::ResolveWithholding` when `on_invoice`, capex 481-family invariant, mixed-invoice payable split, retention to 4817 — all through **one** `PostFromEvent` call with event `supplier.invoice.posted`), `CancelSupplierInvoice`.
- `IssueSupplierCreditNote` (reversal posting + TVA adjustment flag for the declaration period).
- `RecordSupplierPayment` (FOR UPDATE on invoices, allocation under lock, withholding when `on_payment`, fees to 6317, lettering via `Accounting\Actions\LetterEntries`, triggers attestation issue), `ApproveSupplierPayment` (SoD), `VoidSupplierPayment` (reverse allocations, unletter, cancel attestation, reversal in earliest open period — mirror `Fees\VoidPayment`), `ExportPaymentBatch`, `ReleaseRetention`.
- Reports (query actions): `AgedPayables` (unlettered items on 401/481 per §4.9 — ledger-sourced, mirror `Fees\AgedBalances`), `SupplierStatement`, `OpenCommitments`, `ReceiptNotInvoiced`, `DuplicateRisk`, `WithholdingRegister`, `RunYearEndPurchaseAccrual` (4818 + first-day reversal).

## 4. Livewire screens + views (`resources/views/livewire/{procurement,tax}/…`)

| Component | Route | Permission |
|---|---|---|
| `Tax\Livewire\FiscalIdentity` | `/settings/fiscal-identity` | `setting.manage` (or `ledger.configure`) |
| `Tax\Livewire\TaxConfiguration` (tabs: tax codes, withholding rules/profiles, prorata, obligations; "not configured — blocks use" badges) | `/settings/tax` | `ledger.configure` |
| `Tax\Livewire\TaxDashboard` | `/tax` | new `tax.view` |
| `Tax\Livewire\Declarations\Index` + `Show` | `/tax/declarations`, `/tax/declarations/{declaration}` | `tax.declare` |
| `Procurement\Livewire\Suppliers\Index` + `Show` (tabbed profile) | `/procurement/suppliers`, `/{supplier}` | `procurement.view` |
| `Procurement\Livewire\Requisitions\Index` (+ approve queue) | `/procurement/requisitions` | `procurement.view` |
| `Procurement\Livewire\PurchaseOrders\Index` + `Edit` (keyboard-first line grid, Alpine-local, ≤1 request/save) | `/procurement/orders` | `procurement.view` |
| `Procurement\Livewire\GoodsReceipts\Index` | `/procurement/receipts` | `procurement.view` |
| `Procurement\Livewire\SupplierInvoices\Index` + `Capture` (match panel, tax panel) | `/procurement/invoices` | `procurement.invoice.view` |
| `Procurement\Livewire\Payments\Index` + `Pay` (allocate → withholding preview → pay) | `/procurement/payments` | `procurement.payment.record` |
| `Procurement\Livewire\PayablesDashboard` | `/procurement` (index redirect) | `procurement.view` |

## 5. Routes, navigation, permissions

- `routes/web.php`: new group mirroring the Fees block — `Route::redirect('/procurement', '/procurement/suppliers')` etc., each `->middleware('can:…')->name('procurement.*'/'tax.*')`.
- `Navigation.php`: add `['key' => 'procurement', 'route' => '/procurement/suppliers', 'permission' => Permission::ProcurementView, 'enabled' => true, 'built' => true]` in the finance group; add a `tax` item or hang the Tax dashboard under settings/finance per `09-ui.md`; nav lang keys added to both `lang/en` and `lang/fr` nav files; `ShellTest` updated.
- `Permission.php` enum additions: `ProcurementView`, `ProcurementSupplierManage`, `ProcurementSupplierOverrideDuplicate`, `ProcurementRequisitionApprove`, `ProcurementOrderApprove`, `ProcurementInvoiceCreate`, `ProcurementInvoiceApprove`, `ProcurementInvoiceApproveUnmatched`, `ProcurementInvoiceOverrideMatch`, `ProcurementInvoiceWaiveWithholding`, `ProcurementPaymentRecord`, `ProcurementPaymentApprove`, `ProcurementPaymentVoid`, `TaxView`, `TaxDeclare`, `TaxFile`, `FiscalIdentityCorrect`.
- `RolePermissionSeeder`: map to existing roles (Bursar/Principal/Admin patterns already used for fee.*); SoD pairs deliberately split across roles.

## 6. Agent scopes (disjoint files, test DBs `opeschool_test_f1..f5`)

Order of merge: **F1 and F2 first (independent of each other), then F3, then F4, F5 last.** F3/F4 may start Actions against the committed F1/F2 migrations.

### Agent F1 — Tax configuration core (`opeschool_test_f1`)
Owns: migrations 250001–250006; Tax models/enums for identity, settings, withholding rules, prorata, obligations; Actions `ConfirmFiscalIdentity`, `CorrectFiscalIdentity`, `AssertDocumentIdentityComplete`, `ConfigureWithholdingRule/Profile`, `ComputeLineTax`, `ResolveTaxCodeFor`, `EvaluateTaxCode`, `ResolveWithholding`, `ComputeVatProrata`, `ConfirmVatProrata`; Livewire `FiscalIdentity`, `TaxConfiguration` + views.
Tests: `tests/Feature/Tax/FiscalIdentityTest.php`, `WithholdingRuleTest.php` (10-yr sweep, priority ties, base-unset block), `ProrataTest.php` (§5.4 worked example to the franc, `Money::allocate` conservation), `TaxComputationTest.php` (empty-seed refusal, exemption gate §11.13/16), `TaxConfigurationScreenTest.php`. Helpers prefix `f1Tax…`, `function_exists`-guarded.

### Agent F2 — Suppliers & P2P upstream (`opeschool_test_f2`)
Owns: migrations 250007–250014; Procurement models/enums for supplier, settings, requisition, PO, receipt; Actions `SaveSupplier` … `ConfirmGoodsReceipt`; Livewire `Suppliers\*`, `Requisitions\Index`, `PurchaseOrders\*`, `GoodsReceipts\Index` + views.
Tests: `tests/Feature/Procurement/SupplierTest.php` (duplicate block/override), `RequisitionTest.php` (SoD, budget warn/block, draft-only delete trigger), `PurchaseOrderTest.php` (immutability→amendment, thresholds, no-ledger-posting assertion), `GoodsReceiptTest.php` (tolerance, discrepancy, concurrency FOR UPDATE), `ProcurementScreensTest.php`. Helpers `f2Proc…`.

### Agent F3 — Supplier invoices, matching, attestations (`opeschool_test_f3`)
Owns: migrations 250015–250019; `SupplierInvoice(+Line)`, `SupplierCreditNote(+Line)`, `WithholdingAttestation` models; Actions `CaptureSupplierInvoice`, `MatchSupplierInvoice`, `OverrideMatchException`, `ApproveSupplierInvoice`, `PostSupplierInvoice`, `CancelSupplierInvoice`, `IssueSupplierCreditNote`, `Issue/Cancel/ReplaceWithholdingAttestation`; Livewire `SupplierInvoices\*` + views.
Tests: `tests/Feature/Procurement/SupplierInvoiceTest.php` (money conservation property, duplicate `(supplier_id, supplier_invoice_no)` fails at DB, capex 481 invariant, mixed-invoice split, closed-period forward-post), `ThreeWayMatchTest.php` (§4.4 worked example exact), `WithholdingResolutionTest.php` (§6.4 worked example, unresolved flag blocks approval, expired exemption), `AttestationTest.php` (immutability, replacement chain), `SupplierCreditNoteTest.php`, `SupplierInvoicePostingTest.php` (single PostFromEvent path, prorata split posting to the franc). Helpers `f3Inv…`.

### Agent F4 — Payments, payables, accruals (`opeschool_test_f4`)
Owns: migrations 250020–250024; payment models; Actions `RecordSupplierPayment`, `ApproveSupplierPayment`, `VoidSupplierPayment`, `ExportPaymentBatch`, `ReleaseRetention`, all §4.9 report actions, `RunYearEndPurchaseAccrual`; Livewire `Payments\*`, `PayablesDashboard` + views.
Tests: `tests/Feature/Procurement/SupplierPaymentTest.php` (allocation lock race, withholding on_payment posting §6.4 to the franc, fees to 6317), `PaymentVoidTest.php` (§11.9 full cascade incl. attestation cancel, SoD recorder≠voider), `AgedPayablesTest.php` (due_date axis, ledger-sourced), `SupplierStatementTest.php` (auxiliary reconciliation Σ = 401+481+4817+4818), `PurchaseAccrualTest.php` (4818 + reversal), `RetentionTest.php`. Helpers `f4Pay…`.

### Agent F5 — Declarations, DSF, wiring (`opeschool_test_f5`)
Owns: migrations 250025–250028; declaration models; Actions `GenerateTvaDeclaration`, `GenerateWithholdingDeclaration`, `FileTaxDeclaration`, `AmendTaxDeclaration`, `GenerateDsf`, `RecordDsfFiling`, `RegulariseVatProrata`, `ComplianceCalendar`; the DSF guard inside `Accounting` (`ReopenFiscalYear` — **the only cross-scope file touch in the whole phase; F5 owns it exclusively**); Livewire `TaxDashboard`, `Declarations\*`; ALL of: `routes/web.php` block, `Navigation.php`, `Permission.php`, `RolePermissionSeeder.php`, lang nav keys, `ShellTest` update.
Tests: `tests/Feature/Tax/TvaDeclarationTest.php` (inputs_hash re-verify, soft-lock gate, credit carry-forward, one-per-period UNIQUE), `WithholdingDeclarationTest.php` (attestation↔declaration↔447 reconcile §11.8), `DsfTest.php` (reopen block §11.10 — no flag overrides; unmapped account named §11.11), `ComplianceCalendarTest.php`, `tests/Feature/Ui/ProcurementNavTest.php`. Helpers `f5Decl…`.

Shared rules for all agents: exact-path `git add`; `DB_DATABASE=opeschool_test_fN php artisan migrate:fresh --force` before suites; never run two suites on one DB; PHPStan 0 errors, no `ignoreErrors`; `ModuleBoundaryTest` must stay green (no cross-module model imports — `DB::table` for reads, Actions for writes); every ledger write through `PostFromEvent`; NEEDS-VERIFICATION items ship empty-and-blocking, never seeded.

## 7. Risks / decisions to record
1. `withholding_attestations.supplier_payment_id` FK deferred to F4's 250022 (timestamp ordering) — documented above; do not "fix" by reordering.
2. Inventory/Assets Action contracts (§8) are Phase 9 — `ConfirmGoodsReceipt` and `CapitaliseAssetFromInvoice` land behind interfaces with recorded-intent no-ops; add to tracked debts.
3. Posting-event registration migrations (250024/250027) may be no-ops if `PostingRule.event` is a free string — F4/F5 verify against `SavePostingRule` before writing; keep files as documentation migrations rather than renumbering.
4. Fiscal identity as a new singleton table (spec assumed a `school_profiles` row that does not exist) — flag for spec-owner sign-off.
5. Phase 6 (Fees) is still not green on this checkout — Phase 5 agents must not touch any Fees/Accounting file except F5's single `ReopenFiscalYear` guard.

### Critical Files for Implementation
- /home/user/opes-school-soft/docs/specs/03-tax-procurement.md
- /home/user/opes-school-soft/app/Modules/Accounting/Actions/PostFromEvent.php
- /home/user/opes-school-soft/app/Modules/Tax/Actions/ConfigureTaxCode.php (pattern for all append-only config Actions)
- /home/user/opes-school-soft/app/Modules/Fees/Actions/RecordPayment.php (pattern: lock, allocate, PostFromEvent, sequence)
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php (+ routes/web.php, Permission.php — F5 wiring)