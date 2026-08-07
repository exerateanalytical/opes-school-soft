# 03 — Tax & Procurement

**Version:** 2.0
**Date:** 2026-08-07
**Status:** Draft for review
**Owns:** Fiscal identity of the school · TVA · withholding at source · tax declarations incl. DSF · suppliers · requisitions · purchase orders · goods receipt · supplier invoices · payables · supplier payments and credit notes.
**Binding parent:** `00-core.md`. Nothing here may contradict it.
**Build phases:** tax model in Phase 4 (with the ledger); procurement + declarations in Phase 5. Both land **before** Fees (Phase 6).

> **Why this document exists.** v1 defined no `Supplier`, no `SupplierInvoice`, no `PurchaseOrder` and no `Expense`, while listing `supplier.invoice.received` and `supplier.paid` as posting events, referencing `Asset.supplier_id`, and promising an aged-payables report. The entire class 40 side of the ledger — half of every school's book — was absent. v1 deferred TVA to "open questions for the accountant"; TVA is not a report, it changes the schema. And `SchoolProfile` carried **no NIU, no RCCM, no tax regime and no accreditation number**, verified against `frontend images/general setting.png`, whose School Information panel contains name, code, address, city, country, phone, email, website, established year and logo — and nothing fiscal. Every invoice and receipt the product prints today is legally deficient.

---

## 1. Scope, boundaries and cross-references

| Concern | Owned here | Owned elsewhere |
|---|---|---|
| Chart of accounts, journals, posting rules, `JournalEntry` | — | `02-accounting.md` |
| Tax codes, rates, prorata, declarations, withholding | **Yes** | — |
| Supplier lifecycle, P2P chain, payables | **Yes** | — |
| Fee/customer-side invoicing, receipts, receivables | — | `04-fees.md` |
| `tax_code_id` on `FeeItem` / `InvoiceLine` | field **defined here**, applied there | `04-fees.md` |
| Asset creation from a supplier invoice | the Action contract | `06-assets-stores.md` |
| Stock receipt from a supplier invoice | the Action contract | `06-assets-stores.md` |
| Payroll withholding (IRPP, CNPS, CFC, FNE, TDL) | **No** — different regime | `05-hr-payroll.md` |
| Printed supplier documents, attestation layout | field checklist here | `10-documents.md` |
| Screens | contracts here | `09-ui.md` |

**Two withholding systems must never be conflated.** Payroll withholding on *salaries* (IRPP/CAC/CFC/CNPS) belongs to `05-hr-payroll.md` and its `StatutoryDeclaration`. This document owns withholding on **payments to third parties** (AIR, précompte sur achats) and its `TaxDeclaration`. They have different bases, different rates, different forms and different attestations. A single shared entity for both is a design error: it forces one rate table to serve two legal regimes.

**Modules:** `app/Modules/Tax/` and `app/Modules/Procurement/` per `00-core.md` §6.3. Procurement calls Tax **only through Tax Actions**; neither reaches into Accounting models — both emit events consumed by Accounting's posting engine (`00-core.md` §6.2 rules 2 and 6).

---

## 2. SchoolProfile fiscal identity

`SchoolProfile` is a **singleton** row (single-tenant per `00-core.md` §2). The fiscal identity is added as columns on that row, not a side table: it must be readable by the document renderer with no join, and there is exactly one.

### 2.1 Fields

| Column | Type | Null | Notes |
|---|---|---|---|
| `legal_name` | `VARCHAR(200)` | NO | The registered legal name, which may differ from the trading/brand name already stored as `name`. |
| `legal_form` | `VARCHAR(40)` | NO | Enum-as-reference: `etablissement_prive_laic` \| `etablissement_confessionnel` \| `sarl` \| `sa` \| `association` \| `fondation` \| `gie` \| `etablissement_individuel` \| `other`. Drives which of RCCM / association receipt number is mandatory. **The exact enumeration NEEDS VERIFICATION** against OHADA AUSCGIE forms available to a Cameroonian private school. |
| `niu` | `CHAR(14)` collation `utf8mb4_0900_as_cs` | NO | *Numéro Identifiant Unique*. `UNIQUE`. Format validated by a rule object, **format spec NEEDS VERIFICATION** — do not ship a regex that rejects a real NIU. Until verified, validate length + alphanumeric only, and warn (never block) on shape mismatch. |
| `niu_issued_on` | `DATE` | YES | |
| `rccm_number` | `VARCHAR(40)` `as_cs` | YES | *Registre du Commerce et du Crédit Mobilier*. Mandatory when `legal_form` is a commercial form. |
| `rccm_registry` | `VARCHAR(120)` | YES | Greffe of registration. |
| `rccm_registered_on` | `DATE` | YES | |
| `tax_centre_code` | `VARCHAR(20)` `as_cs` | NO | *Centre des impôts de rattachement* code. |
| `tax_centre_name` | `VARCHAR(160)` | NO | |
| `tax_centre_type` | `VARCHAR(10)` | NO | `DGE` \| `CIME` \| `CDI` \| `CSI`. **Load-bearing: it selects the DSF due date (§7.6).** |
| `tax_regime` | `VARCHAR(20)` | NO | `reel` \| `simplifie` \| `liberatoire` \| `non_assujetti`. See §5. |
| `tax_regime_effective_from` | `DATE` | NO | |
| `is_tva_registered` | `BOOLEAN` | NO | Whether the school is an *assujetti* to TVA (may collect and deduct). Default `false`. |
| `tva_registered_from` | `DATE` | YES | Required when `is_tva_registered`. |
| `cnps_employer_number` | `VARCHAR(30)` `as_cs` | YES | Duplicated read-only mirror of `EmployerProfile.cnps_employer_number` (owned by `05-hr-payroll.md`). **Store it once, in `EmployerProfile`; expose it here as a derived accessor, never a second column.** |
| `ministry_accreditation_number` | `VARCHAR(60)` `as_cs` | NO | *Arrêté / autorisation d'ouverture*. **This conditions the TVA exemption on tuition and boarding (§5.2). Without it the school cannot claim the exemption.** |
| `ministry_accreditation_authority` | `VARCHAR(20)` | NO | `MINESEC` \| `MINEDUB` \| `MINEFOP` \| `MINESUP` \| `other`. |
| `ministry_accreditation_date` | `DATE` | NO | |
| `ministry_accreditation_expires_on` | `DATE` | YES | Null = indefinite. When set and passed, §5.2 raises a blocking compliance warning. |
| `ministry_accreditation_document_id` | FK → `Document` | YES | `ON DELETE RESTRICT`. |
| `fiscal_year_end_month` | `TINYINT` | NO | Default `12`. `CHECK (fiscal_year_end_month = 12)` — see §2.3. |
| `fiscal_year_end_day` | `TINYINT` | NO | Default `31`. `CHECK (fiscal_year_end_day = 31)`. |
| `books_cote_paraphe_reference` | `VARCHAR(80)` | YES | Owned by `02-accounting.md` C5; listed here only because it is entered in the same wizard step. |
| `fiscal_identity_confirmed_by` | FK → `users` | YES | `ON DELETE RESTRICT`. |
| `fiscal_identity_confirmed_at` | `DATETIME` | YES | |

All of the above are **audited** (`00-core.md` §14). `niu`, `rccm_number` and `ministry_accreditation_number` changes additionally emit `school.fiscal_identity.changed`, which invalidates any cached document header.

### 2.2 Invariants

1. `niu` is `UNIQUE` and, once `fiscal_identity_confirmed_at` is set, **immutable except through a permission-gated `CorrectFiscalIdentity` Action** requiring a reason and a supporting document. A NIU typo silently propagates onto every printed invoice and every filed declaration.
2. `is_tva_registered = true` requires `tax_regime = 'reel'`. **NEEDS VERIFICATION** whether the *régime simplifié* may be TVA-registered in Cameroon; until verified the system permits only `reel` and shows the rule text.
3. Any TVA-bearing operation (§5) is **refused** unless `is_tva_registered` and a `TaxRegime` row is effective for the transaction date.
4. Claiming exemption on a tuition or boarding line (§5.2) is **refused** unless `ministry_accreditation_number` is present and, where `ministry_accreditation_expires_on` is set, not expired at the invoice date.
5. Printing any invoice, receipt, credit note, supplier order or attestation is **refused** unless `niu`, `tax_regime`, `tax_centre_name` and `legal_name` are all populated. This is a hard gate, not a warning — an unidentified invoice is a legally deficient document, and the school, not the vendor, bears the penalty.

### 2.3 Fiscal year end

`02-accounting.md` establishes that OHADA fixes the exercice at 1 January – 31 December. `fiscal_year_end_month/day` therefore exist **only** to render the value on documents and to make the constraint explicit and greppable; they are CHECK-pinned to 31/12. The setup wizard shows the field read-only with the legal note. An irregular *first* exercice is permitted and is expressed on `FiscalYear`, not here.

### 2.4 First-run wizard step

A **blocking** step, ordered immediately after school identity and before any accounting configuration. It cannot be skipped or deferred. Content:

- All §2.1 fields, grouped: Legal identity · Tax identity · Accreditation · Fiscal year.
- Inline legal notes explaining *why* each is required (the bursar entering it is not a tax specialist).
- Upload slots for the *attestation d'immatriculation* (NIU), the RCCM extract and the accreditation *arrêté*.
- A final confirmation checkbox — "I confirm these values match the school's registration documents" — writing `fiscal_identity_confirmed_by/at`.

### 2.5 Rendering obligation

The document header block (`10-documents.md`) renders, on **every** invoice, receipt, credit note, purchase order, supplier payment advice, withholding attestation and statement:

```
<legal_name>  (trading as <name>, if different)
NIU : <niu>          RCCM : <rccm_number>
Régime : <tax_regime label>   Centre des impôts : <tax_centre_name>
Autorisation d'ouverture n° <ministry_accreditation_number> du <date>
```

Golden-file tests per language assert the presence of NIU and régime on each document type. A missing NIU is an on-the-spot finding.

---

## 3. Supplier

### 3.1 Entity

| Column | Type | Null | Notes |
|---|---|---|---|
| `id` | `BIGINT` PK | | |
| `code` | `VARCHAR(20)` `as_cs` | NO | `UNIQUE`. Allocated from a `Sequence` (gaps permitted, `00-core.md` §12), format `FRN/000123`. |
| `name` | `VARCHAR(200)` | NO | |
| `legal_form` | `VARCHAR(40)` | YES | |
| `supplier_type` | `VARCHAR(20)` | NO | `company` \| `individual` \| `public_body` \| `association`. Drives which identity fields are mandatory and which withholding defaults apply. |
| `niu` | `CHAR(14)` `as_cs` | YES | `UNIQUE` where not null (MySQL permits multiple NULLs — this is intended: a market trader has none). **Absence or inactivity of the NIU changes the withholding rate (§6).** |
| `niu_status` | `VARCHAR(20)` | NO | `unknown` \| `active` \| `inactive` \| `not_found` \| `none_declared`. Default `unknown`. |
| `is_niu_verified` | `BOOLEAN` | NO | Default `false`. |
| `niu_verified_at` | `DATETIME` | YES | |
| `niu_verified_by` | FK → `users` | YES | RESTRICT. |
| `niu_verification_evidence` | `VARCHAR(255)` | YES | Free text or `Document` reference — the *attestation de non-redevance* / portal screenshot. |
| `regime_fiscal` | `VARCHAR(20)` | YES | `reel` \| `simplifie` \| `liberatoire` \| `non_professionnel` \| `unknown`. |
| `tax_centre_name` | `VARCHAR(160)` | YES | |
| `rccm_number` | `VARCHAR(40)` `as_cs` | YES | |
| `has_contributor_card` | `BOOLEAN` | NO | Default `false`. *Carte de contribuable.* Drives a distinct withholding rate (§6). |
| `withholding_profile_id` | FK → `WithholdingProfile` | YES | `ON DELETE RESTRICT`. Null ⇒ resolved dynamically per §6.4. |
| `is_withholding_exempt` | `BOOLEAN` | NO | Default `false`. |
| `withholding_exemption_ref` | `VARCHAR(120)` | YES | Mandatory when `is_withholding_exempt`. |
| `withholding_exemption_expires_on` | `DATE` | YES | |
| `default_tax_code_id` | FK → `TaxCode` | YES | RESTRICT. |
| `default_expense_account_id` | FK → `ChartOfAccount` | YES | RESTRICT. |
| `payable_account_id` | FK → `ChartOfAccount` | NO | Must be a **collective** account (`02-accounting.md` C2): 401 for operating, 481 for investment. See §3.3. |
| `payment_terms_days` | `SMALLINT` | NO | Default `0`. |
| `currency` | `CHAR(3)` | NO | Default `XAF`. See §3.5. |
| `contact_name`, `phone`, `phone_alt`, `email`, `website` | | YES | |
| `address_line1`, `address_line2`, `city`, `region`, `country` | | YES | `country` default `CM`. |
| `bank_name`, `bank_branch`, `bank_account_rib` | `VARCHAR` **encrypted** | YES | Per `00-core.md` §9.5. `bank_account_rib` carries a blind-index column `bank_account_rib_bidx` for duplicate detection. |
| `mobile_money_operator` | `VARCHAR(30)` | YES | |
| `mobile_money_number` | `VARCHAR(20)` **encrypted** | YES | + blind index. |
| `category_id` | FK → `SupplierCategory` | YES | RESTRICT. |
| `is_active` | `BOOLEAN` | NO | Default `true`. |
| `is_archived` | `BOOLEAN` | NO | Default `false`. Per `00-core.md` §10.5 — **never `SoftDeletes`**, the unique `code` would be permanently blocked. |
| `blocked_reason` | `VARCHAR(255)` | YES | |
| `notes` | `TEXT` | YES | |
| `created_by`, `updated_by` | FK → `users` | | RESTRICT. |

**Deletion:** `Supplier` is RESTRICT in `00-core.md` §10.5. There is no delete path once any PO, invoice or payment references it; archive instead.

**Indexes:** `UNIQUE(code)`, `UNIQUE(niu)`, `INDEX(name)`, `INDEX(is_active, is_archived)`, `INDEX(category_id)`, `INDEX(bank_account_rib_bidx)`.

### 3.2 Duplicate prevention

Duplicate suppliers are the classic payables fraud vector (same vendor, two records, one invoice paid twice). At save the Action runs a **similarity check** on `name` (normalised, accent-stripped), `niu`, `phone` and `bank_account_rib_bidx`, and presents matches for confirmation. An exact `niu` or `bank_account_rib_bidx` match is a **hard block**, overridable only with the `procurement.supplier.override_duplicate` permission and a stored reason.

### 3.3 401 vs 481 — operating versus investment payables

SYSCOHADA separates ordinary supplier debt from debt arising on fixed-asset acquisition:

| Account | Use |
|---|---|
| **401** Fournisseurs | Operating purchases: consumables, services, utilities, merchandise for resale. |
| **481** Fournisseurs d'investissements | Capital acquisitions. |
| **4812** | Fournisseurs d'investissements — immobilisations corporelles. |
| **4817** | Retenues de garantie (amounts withheld from a contractor pending final acceptance). |
| **4818** | Factures non parvenues (goods/services received, invoice not yet arrived — the accrual). |

`Supplier.payable_account_id` is only a **default**. The authoritative choice is per document: `SupplierInvoice.payable_account_id`, defaulted from the supplier, overridden when a line is capitalised. A single supplier legitimately sells both a box of chalk (401) and a minibus (4812).

**Invariant:** an invoice whose lines include any `is_capitalised = true` line must use an account in the 481 family. Mixed invoices (some capex, some opex lines) are **split at posting into two payable lines**, one per family, each carrying the same `partner_id`; the Action refuses to post a single payable line spanning both.

**Retenue de garantie (4817).** A construction or major-works `PurchaseOrder` may carry `retention_rate_bp` and `retention_release_due_on`. On invoice posting the retained portion credits **4817** instead of 481/401; a `RetentionRelease` Action later reclassifies `Dr 4817 / Cr 401` when the works are accepted, at which point it becomes payable. Retention is **not** a discount and never touches expense.

**Factures non parvenues (4818).** Generated by the year-end cut-off run (`02-accounting.md` C8) from every `GoodsReceipt` with no matched `SupplierInvoice` at the closing date, valued at PO price. Posted `Dr 60x/61x/62x / Cr 4818` and **reversed on the first day of the next period**. This is the accrual `02-accounting.md` requires and procurement is the only module that can produce its input.

### 3.4 SupplierCategory

`id, code (UNIQUE, as_cs), name, name_fr, default_expense_account_id (FK RESTRICT), default_tax_code_id (FK RESTRICT), default_withholding_profile_id (FK RESTRICT), is_active`. Reference data, archive-flag deletion.

### 3.5 Currency

`00-core.md` §7.1 makes money `BIGINT SIGNED` whole FCFA and `02-accounting.md` requires a decision on multi-currency. **This document takes the dependent position: the ledger is XAF-only.** `Supplier.currency` and `SupplierInvoice.currency` + `exchange_rate_bp` are retained as **source-document metadata only** — the original amount as printed on a foreign invoice — and the ledger receives the XAF conversion computed in the Action, with the rate and source stored on the invoice for audit. No FX gain/loss is recognised on payables in this version; where an invoice is settled at a different rate the difference is an expense on a designated account, configured in settings. If `02-accounting.md` instead specifies full multi-currency, that document wins and this paragraph is superseded.

---

## 4. The procure-to-pay chain

```
PurchaseRequisition ──approve──▶ PurchaseOrder ──send──▶ (supplier)
                                      │
                                      ▼
                                GoodsReceipt  ◀── delivery
                                      │
                                      ▼  three-way match
SupplierInvoice ──approve──▶ posted ──▶ payable (401/481)
      │                                      │
      ▼                                      ▼
SupplierCreditNote                    SupplierPayment ──▶ withholding attestation
```

Each step is optional-by-configuration except `SupplierInvoice`: a school can buy chalk without a requisition. `ProcurementSettings.requisition_required_above`, `.po_required_above` and `.receipt_required_for_goods` (thresholds in FCFA, `0` = always) govern which steps are mandatory. **`SupplierInvoice` is never optional** — it is the document that creates the payable and the expense.

### 4.1 PurchaseRequisition

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `requisition_no` | `VARCHAR(30)` `as_cs` | `UNIQUE`. Series `REQ/2026/000123`, gaps permitted. |
| `requested_by` | FK `users` | RESTRICT. |
| `department_id` | FK `Department` | RESTRICT, nullable. |
| `school_section_id` | FK | RESTRICT, nullable. Feeds the analytic split. |
| `requested_on` | `DATE` | |
| `needed_by` | `DATE` | nullable |
| `justification` | `TEXT` | |
| `status` | `VARCHAR(20)` | `draft` \| `submitted` \| `approved` \| `partially_ordered` \| `ordered` \| `rejected` \| `cancelled` |
| `approved_by`, `approved_at` | | RESTRICT |
| `rejected_reason` | `VARCHAR(255)` | mandatory when `rejected` |
| `budget_line_id` | FK → `BudgetLine` | RESTRICT, nullable. |
| `estimated_total` | `BIGINT SIGNED` | derived from lines, stored for indexing. |
| `academic_year_id`, `fiscal_year_id` | FK | RESTRICT. Dual calendar per `02-accounting.md` C3. |
| `idempotency_key` | `CHAR(36)` | `UNIQUE`. |

`PurchaseRequisitionLine`: `requisition_id (FK CASCADE)`, `line_no`, `description`, `inventory_item_id (FK RESTRICT, nullable)`, `asset_category_id (FK RESTRICT, nullable)`, `quantity DECIMAL(12,3)`, `unit_of_measure`, `estimated_unit_price BIGINT SIGNED`, `estimated_amount BIGINT SIGNED`, `expense_account_id (FK RESTRICT)`, `qty_ordered DECIMAL(12,3) DEFAULT 0`.

`ON DELETE CASCADE` from requisition to its lines is permitted **only while `status = 'draft'`**; a BEFORE DELETE trigger on `PurchaseRequisition` rejects deletion in any other status. Approved requisitions are cancelled, never deleted.

**Budget check.** If `budget_line_id` is set and `ProcurementSettings.budget_enforcement` is `warn` or `block`, approval evaluates committed + actual against the budget line (`02-accounting.md` budget model) and warns or refuses. Commitment accounting (engagements) is **explicitly out of scope for v2** — the budget consumption is measured on posted invoices plus open PO value, computed at read time, not carried as a ledger commitment.

### 4.2 PurchaseOrder

| Column | Type | Notes |
|---|---|---|
| `po_no` | `VARCHAR(30)` `as_cs` | `UNIQUE`, series `BC/2026/000123` (*bon de commande*). |
| `supplier_id` | FK | RESTRICT. |
| `requisition_id` | FK | RESTRICT, nullable. A PO may consolidate several requisitions via `PurchaseOrderLine.requisition_line_id` instead. |
| `order_date` | `DATE` | |
| `expected_delivery_date` | `DATE` | nullable |
| `delivery_address` | `VARCHAR(255)` | |
| `currency`, `exchange_rate_bp` | | §3.5 |
| `subtotal_ht`, `tax_total`, `total_ttc` | `BIGINT SIGNED` | derived, stored. |
| `retention_rate_bp` | `BIGINT` | default `0`. §3.3. |
| `retention_release_due_on` | `DATE` | nullable |
| `status` | `VARCHAR(20)` | `draft` \| `pending_approval` \| `approved` \| `sent` \| `partially_received` \| `received` \| `partially_invoiced` \| `invoiced` \| `closed` \| `cancelled` |
| `approved_by`, `approved_at`, `sent_at` | | |
| `closed_reason` | `VARCHAR(255)` | for short-closing an under-delivered PO. |
| `payable_account_id` | FK | RESTRICT. Default from supplier; §3.3. |
| `academic_year_id`, `fiscal_year_id` | FK | RESTRICT. |
| `version` | `INT` | optimistic lock, `00-core.md` §10.6. |
| `idempotency_key` | `CHAR(36)` | `UNIQUE`. |

`PurchaseOrderLine`: `purchase_order_id (CASCADE while draft only)`, `line_no`, `requisition_line_id (RESTRICT, nullable)`, `description`, `inventory_item_id`, `asset_category_id`, `is_capitalised BOOLEAN`, `quantity DECIMAL(12,3)`, `unit_of_measure`, `unit_price_ht BIGINT SIGNED`, `discount_rate_bp BIGINT DEFAULT 0`, `amount_ht BIGINT SIGNED`, `tax_code_id (FK RESTRICT)`, `tax_amount BIGINT SIGNED`, `amount_ttc BIGINT SIGNED`, `expense_account_id (FK RESTRICT)`, `analytic_value_ids` via a pivot (`02-accounting.md` H), `qty_received DECIMAL(12,3) DEFAULT 0`, `qty_invoiced DECIMAL(12,3) DEFAULT 0`.

**Invariants.**
1. `amount_ht = round_half_up(quantity × unit_price_ht × (1 − discount_rate_bp/10000))`, rounded once to whole FCFA per line.
2. `subtotal_ht = Σ line.amount_ht`; `total_ttc = subtotal_ht + tax_total`. The header is never independently rounded (`00-core.md` §7.3).
3. `qty_received ≤ quantity × (1 + ProcurementSettings.over_receipt_tolerance_bp/10000)`.
4. `qty_invoiced ≤ qty_received` when `receipt_required_for_goods` and the line is a goods line; service lines match two-way (§4.4).
5. An approved PO is **immutable**. Changes go through a `PurchaseOrderAmendment` (`po_id`, `amendment_no`, `reason`, `approved_by/at`, a snapshot of the prior line set), producing a new version of the line set. `UNIQUE(po_id, amendment_no)`.
6. A PO **posts nothing to the ledger.** It is a commitment document. This is stated explicitly because a developer will otherwise reach for a posting rule here.

**Approval.** `ProcurementSettings` carries an ordered `ApprovalThreshold` table (`min_amount`, `max_amount`, `required_role`, `sequence`) so that a 5,000,000 FCFA order needs the Principal and a 50,000 one needs the Bursar. Segregation of duties: **the requester cannot approve their own requisition or PO**, enforced in the Action and covered by a test.

### 4.3 GoodsReceipt

| Column | Notes |
|---|---|
| `receipt_no` | `UNIQUE`, series `BR/2026/000123` (*bon de réception*). |
| `purchase_order_id` | FK RESTRICT, nullable (direct receipt permitted where PO not required). |
| `supplier_id` | FK RESTRICT. |
| `received_on` | `DATE`. Uses `business_date()` (`00-core.md` §7.5). |
| `received_by` | FK `users` RESTRICT. |
| `delivery_note_ref` | supplier's own *bordereau*. |
| `store_location_id` | FK RESTRICT, nullable — see `06-assets-stores.md`. |
| `status` | `draft` \| `confirmed` \| `cancelled` |
| `has_discrepancy` | BOOLEAN, derived and stored. |
| `academic_year_id`, `fiscal_year_id` | FK RESTRICT. |

`GoodsReceiptLine`: `goods_receipt_id (CASCADE while draft)`, `purchase_order_line_id (RESTRICT, nullable)`, `description`, `qty_ordered`, `qty_received`, `qty_accepted`, `qty_rejected`, `rejection_reason`, `inventory_item_id`, `asset_category_id`, `serial_numbers JSON`.

**Confirming a receipt** emits `procurement.goods.received`, which:
- for `inventory_item_id` lines, calls the Inventory Action to create a `StockMovement` at PO unit cost (`06-assets-stores.md` owns valuation; **procurement supplies quantity and provisional cost, inventory owns the weighted-average update**);
- for `asset_category_id` lines, creates a **provisional** `Asset` in status `pending_capitalisation` — the definitive cost is only known once the invoice determines the recoverable/non-recoverable VAT split (§5.5);
- **posts nothing.** Stock and expense recognition happen on the invoice, or at cut-off via 4818 (§3.3). Posting on receipt would double-count when the invoice arrives.

`qty_rejected > 0` sets `has_discrepancy` and blocks a three-way match on that line until a `SupplierCreditNote` or a PO amendment resolves it.

### 4.4 Three-way match

Run by `MatchSupplierInvoice` before an invoice may be approved.

| Mode | Applies to | Compares |
|---|---|---|
| **Three-way** | goods lines with a PO and receipt required | PO price × PO quantity ↔ receipt quantity ↔ invoice price × invoice quantity |
| **Two-way** | services, utilities, and goods where `receipt_required_for_goods = false` | PO ↔ invoice |
| **None** | direct invoices below `po_required_above` | nothing; requires the `procurement.invoice.approve_unmatched` permission and a stored reason |

**Tolerances** (`ProcurementSettings`): `price_tolerance_bp`, `price_tolerance_absolute` (whichever is greater), `quantity_tolerance_bp`. Within tolerance ⇒ `matched`. Outside ⇒ `match_exception` with a typed reason (`price_variance` \| `quantity_variance` \| `no_receipt` \| `over_invoiced` \| `no_po` \| `supplier_mismatch`), which **blocks approval** until either the exception is overridden by a user holding `procurement.invoice.override_match` (recorded with reason on `SupplierInvoice.match_override_reason` / `_by` / `_at`) or the underlying documents are corrected.

Match state is stored per invoice line (`match_status`, `matched_qty`, `price_variance`, `quantity_variance`) so the exception report names the line, not the invoice.

**Worked example.** PO line: 40 reams at 3,250 FCFA HT = 130,000. Receipt: 38 accepted, 2 rejected (damaged). Invoice: 40 reams at 3,400 = 136,000.
- Quantity variance: invoiced 40 vs accepted 38 ⇒ over-invoiced by 2 ⇒ `quantity_variance`.
- Price variance: 3,400 vs 3,250 = +150, +461 bp. With `price_tolerance_bp = 200` and `price_tolerance_absolute = 100`, the greater tolerance is 200 bp ⇒ 3,250 × 1.02 = 3,315 < 3,400 ⇒ `price_variance`.
- Result: `match_exception`, approval blocked. Correct resolution is a supplier credit note for 2 reams plus either a PO amendment for the price or a credit note for 40 × 150 = 6,000.

### 4.5 SupplierInvoice

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `internal_no` | `VARCHAR(30)` `as_cs` | `UNIQUE`. Our own reference, series `FF/2026/000123`. Gaps permitted. |
| `supplier_invoice_no` | `VARCHAR(60)` `as_cs` | NO. **The supplier's own number.** `UNIQUE(supplier_id, supplier_invoice_no)` — the single most effective duplicate-payment control there is. |
| `supplier_id` | FK | RESTRICT. |
| `purchase_order_id` | FK | RESTRICT, nullable. |
| `invoice_date` | `DATE` | The supplier's date. Drives the TVA period (§5.6). |
| `received_date` | `DATE` | When we received it. |
| `value_date` | `DATE` | Per `02-accounting.md` C4 — retained when a late invoice is forward-posted into the first open period. |
| `due_date` | `DATE` | Derived from `invoice_date + supplier.payment_terms_days`, overridable. Drives aged payables. |
| `currency`, `exchange_rate_bp` | | §3.5. |
| `subtotal_ht` | `BIGINT SIGNED` | |
| `discount_total` | `BIGINT SIGNED` | |
| `tax_total` | `BIGINT SIGNED` | Σ TVA per line. |
| `total_ttc` | `BIGINT SIGNED` | |
| `withholding_total` | `BIGINT SIGNED` | Σ withholding computed per §6. Default `0`. |
| `net_payable` | `BIGINT SIGNED` | `total_ttc − withholding_total`. Stored. |
| `payable_account_id` | FK | RESTRICT. §3.3. |
| `status` | `VARCHAR(20)` | `draft` \| `pending_match` \| `match_exception` \| `pending_approval` \| `approved` \| `posted` \| `partially_paid` \| `paid` \| `cancelled` \| `disputed` |
| `match_status` | `VARCHAR(20)` | `not_required` \| `matched` \| `exception` \| `overridden` |
| `match_override_reason/_by/_at` | | RESTRICT on the FK. |
| `approved_by`, `approved_at` | | RESTRICT. |
| `posted_at`, `journal_entry_id` | FK | RESTRICT. |
| `is_migration` | `BOOLEAN` | `02-accounting.md` H — a migrated invoice must not re-trigger posting rules. |
| `academic_year_id`, `fiscal_year_id`, `accounting_period_id` | FK | RESTRICT; derived from `date` in the Action. |
| `document_id` | FK → `Document` | RESTRICT, nullable. The scanned original. |
| `version` | `INT` | optimistic lock. |
| `idempotency_key` | `CHAR(36)` | `UNIQUE`. |

`SupplierInvoiceLine`:

| Column | Notes |
|---|---|
| `supplier_invoice_id` | CASCADE **only while `status='draft'`**, enforced by a BEFORE DELETE trigger on the parent mirroring `00-core.md` §10.3's line-write trigger. |
| `line_no` | `UNIQUE(supplier_invoice_id, line_no)`. |
| `purchase_order_line_id` | FK RESTRICT, nullable. |
| `goods_receipt_line_id` | FK RESTRICT, nullable. |
| `description` | |
| `quantity`, `unit_of_measure`, `unit_price_ht`, `discount_rate_bp`, `amount_ht` | |
| **`tax_code_id`** | FK RESTRICT. **Per line, mandatory.** One invoice legitimately mixes an exempt line and a 19.25% line. |
| `tax_rate_bp_applied` | `BIGINT`. Snapshotted from the `TaxCode` version in force at `invoice_date` — the rate must never be re-derived later. |
| `tax_amount` | |
| `deductible_tax_amount` | The recoverable portion after prorata (§5.4). |
| `non_deductible_tax_amount` | `tax_amount − deductible_tax_amount`. Expensed or capitalised (§5.5). |
| **`expense_account_id`** | FK RESTRICT. **Per line, mandatory.** |
| `is_capitalised` | BOOLEAN. |
| `asset_id` / `asset_category_id` | FK RESTRICT, nullable. |
| `inventory_item_id` | FK RESTRICT, nullable. |
| `withholding_rule_id` | FK RESTRICT, nullable. |
| `withholding_base` | `BIGINT SIGNED`. |
| `withholding_rate_bp_applied` | `BIGINT`. |
| `withholding_amount` | `BIGINT SIGNED`. |
| `match_status`, `matched_qty`, `price_variance`, `quantity_variance` | §4.4. |
| analytic split | pivot rows summing to `amount_ht` (`02-accounting.md` H). |

**Invariants.**
1. `subtotal_ht = Σ amount_ht`; `tax_total = Σ tax_amount`; `total_ttc = subtotal_ht + tax_total`; `withholding_total = Σ withholding_amount`; `net_payable = total_ttc − withholding_total`. All computed in `Money`, never SQL.
2. `deductible_tax_amount + non_deductible_tax_amount = tax_amount`, exactly, per line. The prorata split uses `Money::allocate` so the invoice-level totals conserve (`00-core.md` §7.3).
3. A line with `is_capitalised = true` must carry `asset_category_id` or `asset_id`, and the header `payable_account_id` must be in the 481 family (§3.3).
4. A line with `inventory_item_id` posts through 601/602/604 and the stock/variation scheme of `02-accounting.md` C6 — **never directly to a 6xx consumption account**.
5. Posting is refused when the invoice's derived `accounting_period_id` is closed; the Action forward-posts to the first open period keeping `value_date` (`02-accounting.md` C4).
6. `withholding_total > 0` requires the supplier not to be `is_withholding_exempt` at `invoice_date`, or the exemption reference to be present and unexpired.

**Segregation of duties.** The user who created the invoice cannot approve it; the user who approved it cannot pay it. Three distinct permissions: `procurement.invoice.create`, `.approve`, and `procurement.payment.record`. Where a school is too small to separate all three, the override is per-permission, granted explicitly, and every such approval is flagged in the audit report as a control exception — not silently allowed.

### 4.6 Posting scheme

Emitted as `procurement.supplier_invoice.posted`; the posting rule lives in `02-accounting.md`'s multi-line `PostingRule` + `PostingRuleLine` model. The **shape** required (the Tax/Procurement contract) is:

Operating purchase of a consumable, HT 1,000,000, TVA 19.25% = 192,500, prorata 40%, no withholding:

```
Dr 6xx / 60x  purchase (HT)                     1 000 000
Dr 4451       TVA déductible (recoverable 40%)      77 000
Dr 6xx        TVA non déductible (60%)             115 500
    Cr 401    Fournisseurs                                1 292 500
```
*(TVA account codes 4451/4452 and the non-deductible expense account NEEDS VERIFICATION against the SYSCOHADA révisé listing before seeding. The structure — a deductible portion to a class 44 asset and a non-deductible portion to charge or asset cost — is not in doubt; the exact sub-account is.)*

Same invoice with 5.5% AIR withholding on a service, HT 1,000,000:

```
Dr 62x        service (HT)                       1 000 000
Dr 4451/6xx   TVA split as above                   192 500
    Cr 401    Fournisseurs                                1 192 500
Cr 401 is then partially settled by the withholding at payment, OR
the withholding is recognised at invoice:
    Dr 401    Fournisseurs                            55 000
        Cr 447  État, impôts retenus à la source            55 000
```

**The recognition point of withholding is a configuration decision, not a free choice per transaction.** `TaxSettings.withholding_recognition` ∈ {`on_invoice`, `on_payment`}, set once at configuration and effective-dated. Cameroonian withholding is legally triggered by **payment**, so the default is `on_payment`; `on_invoice` is offered for schools whose accountant prefers accrual symmetry. *Which basis the DGI requires NEEDS VERIFICATION with the school's accountant (blocking gate 6, `00-core.md` §16).* The system must not allow the two bases to be mixed within a fiscal year — a validation refuses a change of `withholding_recognition` while any unpaid invoice exists with recognised withholding.

Capital acquisition, non-recoverable VAT capitalised (§5.5):

```
Dr 2xxx       Immobilisation (HT + non-deductible TVA)
Dr 4451       TVA déductible (recoverable portion)
    Cr 481x   Fournisseurs d'investissements
```

### 4.7 SupplierPayment

| Column | Notes |
|---|---|
| `payment_no` | `UNIQUE`, series `PF/2026/000123`. |
| `supplier_id` | FK RESTRICT. |
| `payment_date` | `business_date()`. |
| `payment_method_id` | FK → `PaymentMethod` (**the table owned by `04-fees.md`**, reused; do not create a second one). RESTRICT. |
| `treasury_account_id` | FK RESTRICT. Derived from the payment method. |
| `reference` | cheque number, transfer ref, MoMo transaction id. Mandatory when the method says so. |
| `gross_amount` | Σ allocated invoice amounts (TTC). |
| `withholding_amount` | recognised here when `withholding_recognition = 'on_payment'`. |
| `fee_amount` | operator commission, e.g. mobile-money charges to **6317** (`02-accounting.md`). |
| `fee_bearer` | `school` \| `supplier`, from the payment method. |
| `net_amount` | actually disbursed. |
| `status` | `draft` \| `approved` \| `paid` \| `voided` |
| `clearing_state` | `not_applicable` \| `pending` \| `cleared` \| `bounced` — cheques mirror the `04-fees.md` model, posting to *effets à payer* while pending. |
| `recorded_by`, `approved_by`, `paid_by` | RESTRICT; **`recorded_by ≠ voided_by`** enforced. |
| `journal_entry_id` | FK RESTRICT. |
| `academic_year_id`, `fiscal_year_id`, `accounting_period_id` | RESTRICT. |
| `idempotency_key` | `UNIQUE`. |

`SupplierPaymentAllocation`: `supplier_payment_id`, `supplier_invoice_id (RESTRICT)`, `amount BIGINT SIGNED`, `withholding_amount`, `letter_code` (feeds `02-accounting.md` C10 `Lettering`). `UNIQUE(supplier_payment_id, supplier_invoice_id)`.

**Concurrency.** Allocation takes `SELECT … FOR UPDATE` on the `SupplierInvoice` row and recomputes outstanding **inside the lock** — the payables mirror of `00-core.md` §11's payment-allocation rule. Two clerks paying the same invoice is the same race as two cashiers allocating a receipt.

**Immutability and void.** A `SupplierPayment` is immutable once `paid`. Reversal is a separate `SupplierPaymentVoid` record (`payment_id UNIQUE`, `reason` mandatory, `voided_by`, `voided_at`, `reversal_journal_entry_id`); `is_voided` is derived. The void reverses allocations, re-opens the invoices, un-letters the lettering group, **and cancels or amends any issued withholding attestation** (§6.6) — an attestation for a payment that never happened is a false tax document. The reversal entry is dated in the **earliest open period**, never the original date.

**Disbursement file.** `SupplierPaymentBatch` groups approved payments into a bank transfer file or a mobile-money bulk file (`batch_no`, `bank_account_id`, `export_format`, `exported_at`, `exported_by`, `file_hash`). Formats are configurable; **no specific bank's file layout is specified here — layouts NEEDS VERIFICATION per bank.**

### 4.8 SupplierCreditNote

Mirrors `SupplierInvoice` with its own sequence (`AVF/2026/000123`), `original_invoice_id` (FK RESTRICT, nullable — a credit note may be standalone, e.g. an annual rebate), `reason_type` (`return` \| `price_correction` \| `quantity_correction` \| `rebate` \| `cancellation`), lines mirroring invoice lines with the same `tax_code_id` and `expense_account_id`, and a posting that reverses the original scheme (`Dr 401 / Cr 60x, Cr 4451`). A credit note **reduces the TVA déductible already claimed** — it therefore generates a `TaxDeclarationLine` adjustment in the period of the credit note, not a restatement of the original period.

Allocation to invoices uses the same `Lettering` mechanism. `Σ credit notes against an invoice ≤ invoice.total_ttc`, checked under lock.

### 4.9 Payables reporting

- **Aged payables** — buckets configurable (default current / 1–30 / 31–60 / 61–90 / >90), aged on **`due_date`, not invoice date**, with an explicit `as_of` parameter and the axis printed on the report. Source is unlettered items on the collective account, per `02-accounting.md` C10 — this is the only definition; a second parallel query over `SupplierInvoice` will drift from the ledger.
- **Supplier statement** — per supplier, per date range: opening balance, invoices, credit notes, payments, withholdings, closing balance, matching the auxiliary ledger exactly.
- **Auxiliary/collective reconciliation** — Σ per-supplier balances = balance of 401 + 481 + 4817 + 4818, run at every period close (`02-accounting.md` C2).
- **Open commitments** — approved POs not yet fully invoiced, with value.
- **Receipt-not-invoiced** — the 4818 working paper.
- **Duplicate-risk** — same supplier + same amount + invoice dates within N days.
- **Withholding register** — every withholding by supplier and period, reconciling to account 447 and to the filed declaration.

---

## 5. TVA

### 5.1 Verified facts

| Fact | Value | Confidence |
|---|---|---|
| Standard TVA rate | **19.25%** (17.5% base + 10% *centimes additionnels communaux*) | Verified |
| Tuition and boarding for accredited establishments | **Exempt** | Verified |
| The exemption is **conditional on ministry accreditation** | Yes | Verified |
| Commercial activities of private schools (uniform sales, textbook sales, canteen, student transport) | **Taxable** since the **2022 Finance Law** removed the exemption | Verified |
| *Prorata de déduction* applies where exempt and taxable activities coexist | Yes | Verified |
| Non-recoverable input VAT must be **capitalised into asset cost** | Yes | Verified |
| **The exact CGI article granting the education exemption** | art. 120 and art. 128 are both cited by different sources | **NEEDS VERIFICATION** |
| Zero-rated (0%) vs exempt distinction and which supplies are zero-rated | — | **NEEDS VERIFICATION** |
| TVA registration turnover threshold | — | **NEEDS VERIFICATION** |
| TVA return filing deadline and form reference | commonly stated as the 15th of the following month | **NEEDS VERIFICATION** |
| Prorata computation formula as prescribed by the CGI, and the *régularisation* rules on the provisional-to-definitive prorata | — | **NEEDS VERIFICATION** |

**Nothing marked NEEDS VERIFICATION is seeded.** `exemption_legal_ref` ships **empty** with a mandatory-before-use validation, and the TVA module refuses to compute until a `TaxRegime` row and at least one non-exempt `TaxCode` are configured and confirmed (`00-core.md` §16, blocking gate 6).

### 5.2 Exemption gating

The exemption on tuition and boarding is a **conditional privilege**, not a property of the fee item. Modelled as:

- `TaxCode` rows with `is_exempt = true` and `exemption_condition = 'ministry_accreditation'`.
- At invoice issue (`04-fees.md`) and at declaration generation, `EvaluateTaxCode` checks the condition against `SchoolProfile.ministry_accreditation_*`. If accreditation is missing or expired at the transaction date, the Action **refuses** and surfaces the reason, rather than silently invoicing exempt.
- A nightly compliance job raises a dashboard alert 90 days before `ministry_accreditation_expires_on`.

### 5.3 TaxCode

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `code` | `VARCHAR(20)` `as_cs` | Part of `UNIQUE(code, effective_from)`. |
| `name`, `name_fr` | | |
| `tax_type` | `VARCHAR(20)` | `tva` \| `withholding_air` \| `withholding_precompte` \| `other`. Withholding uses `WithholdingRule` (§6) for its logic; a `TaxCode` of a withholding type exists only so a line can name it. |
| `rate_bp` | `BIGINT` | Integer basis points per `00-core.md` §7.2. 19.25% = `1925`. **Note: basis points here are per 10 000, so 19.25% is 1925 — the value is exact and must not be stored as a percentage.** |
| `direction` | `VARCHAR(10)` | `output` (collected on sales) \| `input` (deductible on purchases) \| `both`. |
| `effective_from` | `DATE` | |
| `effective_to` | `DATE` NULL | **Exclusive.** Null = open. |
| `is_exempt` | `BOOLEAN` | |
| `is_zero_rated` | `BOOLEAN` | Distinct from exempt: zero-rated supplies grant deduction, exempt supplies do not, and conflating them corrupts the prorata numerator. |
| `exemption_legal_ref` | `VARCHAR(120)` NULL | Ships empty; mandatory when `is_exempt`. |
| `exemption_condition` | `VARCHAR(40)` NULL | `none` \| `ministry_accreditation` \| `other`. |
| `collected_account_id` | FK `ChartOfAccount` RESTRICT | TVA collectée (output). |
| `deductible_account_id` | FK RESTRICT | TVA déductible (input). |
| `non_deductible_expense_account_id` | FK RESTRICT | Where the prorata-excluded portion lands when it is not capitalised. |
| `affects_prorata_numerator` | `BOOLEAN` | Does turnover under this code count as taxable turnover in the prorata fraction? |
| `affects_prorata_denominator` | `BOOLEAN` | |
| `is_active` | `BOOLEAN` | |

**Immutability.** A `TaxCode` version is **append-only once referenced** by any posted document. A rate change closes the current row (`effective_to = new date`) and inserts a successor. Editing `effective_from` or `rate_bp` in place is forbidden by a model observer, because it silently rewrites the tax of every historical invoice. Overlap check per `code` over `[effective_from, effective_to)`; property test asserts **exactly one matching row per code for any date in a 10-year sweep** (the same test `05-hr-payroll.md` mandates for `StatutoryRate`).

**Selection date:** the **document date** (`invoice_date` for a supplier invoice, `issue_date` for a customer invoice), never `now()`.

**Rate snapshotting:** every line stores `tax_rate_bp_applied` and `tax_code_id`, exactly as `01-assessment.md` binds a mark to its `subject_allocation_id`. Re-deriving a historical rate is the same class of bug.

### 5.4 Prorata de déduction

**The problem.** A school with exempt tuition and taxable canteen cannot recover 100% of its input VAT. Recovery is limited to a fraction — the *prorata* — computed from the mix of taxable and total turnover.

**Entity: `VatProrata`**

| Column | Notes |
|---|---|
| `fiscal_year_id` | FK RESTRICT. `UNIQUE(fiscal_year_id, basis)`. |
| `basis` | `provisional` \| `definitive`. |
| `rate_bp` | The prorata, in basis points. |
| `numerator_amount` | Taxable (and zero-rated) turnover, HT. |
| `denominator_amount` | Total turnover, HT. |
| `computed_at`, `computed_by` | RESTRICT. |
| `source` | `computed` \| `manual`. Manual requires a reason and the accountant's confirmation. |
| `manual_reason` | |
| `confirmed_by`, `confirmed_at` | RESTRICT. A prorata cannot be used for deduction until confirmed. |
| `regularisation_entry_id` | FK `JournalEntry` RESTRICT, nullable. |

**Mechanics.**
1. At the opening of fiscal year N the school applies a **provisional prorata**, normally the definitive prorata of N−1. For the very first year the accountant enters it manually.
2. Every input-VAT line computes `deductible = round_half_up(tax_amount × rate_bp / 10000)` and `non_deductible = tax_amount − deductible`. The subtraction, not a second rounding, guarantees conservation.
3. At year end the **definitive prorata** is computed from actual turnover. A `VatProrataRegularisation` Action posts the difference between deductions taken at the provisional rate and those due at the definitive rate, as a single adjusting entry in the closing period, and stores the working paper listing every affected invoice.
4. *Whether the CGI additionally requires a multi-year regularisation on capital goods when the prorata varies by more than a stated margin* — **NEEDS VERIFICATION.** The schema anticipates it (`VatProrataRegularisation.asset_id` nullable, `regularisation_type`) but no rule is implemented until verified.

**Worked example.** Fiscal year 2026. Taxable turnover (canteen 18,400,000 + uniforms 6,100,000 + transport 9,500,000) = 34,000,000 HT. Exempt turnover (tuition 210,000,000 + boarding 46,000,000) = 256,000,000. Total = 290,000,000.

`rate_bp = round(34 000 000 / 290 000 000 × 10 000) = 1172` (11.72%).
*Whether the CGI requires rounding the prorata up to the next whole percent — a common rule in francophone VAT systems — is* **NEEDS VERIFICATION**; the schema stores basis points so either rule is representable, and the rounding rule is a configured setting `TaxSettings.prorata_rounding` ∈ {`exact_bp`, `up_to_whole_percent`}, shipping unset and blocking.

A supplier invoice for generator fuel, HT 4,000,000, TVA 19.25% = 770,000:
- `deductible = round_half_up(770 000 × 1172 / 10 000) = round_half_up(90 244.0) = 90 244`
- `non_deductible = 770 000 − 90 244 = 679 756`

Posting:
```
Dr 6xx  Carburant                                4 000 000
Dr 4451 TVA déductible                              90 244
Dr 6xx  TVA non déductible                         679 756
    Cr 401 Fournisseur                                     4 770 000
```
Note that 679,756 — not the 770,000 a naive implementation would fully deduct — is a real cost. Getting this wrong overstates deductible VAT by 88% of the tax on every mixed-use purchase, which is precisely the finding a DGI *contrôle* looks for.

### 5.5 Non-recoverable VAT on capital goods

Verified: non-recoverable input VAT on an asset must be **capitalised into the asset's cost**, not expensed.

Therefore `Asset.acquisition_cost` (owned by `06-assets-stores.md`) needs a documented basis, and this document specifies the contract:

- `Asset.acquisition_cost_basis` ∈ {`ht`, `ttc`, `ht_plus_non_deductible_vat`}.
- The **only** value procurement writes is `ht_plus_non_deductible_vat`, computed as `line.amount_ht + line.non_deductible_tax_amount`.
- `Asset.input_vat_total`, `Asset.input_vat_deducted`, `Asset.input_vat_capitalised` are stored for the audit trail and for any future capital-goods regularisation.
- Where the school is **not** TVA-registered, all input VAT is non-deductible, `rate_bp` is effectively 0, and the asset enters at TTC. The basis field still records how it was derived.

**Ordering consequence.** The `Asset` created provisionally at goods receipt (§4.3) cannot have a definitive cost. `CapitaliseAssetFromInvoice` runs at invoice posting, sets the cost, sets `in_service_date` if supplied, and moves the asset out of `pending_capitalisation`. `06-assets-stores.md` forbids depreciation before `in_service_date`, so no depreciation can occur on a provisional cost.

### 5.6 TVA period, sales side and declaration input

- The **output** side is generated by `04-fees.md` and by merchandise sales in `06-assets-stores.md`, each line carrying a `tax_code_id`.
- **`tax_code_id` is added to:** `FeeItem` (the default), `InvoiceLine` (the applied value, snapshotted), `SupplierInvoiceLine`, `SupplierCreditNoteLine`, `AssetCategory` (default for capitalised purchases), and `InventoryItem`/`InventoryItemCategory` (default for merchandise). On `FeeItem` and `AssetCategory` it is a default; on the line entities it is the authoritative snapshot with its `tax_rate_bp_applied`.
- The **TVA period** of a transaction is derived from the document date, mapped to a calendar month. A supplier invoice dated 28 February received on 4 March belongs to the February TVA period; if February's `TaxDeclaration` is already filed, the deduction is carried into March with `TaxDeclarationLine.is_late_claim = true` and the original date retained. *Whether the CGI permits deduction in a later period and within what window* — **NEEDS VERIFICATION.**
- **Interaction with agent collections.** `04-fees.md` C5 establishes that APEE contributions and exam registration fees are collected as an **agent**, credited to a class 47 liability and never to class 7. Such items are **excluded from both the prorata numerator and denominator** — they are not the school's turnover. `FeeItem.collection_basis = 'agent_for_third_party'` forces `affects_prorata_denominator = false` regardless of the tax code, and a validation refuses a tax code that says otherwise.

---

## 6. Withholding at source

### 6.1 Verified rates

| Withholding | Rate | Applies to |
|---|---|---|
| **AIR** (*acompte d'impôt sur le revenu*) | **5.5%** | Remuneration for services |
| **Précompte sur achats** | **1%** | Purchases (general) |
| Précompte sur achats — service stations | **0.5%** | |
| Taxpayer without a *carte de contribuable* | **5%** | |
| Supplier NIU inactive or non-existent | **5.5%** | |

**The current full withholding rate table NEEDS VERIFICATION**, including: whether AIR is 5.5% for all service categories or varies by supplier regime; the rates for rent, commissions, and non-resident payments; any minimum-payment threshold below which no withholding is due; and whether the 10% CAC is already included in the quoted rates or added on top. **No rate is seeded.** The module ships with an empty `WithholdingRule` table and refuses to compute withholding until at least one confirmed rule exists — surfacing a clear "configure withholding rules with your accountant" state rather than silently withholding nothing.

**Why this matters more than most tax rules.** A school that pays a plumber or an IT consultant without withholding is **personally liable for the tax plus penalties**. The failure is invisible until a *contrôle*, by which time it has recurred for years. The system must therefore make *not* withholding the deliberate, recorded act — never the default that happens when nobody configured anything.

### 6.2 WithholdingRule

| Column | Notes |
|---|---|
| `id` | PK |
| `code` | `as_cs`. Part of `UNIQUE(code, effective_from)`. |
| `name`, `name_fr` | |
| `withholding_type` | `air` \| `precompte_achats` \| `precompte_station_service` \| `no_contributor_card` \| `niu_inactive` \| `other`. |
| `rate_bp` | `BIGINT`. 5.5% = `550`. |
| `base` | `amount_ht` \| `amount_ttc`. **Load-bearing and NEEDS VERIFICATION per withholding type** — HT vs TTC differs by 19.25% of the base. Ships unset; a rule with an unset base cannot be activated. |
| `applies_to` | `services` \| `goods` \| `both` \| `rent` \| `commission`. |
| `minimum_base` | `BIGINT SIGNED`, default `0`. Below this, no withholding. **Threshold NEEDS VERIFICATION.** |
| `supplier_condition` | JSON criterion set evaluated against the supplier: `regime_fiscal`, `has_contributor_card`, `niu_status`, `supplier_type`, `country`. |
| `priority` | `INT`. Highest matching rule wins; ties are a configuration error rejected at save — the same "highest-priority single match wins" discipline `02-accounting.md` C1 imposes on posting rules. |
| `liability_account_id` | FK RESTRICT. `447 État, impôts retenus à la source` — *sub-account NEEDS VERIFICATION*. |
| `declaration_type` | Which `TaxDeclaration` type this feeds. |
| `legal_ref` | Ships empty; mandatory before activation. |
| `effective_from`, `effective_to` | `effective_to` **exclusive**. Append-only once referenced. |
| `is_active` | |
| `confirmed_by`, `confirmed_at` | RESTRICT. An unconfirmed rule cannot be applied. |

`WithholdingProfile` groups rules for assignment to a supplier: `code`, `name`, ordered `WithholdingProfileRule[]` (`profile_id`, `withholding_rule_id`, `sequence`). A supplier with no profile resolves dynamically (§6.4).

### 6.3 Rate-change history

Same discipline as `TaxCode`: append-only once referenced; overlap check per `(code, withholding_type)`; selection driven by the **payment date** when `withholding_recognition = 'on_payment'` and by the **invoice date** when `on_invoice`. The engine has exactly one path for this and it is unit-tested at every boundary in both directions.

### 6.4 Resolution algorithm

`ResolveWithholding(supplier, invoice_line, date)`:

1. If `supplier.is_withholding_exempt` and the exemption is unexpired at `date` → no withholding; record `withholding_rule_id = null`, `exemption_ref`.
2. Collect active, confirmed `WithholdingRule` rows effective at `date` whose `applies_to` matches the line's nature and whose `supplier_condition` evaluates true.
3. Order by `priority` descending. **Exactly one** rule must survive at the top priority; two rules at equal top priority is a configuration error raised at rule-save time and, defensively, at resolution time.
4. If `line.amount_ht < rule.minimum_base` → no withholding, reason `below_threshold`.
5. `base = rule.base = 'amount_ht' ? line.amount_ht : line.amount_ttc`.
6. `withholding_amount = round_half_up(base × rate_bp / 10000)`.
7. If no rule matches at all → **no withholding, but the invoice is flagged** `withholding_unresolved = true` and cannot be approved without the `procurement.invoice.waive_withholding` permission and a stored reason. Silence is not an answer here.

**Worked example.** IT consultant, individual, NIU present and active, holds a contributor card, régime simplifié. Service invoice HT 1,200,000, TVA 19.25% = 231,000, TTC 1,431,000. AIR rule matches at 5.5% on `amount_ht` *(base NEEDS VERIFICATION)*:

- `withholding = round_half_up(1 200 000 × 550 / 10 000) = 66 000`
- `net_payable = 1 431 000 − 66 000 = 1 365 000`

At payment (recognition `on_payment`):
```
Dr 401  Fournisseur                              1 431 000
    Cr 521  Banque                                       1 365 000
    Cr 447  État, retenues à la source                      66 000
```
The supplier receives 1,365,000 **and an attestation for 66,000**, which they will use to credit their own income tax. The 66,000 is remitted to the DGI with the monthly declaration. Netting it into a single 1,365,000 credit to 401 with no 447 leg is the error that leaves the school holding the tax with no liability recorded and no attestation issued.

### 6.5 Contractor withholding vs employment

**A recurring "consultant" paid monthly on a fixed schedule with school-directed hours is, in Cameroonian law, likely an employee.** `05-hr-payroll.md` C6 already flags misclassification of *vacataires* as the largest CNPS-audit exposure a school has. Procurement therefore raises a **classification warning** when a supplier of `supplier_type = 'individual'` receives payments in ≥ N consecutive months (default 3, configurable) exceeding a configurable amount, prompting a review. It does not block — the determination is legal, not arithmetic — but it must be surfaced, logged, and shown on the compliance dashboard. **NEEDS VERIFICATION with a labour lawyer**, as `05-hr-payroll.md` also requires.

### 6.6 WithholdingAttestation

The withholder is legally required to issue an *attestation de retenue à la source* to the supplier. Without it the supplier cannot credit the withholding against their own tax, and the school's withholding — however correctly remitted — is a de facto confiscation.

| Column | Notes |
|---|---|
| `attestation_no` | `UNIQUE`, own series `ATT/2026/000123`. Gaps permitted; but see the cancellation rule below. |
| `supplier_id` | FK RESTRICT. |
| `supplier_payment_id` / `supplier_invoice_id` | FK RESTRICT. Exactly one is non-null, per the recognition basis. `CHECK ((supplier_payment_id IS NULL) <> (supplier_invoice_id IS NULL))`. |
| `withholding_rule_id` | FK RESTRICT. |
| `period_month`, `period_year` | The tax period. |
| `base_amount`, `rate_bp_applied`, `withheld_amount` | Snapshotted. Never recomputed at print time. |
| `tax_declaration_id` | FK RESTRICT, nullable — set when included in a filed declaration. |
| `status` | `draft` \| `issued` \| `cancelled` \| `replaced` |
| `issued_at`, `issued_by` | RESTRICT. |
| `cancelled_at`, `cancelled_by`, `cancellation_reason` | |
| `replaced_by_attestation_id` | FK RESTRICT, `UNIQUE`. A replacement chain, never an in-place edit. |
| `document_hash` | SHA-256 of the issued PDF, per `00-core.md` §13/§14 practice. |
| `delivered_at`, `delivery_method` | `hand` \| `email` \| `post`. Proof of delivery matters in a dispute. |

**Printed content** (checklist enforced by golden-file tests, layout in `10-documents.md`): school `legal_name`, NIU, RCCM, tax centre, address · supplier name, NIU, address · the legal basis of the withholding · period · base amount · rate · amount withheld · the related invoice/payment reference and date · date and place of issue · signature block of the school's authorised officer and school stamp. Bilingual FR/EN.

**Invariants.**
1. An `issued` attestation is immutable. Corrections issue a replacement and set the original to `replaced`.
2. Voiding the underlying payment (§4.7) forces the attestation to `cancelled` in the same transaction — never leaves an issued attestation for a reversed payment.
3. `Σ withheld_amount` over issued, non-cancelled attestations for a period **must equal** the withholding line of that period's `TaxDeclaration` and the movement on account 447. Reconciled at declaration generation; a mismatch blocks filing.
4. Every attestation issue and reprint is written to `DocumentPrintLog`; reprints are watermarked `DUPLICATA`.

---

## 7. Tax declarations

### 7.1 TaxDeclaration

| Column | Notes |
|---|---|
| `id` | PK |
| `declaration_type` | `tva_monthly` \| `withholding_monthly` \| `acompte_is` \| `dsf_annual` \| `other`. Extensible reference table `TaxDeclarationType` rather than an enum, since the list is not verified. |
| `period_type` | `month` \| `quarter` \| `year` |
| `period_year`, `period_month` | `UNIQUE(declaration_type, period_year, period_month)` — one declaration per type per period, the idempotency backstop. |
| `fiscal_year_id` | FK RESTRICT. |
| `due_date` | Computed by the type's rule (§7.6). |
| `status` | `draft` \| `generated` \| `under_review` \| `filed` \| `paid` \| `amended` \| `cancelled` |
| `generated_at`, `generated_by` | RESTRICT. |
| `reviewed_by`, `reviewed_at` | RESTRICT. Segregation: generator ≠ reviewer where staffing allows. |
| `filed_at`, `filed_by` | RESTRICT. |
| `filing_channel` | `impots_cm` \| `paper` \| `other`. |
| `external_reference` | The DGI acknowledgement/receipt number. Mandatory when `filed`. |
| `amount_declared` | `BIGINT SIGNED` |
| `amount_paid` | `BIGINT SIGNED` |
| `paid_at`, `payment_reference` | |
| `penalty_amount` | `BIGINT SIGNED`, default `0`. |
| `interest_amount` | `BIGINT SIGNED`, default `0`. |
| `amends_declaration_id` | FK RESTRICT, nullable. `UNIQUE` — one amendment per original, chained. |
| `generated_from_entry_ids` | JSON array of `JournalEntry` ids, **plus** a normalised `TaxDeclarationEntry` pivot (`declaration_id`, `journal_entry_id`, `journal_entry_line_id`) — the JSON is for human inspection, the pivot is what queries and reconciles. |
| `inputs_hash` | SHA-256 over the contributing line set, stored at generation and **re-verified at filing**; filing fails if the ledger changed underneath, mirroring the payroll `inputs_hash` discipline in `00-core.md` §11. |
| `document_id` | FK RESTRICT. The generated form / the filed acknowledgement. |
| `notes` | |

`TaxDeclarationLine`: `declaration_id (CASCADE while draft)`, `line_code` (the form's own box reference), `label`, `base_amount`, `rate_bp`, `tax_amount`, `is_late_claim BOOLEAN`, `source` (`computed` \| `manual`), `manual_reason`.

**Form box codes ship empty.** `line_code` values must map to the actual DGI form and **NEEDS VERIFICATION**; until supplied, declarations generate with internal codes and a clear "not yet mapped to the official form" banner, and cannot be marked `filed`.

### 7.2 TVA declaration generation

`GenerateTvaDeclaration(year, month)`:

1. Refuse if a declaration for that type/period already exists in a non-cancelled status (the UNIQUE is the backstop; the Action gives the readable error).
2. Refuse if the corresponding `AccountingPeriod` is not at least soft-locked (`02-accounting.md` C8) — declaring from a period still accepting entries produces a figure that changes after filing.
3. Collect output TVA: all posted `JournalEntryLine` on the collected accounts of active `TaxCode`s within the period.
4. Collect input TVA: the deductible portions, plus late claims flagged per §5.6.
5. Apply prorata already applied at line level; the declaration does **not** re-apply it.
6. Compute `net = output − deductible − credit_carried_forward`. If negative, carry forward as `TaxCredit` (`fiscal_year_id`, `period`, `amount`, `consumed_in_declaration_id`).
7. Store `inputs_hash`. Emit `tax.declaration.generated`.

Filing sets `filed_at`, `external_reference`, and emits `tax.declaration.filed`, which triggers the payable/settlement posting via the Accounting posting rule (`tax.provision.recognized` and the settlement event are already in the `02-accounting.md` event list).

### 7.3 Withholding declaration

Same mechanics, sourced from `WithholdingAttestation` and account 447 movements, reconciled per §6.6 invariant 3, and producing a **per-supplier annex** (supplier name, NIU, base, rate, withheld) — required by the form and impossible to reconstruct later without the attestation table.

### 7.4 Compliance calendar

`TaxObligation` reference data: `declaration_type`, `frequency`, `due_rule` (a small declarative expression such as `day_of_next_month(15)` or `tax_centre_dependent`), `applies_when` (regime / TVA-registration predicate), `penalty_note`, `legal_ref`. From it the system generates upcoming obligations, shows them on the Finance and Compliance dashboards, and raises alerts at T−15, T−7, T−1 and on the due date, escalating to overdue.

An **unfiled prior-period declaration is a warning on the next generation run** — the same pattern `05-hr-payroll.md` C5 mandates for statutory declarations. Late-payment penalties and interest are recorded as a **distinct expense line**, never buried in the tax amount, so the cost of lateness is visible.

Because LAN deployments have no internet (`00-core.md` §3), **the system never files anything.** It generates the figures and the export; the bursar files on impots.cm. Any wording implying automated filing is forbidden.

### 7.5 DSF — Déclaration Statistique et Fiscale

**Verified:** mandatory under the *régime réel*; filed **exclusively electronically via impots.cm since 1 January 2019**; due **15 March** (DGE) / **15 April** (CIME) / **15 May** (others); penalties **25% + 1.5% per month**.

**NEEDS VERIFICATION:** the exact DSF form set and box structure for the *Système Normal* vs *Système Minimal de Trésorerie*; the precise file format accepted by impots.cm (whether a structured upload or on-screen entry); whether the ~37 *notes annexes* are transmitted as part of the same file.

**Model.** DSF is a `TaxDeclaration` of type `dsf_annual` with `period_type = 'year'`, plus:

| Column on `FiscalYear` (owned by `02-accounting.md`, specified here) | Notes |
|---|---|
| `dsf_filed_at` | `DATETIME` NULL |
| `dsf_reference` | `VARCHAR(60)` NULL. The impots.cm acknowledgement. |
| `dsf_declaration_id` | FK → `TaxDeclaration` RESTRICT, `UNIQUE`. |
| `dsf_filed_by` | FK `users` RESTRICT. |

**Hard reopen block.** Once `dsf_filed_at` is set, `ReopenFiscalYear` **refuses unconditionally**. There is no permission that overrides it and no `force` flag. Reopening a year whose DSF has been filed makes the filed statutory accounts differ from the books — the exact discrepancy a *contrôle fiscal* is designed to find. If a genuine error is discovered, the remedy is an **amending declaration** (`amends_declaration_id`) and correcting entries in the current open year, never a reopening. This is stated as an absolute because the first support ticket will ask for the override.

**Generation.** The DSF is built from the `dsf_line_code` mapping on `ChartOfAccount` (`02-accounting.md` fix) plus the balance sheet, income statement and cash-flow statement of `02-accounting.md`. Its generator is a **mapper, not a second accounting engine**: every DSF figure must be traceable to a trial-balance line, and a reconciliation report proves Σ mapped = Σ trial balance, with any unmapped account listed by name. An unmapped account **blocks DSF generation** — a silently dropped account is a wrong DSF that looks complete.

**Pre-filing checklist** (blocking, individually signed off, mirroring `02-accounting.md`'s `YearEndChecklist`): year hard-locked · auxiliary reconciliation passed · all 12 TVA declarations filed · all withholding declarations filed and reconciled to 447 · no unbalanced entries · no `SequenceGap` on `piece_no` · every account mapped to a `dsf_line_code` · notes annexes complete.

### 7.6 Due-date computation

```
dsf_due_date(fiscal_year, tax_centre_type) =
    DGE                 → 15 March  of year+1
    CIME                → 15 April  of year+1
    CDI | CSI | other   → 15 May    of year+1
```

Implemented as data on `TaxObligation`, not as a hardcoded `match`. Weekend/holiday roll-forward behaviour **NEEDS VERIFICATION**; until verified the system shows the statutory date with no adjustment and a note.

### 7.7 Accounting-file export during audit (FEC-equivalent)

**NEEDS VERIFICATION:** whether Cameroon's *Livre des Procédures Fiscales* imposes a dematerialised accounting-file submission (the French *FEC* analogue) during a *contrôle*. If it does, it is a critical export obligation with a mandated layout, and it belongs in this document.

**Interim position:** the system ships a **generic dematerialised accounting export** — one row per journal entry line with entry date, value date, journal code, `piece_no`, account code and label, partner type and id, label, debit, credit, lettering code, and the source document reference — in CSV and a fixed-width variant, with a documented schema. This satisfies a reasonable audit request regardless of the outcome, and if a mandated layout is later confirmed, only the formatter changes. **No claim of legal conformity to any named format is made until verified.**

---

## 8. Cross-module contracts

| Contract | Direction | Notes |
|---|---|---|
| `Tax::ResolveTaxCodeFor(subject, date)` | Fees, Inventory, Assets → Tax | Returns the effective `TaxCode` + snapshot rate. Batch signature `forLines(array)` per `00-core.md` §6.2 rule 5. |
| `Tax::ComputeLineTax(amount_ht, tax_code_id, date, direction)` | any → Tax | Returns `{tax_amount, deductible, non_deductible}`. |
| `Tax::ResolveWithholding(supplier_id, lines, date)` | Procurement → Tax | Batch. |
| `Procurement::CreateAssetFromInvoiceLines` | Procurement → Assets | Emits `procurement.asset.capitalised`; Assets owns the `Asset` record. |
| `Procurement::ReceiveStock` | Procurement → Inventory | Quantity + provisional cost; Inventory owns valuation. |
| `procurement.supplier_invoice.posted` / `.cancelled` | Procurement → Accounting | After-commit events. |
| `procurement.supplier_payment.paid` / `.voided` | Procurement → Accounting | |
| `procurement.withholding.recognised` | Procurement → Accounting, Tax | |
| `tax.declaration.generated` / `.filed` | Tax → Accounting, Reporting | |
| `Accounting::PostingRule` events added by this document | — | `supplier.invoice.posted`, `supplier.invoice.cancelled`, `supplier.credit_note.issued`, `supplier.payment.made`, `supplier.payment.voided`, `supplier.retention.withheld`, `supplier.retention.released`, `withholding.recognised`, `withholding.remitted`, `tax.tva.declared`, `tax.tva.settled`, `tax.credit.carried_forward`, `purchase.accrual.recognised` (4818), `purchase.accrual.reversed`. These extend the `02-accounting.md` C-list. |

---

## 9. Concurrency, sequences, deletion

**Concurrency additions** to `00-core.md` §11:

| Operation | Lock |
|---|---|
| Supplier payment allocation | `SELECT … FOR UPDATE` on `SupplierInvoice`; recompute outstanding inside the lock |
| Goods receipt against a PO line | `FOR UPDATE` on `PurchaseOrderLine`; `qty_received` updated inside |
| Invoice against a PO/receipt line | `FOR UPDATE` on `PurchaseOrderLine` and `GoodsReceiptLine` |
| Declaration generation | `FOR UPDATE` on the `TaxDeclaration` row; advisory lock on `(declaration_type, period)` |
| Prorata regularisation | `FOR UPDATE` on `VatProrata` + the fiscal year |
| Attestation issue | `FOR UPDATE` on the `SupplierPayment` |

**Sequences** (all gaps-permitted per `00-core.md` §12, row-locked, never `max()+1`): `REQ`, `BC`, `BR`, `FF`, `AVF`, `PF`, `ATT`. Each declares its uniqueness scope explicitly: all are **globally unique per series across all fiscal years**, with the year embedded in the format for legibility only. `JournalEntry.piece_no` remains gapless and is not affected by anything here.

**Deletion matrix additions** to `00-core.md` §10.5:

| Entity | Treatment |
|---|---|
| `Supplier`, `TaxCode`, `WithholdingRule`, `TaxDeclaration`, `SupplierInvoice`, `SupplierPayment`, `SupplierCreditNote`, `WithholdingAttestation`, `VatProrata` | **RESTRICT** — never deleted. `02-accounting.md` C5's **10-year AUDCIF retention** covers all of them; the global model observer that forbids hard deletion of accounting records must include these tables in its scope. |
| `PurchaseRequisition`, `PurchaseOrder`, `GoodsReceipt` | Deletable **only in `draft`**, enforced by a BEFORE DELETE trigger. Otherwise cancelled. |
| Line tables | `ON DELETE CASCADE` from their header, gated by the header's draft-only delete rule |
| `SupplierCategory`, `TaxDeclarationType`, `TaxObligation`, `WithholdingProfile` | **Archive flag**, never `SoftDeletes` (unique codes) |
| Actor FKs (`approved_by`, `received_by`, `issued_by`, `filed_by`, …) | RESTRICT |

---

## 10. Screens

**No mockup exists for procurement or tax.** `frontend images/` contains no supplier, purchase-order or tax screen, and `general setting.png` has no fiscal panel. `complete product overview.png` and `flow wizards.png` are the normative scope sources per `00-core.md` §17 and must be re-read when `09-ui.md` is written; the screen list below is derived from the domain, not from a mockup, and is flagged as such.

| Screen | Contents |
|---|---|
| **Settings → Fiscal Identity** | The §2.4 wizard step, also reachable as a settings panel. Read-only after confirmation except through the audited correction Action. |
| **Settings → Tax Configuration** | `TaxCode` list with effective-dating UI (close-and-successor, never in-place edit) · `WithholdingRule` + profiles · prorata per fiscal year with the computation working paper · `TaxObligation` calendar. Every unconfirmed row visibly badged "not configured — blocks use". |
| **Suppliers** | List (search by name/NIU/code, filter active/blocked/withholding status), profile with tabs: Details · Purchase Orders · Invoices · Payments · Credit Notes · Withholdings & Attestations · Documents · Statement. |
| **Purchase Requisitions** | Create, submit, approve queue with threshold routing. |
| **Purchase Orders** | Create from requisition or blank, keyboard-first line grid, print *bon de commande*, send, amendment history. |
| **Goods Receipt** | Receive against PO, accept/reject quantities, discrepancy capture. |
| **Supplier Invoices** | Capture (with the scanned original attached), match panel showing PO ↔ receipt ↔ invoice per line with variances highlighted, tax panel showing per-line TVA and withholding with the applied rule named, approve, post. |
| **Supplier Payments** | Select supplier → open invoices with outstanding → allocate → method → withholding preview → pay → print advice + attestation. |
| **Payables Dashboard** | Aged payables, due this week, open commitments, receipt-not-invoiced, match exceptions, duplicate risk. |
| **Tax Dashboard** | Current-period TVA position, withholding to remit, obligation calendar with countdown, unfiled prior periods, prorata in force, DSF status and checklist. |

Line grids follow the `01-assessment.md` marks-entry discipline: keyboard-first, Alpine-local state, batched save, **≤1 request per save**.

---

## 11. Test obligations

1. **Money conservation:** for every supplier invoice, `Σ line.amount_ht + Σ line.tax_amount = total_ttc` and `Σ deductible + Σ non_deductible = tax_total`, property-tested over random line sets.
2. **Prorata allocation:** `Money::allocate` conserves; the §5.4 worked example reproduces to the franc.
3. **Rate selection:** 10-year daily sweep asserting exactly one effective `TaxCode` per code and exactly one `WithholdingRule` per code+type; boundary tests at `effective_from` and `effective_to` from both directions.
4. **Immutability:** attempting to edit `rate_bp` on a referenced `TaxCode` throws; attempting to edit an approved PO throws; attempting to edit an issued attestation throws.
5. **Duplicate payment:** inserting a second `SupplierInvoice` with the same `(supplier_id, supplier_invoice_no)` fails at the database, not only in validation.
6. **Three-way match:** the §4.4 worked example produces exactly the two named exceptions and blocks approval.
7. **Withholding:** the §6.4 worked example reproduces to the franc; an unmatched rule sets `withholding_unresolved` and blocks approval; a supplier with an expired exemption is withheld from.
8. **Attestation ↔ declaration ↔ 447** reconcile for a generated period; a manually inserted 447 movement without an attestation blocks filing.
9. **Payment void** cascades: allocations reversed, invoice re-opened, lettering undone, attestation cancelled, reversal entry in the current open period.
10. **DSF reopen block:** setting `dsf_filed_at` then calling `ReopenFiscalYear` throws, with no permission or flag that permits it.
11. **Unmapped account** blocks DSF generation and names the account.
12. **Fiscal identity gate:** rendering any invoice, receipt or attestation with a null `niu` throws.
13. **Exemption gate:** invoicing tuition exempt with an expired accreditation throws.
14. **Segregation of duties:** creator cannot approve; approver cannot pay; recorder cannot void. One test per pair.
15. **Offline:** with all outbound network blocked, the full P2P chain and declaration generation complete (`00-core.md` §3). Nothing in this module may require the internet.
16. **Empty-seed refusal:** with no confirmed `TaxCode` / `WithholdingRule`, the module returns a configuration error, never a silent zero.

---

## 12. NEEDS VERIFICATION register

Consolidated. Every item ships **empty and blocking**, per `00-core.md` §16 ("a wrong seeded value is more dangerous than an empty field"). These roll up into blocking gate 6 (Phase 4).

| # | Item | Blocks |
|---|---|---|
| 1 | Exact CGI article for the education TVA exemption (art. 120 vs art. 128 both cited) | `TaxCode.exemption_legal_ref` |
| 2 | NIU format specification | NIU validation rule |
| 3 | `legal_form` enumeration available to Cameroonian private schools | Wizard field |
| 4 | Whether *régime simplifié* may be TVA-registered | §2.2 invariant 2 |
| 5 | Which supplies (if any) are zero-rated vs exempt | Prorata numerator |
| 6 | TVA registration turnover threshold | Setup guidance |
| 7 | TVA return deadline and official form/box codes | `TaxDeclarationLine.line_code`, filing |
| 8 | Prorata formula per the CGI, and its rounding rule | `TaxSettings.prorata_rounding` |
| 9 | Whether multi-year capital-goods prorata regularisation applies | `VatProrataRegularisation` |
| 10 | Deduction window for late input-VAT claims | §5.6 |
| 11 | SYSCOHADA sub-accounts: 4451/4452 TVA, 447 sub-accounts, the non-deductible-VAT expense account | Posting rules |
| 12 | Full current withholding rate table incl. rent, commissions, non-residents; whether CAC is included in the quoted rates | `WithholdingRule` seed |
| 13 | Withholding base: HT or TTC, per type | `WithholdingRule.base` |
| 14 | Withholding minimum-payment threshold | `WithholdingRule.minimum_base` |
| 15 | Whether withholding is legally recognised on invoice or on payment | `TaxSettings.withholding_recognition` |
| 16 | Attestation de retenue à la source — official layout/content requirements | `10-documents.md` template |
| 17 | DSF form set and box structure (Système Normal vs SMT); impots.cm accepted file format; whether notes annexes are transmitted with it | DSF generator |
| 18 | Weekend/holiday roll-forward on statutory due dates | `TaxObligation.due_rule` |
| 19 | Whether the LPF imposes an FEC-style dematerialised accounting-file submission during audit, and its layout | §7.7 |
| 20 | Bank/mobile-money disbursement file layouts | §4.7 |
| 21 | Employee-vs-contractor classification criteria (with a labour lawyer) | §6.5 warning thresholds |

---

## 13. Open decisions for the accountant

To be answered in the same session that closes blocking gate 6:

1. Is the school TVA-registered today? If so, from when, and what prorata was applied last year?
2. Which activities does the school actually run that the 2022 Finance Law made taxable — canteen, uniforms, textbooks, transport — and are they invoiced separately from tuition today?
3. Does the school currently withhold on supplier payments? If not, what is the exposure, and does it want the system to begin?
4. Withholding recognised on invoice or on payment?
5. Système Normal or SMT for the DSF?
6. Which tax centre, and therefore which DSF deadline?
7. Does the school hold any withholding exemption certificates from suppliers?
8. Retenue de garantie: does the school run works contracts that use it?
