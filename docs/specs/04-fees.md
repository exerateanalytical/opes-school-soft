# 04 — Fees, Invoicing, Payments & Receivables

**Version:** 2.0
**Status:** Draft for review
**Module:** `app/Modules/Fees`
**Binding parent:** `00-core.md`. Where this document and `00-core.md` differ, `00-core.md` wins.
**Build phase:** 6 (after Ledger, Phase 4, and Procurement & Tax, Phase 5).

**Cross-references — not duplicated here:**
`00-core.md` §7 (money, rates, `Money::allocate`), §10 (constraints, deletion matrix, optimistic locking), §11 (concurrency), §12 (sequences), §14 (audit & `DocumentPrintLog`).
`02-accounting.md` — chart of accounts, `PostingRule`/`PostingRuleLine`, `JournalEntry`, `Lettering`, `AccountingPeriod` lock states, partner/auxiliary layer, reversal semantics.
`03-tax-procurement.md` — `TaxCode`, TVA treatment, prorata, `SchoolProfile` fiscal identity (NIU, régime, accreditation number).
`07-students.md` — `Student`, `Enrollment`, `EnrollmentSegment`, guardian links and the guardian authorization matrix.
`06-assets-stores.md` — library fines (the second debt stream, §20).
`09-ui.md` — global navigation shell, responsive rules. `10-documents.md` — print templates.

---

## 1. Scope and position

This module owns the **student receivable**: what a student is billed, what is adjusted, what is paid, what is refunded, what is credited, what is written off, and what remains owing. It does **not** own the ledger; every financial consequence is emitted as a domain event consumed by `02-accounting`'s posting engine. It does **not** own tax rates; it carries `tax_code_id` and defers to `03-tax-procurement`.

It contains the highest-traffic screen in the product (Fee Collection, §17) and the product's most legally exposed arithmetic (agent-collected funds, §7; revenue cut-off, §6).

### 1.1 Design axioms

| # | Axiom | Consequence |
|---|---|---|
| A1 | **Balance is computed, never stored.** | No `Invoice.balance` column. Every read derives it from §5's formula. Prevents drift; requires §11's locking for check-then-act. |
| A2 | **Allocation is line-level.** | Adjustments, credit notes and write-offs are also line-level, or reconciliation is impossible (C2). |
| A3 | **Financial records are append-only.** | Nothing is edited or deleted. Corrections are new records: void, credit note, reversal. Ten-year retention per `02-accounting` C5. |
| A4 | **The school is not always the principal.** | APEE and exam registration money is a liability, never revenue (C5). |
| A5 | **No netting across students.** | OHADA non-compensation. A student in credit is a liability in 4191 (C9). |
| A6 | **Payment may precede invoice.** | The normal Cameroonian case. Unallocated credit is a first-class state (C10). |

---

## 2. Reference data

### 2.1 `fee_categories`

Groups fee items for the "Income by Category" panel of the Finance Dashboard and for the Fee Statement grouping.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(30) `utf8mb4_0900_as_cs` | UNIQUE |
| `name`, `name_fr` | VARCHAR(120) | |
| `display_order` | SMALLINT | |
| `is_archived` | BOOL | Archive flag, not `SoftDeletes` (00-core §10.5) |

`UNIQUE(code)`. Deletion: RESTRICT where a `FeeItem` references it.

### 2.2 `fee_items`

The billable thing. **RESTRICT on delete** (00-core §10.5).

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(30) `as_cs` | UNIQUE |
| `name`, `name_fr` | VARCHAR(160) | |
| `fee_category_id` | FK → `fee_categories` | RESTRICT |
| `collection_basis` | ENUM(`own_revenue`,`agent_for_third_party`) | **C5.** NOT NULL, no default — the developer must choose |
| `third_party_fund_id` | FK → `third_party_funds` NULL | NOT NULL iff `collection_basis = agent_for_third_party` |
| `revenue_account_id` | FK → `chart_of_accounts` NULL | NOT NULL iff `collection_basis = own_revenue` |
| `recognition_method` | ENUM(`on_issue`,`straight_line_over_period`,`on_collection`) | **C4.** Default `on_issue` |
| `tax_code_id` | FK → `tax_codes` NULL | `03-tax-procurement` |
| `is_refundable` | BOOL | Deposits/cautions are refundable |
| `is_mandatory` | BOOL | Optional items require explicit opt-in per enrollment |
| `default_recurrence` | ENUM(`per_year`,`per_term`,`per_month`,`one_off`) | |
| `asset_or_service_note` | TEXT NULL | For the fee schedule printout |
| `is_archived` | BOOL | |

**CHECK constraints:**
```sql
CHECK ( (collection_basis = 'own_revenue'          AND revenue_account_id IS NOT NULL AND third_party_fund_id IS NULL)
     OR (collection_basis = 'agent_for_third_party' AND third_party_fund_id IS NOT NULL AND revenue_account_id IS NULL) )
```

#### 2.2.1 Audience and exclusions (H)

v1's single-valued `applies_to` could not express "new boarders", and `scholarship_excluded` is an exclusion rule wearing an audience's clothes. Replaced by two criterion sets:

`fee_item_audience_criteria` — **conjunctive**; all rows must match for the item to apply.

| Column | Notes |
|---|---|
| `fee_item_id` | FK, CASCADE (pure child of a config row, no financial history) |
| `dimension` | ENUM(`enrollment_status`,`gender`,`boarding_status`,`transport_status`,`stream`,`class_level`,`school_section`,`nationality`,`house`) |
| `operator` | ENUM(`in`,`not_in`) |
| `values_json` | JSON array of scalar ids/codes |

`fee_item_exclusion_criteria` — same shape; **disjunctive**: any matching row excludes the student.

`enrollment_status` values `new | returning | repeating` are **derived from enrollment history** (`07-students` C4), never from a mutable person-level flag.

> **Timing rule (H).** Invoicing normally runs in September; hostel and transport allocation frequently completes later, and status can change mid-year. Therefore: **audience criteria are evaluated at invoice-issue time and the result is frozen into the invoice.** A day student who becomes a boarder in November is **not** retro-billed by re-running the invoice; the operator issues a **supplementary invoice** (§4.6) for the boarding item, pro-rated over the remaining service period. A boarder who becomes a day student receives a **credit note** (§9) for the unearned portion, computed by the same pro-rata service-period arithmetic as `WithdrawalSettlement` (§13). Silent re-issue is forbidden — `IssueInvoices` is idempotent on `UNIQUE(enrollment_id, fee_structure_id, term_id)` and will not produce a second invoice.

### 2.3 `third_party_funds` — **C5**

APEE (Association des Parents d'Élèves et Enseignants) contributions and GCE / BEPC / Probatoire / Baccalauréat registration fees are **collected as an agent**. Booking them in class 7 overstates turnover, which drives the minimum tax / acompte d'IS — **the school pays tax on money that is not its own** — inflates the TVA threshold, makes the DSF materially false, and destroys the ability to prove to the APEE or the exam board what is held on their behalf.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(30) `as_cs` | UNIQUE |
| `name`, `name_fr` | VARCHAR(160) | e.g. "APEE Heritage Academy", "GCE Board registration" |
| `beneficiary_type` | ENUM(`apee`,`exam_board`,`ministry`,`insurer`,`other`) | |
| `beneficiary_name` | VARCHAR(200) | |
| `beneficiary_niu` | VARCHAR(30) NULL | |
| `liability_account_id` | FK → `chart_of_accounts` | **must be a class 47 account**; enforced by an Action-level validator reading `code LIKE '47%'` |
| `remittance_frequency` | ENUM(`on_demand`,`monthly`,`termly`,`annual`) | |
| `remittance_due_day` | TINYINT NULL | |
| `is_active` | BOOL | |

> **NEEDS VERIFICATION — the exact SYSCOHADA sub-account for funds held on behalf of a parents' association or an examination board.** Compte 47 *Débiteurs et créditeurs divers* is the correct class, and `4:` sub-accounts such as 471/472/478 are candidates, but the appropriate subdivision for third-party collection in a Cameroonian school context has not been confirmed against the OHADA revised plan or a Cameroonian practitioner. **The seed ships empty.** The school's accountant selects the account in the setup wizard; the module refuses to issue an `agent_for_third_party` fee item until an account is chosen (00-core §16 blocking-gate discipline).

`third_party_fund_remittances`

| Column | Notes |
|---|---|
| `id`, `third_party_fund_id` | |
| `period_start`, `period_end` | DATE |
| `amount_collected` | BIGINT SIGNED — Σ receipts allocated to agent items in the window |
| `amount_remitted` | BIGINT SIGNED |
| `remitted_on`, `method`, `reference` | |
| `status` | ENUM(`draft`,`remitted`,`cancelled`) |
| `journal_entry_id` | FK NULL, RESTRICT |
| `approved_by`, `approved_at` | |

`UNIQUE(third_party_fund_id, period_start, period_end)` where status ≠ `cancelled` (generated-column pattern, 00-core §10.1).

**Statement of third-party funds held and remitted** — a standing report: opening held, collected, remitted, closing held, per fund per period. It must tie exactly to the class 47 account balance in the GL; a nightly integrity job asserts equality and raises on divergence.

### 2.4 `payment_methods` — **must be a table, not an enum (H)**

v1's enum could not hold `treasury_account_id`, which is a foreign key.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(30) `as_cs` | UNIQUE. `cash`, `momo_mtn`, `momo_orange`, `bank_transfer`, `cheque`, `bank_deposit`, `card`, `in_kind` |
| `name`, `name_fr` | VARCHAR(120) | |
| `treasury_account_id` | FK → `chart_of_accounts` | RESTRICT. Cash → 57x; bank → 521x; **mobile money → 552 *Téléphone Portable* under 55 *Instruments de monnaie électronique*** (02-accounting; **not** a bank sub-account) |
| `pending_account_id` | FK → `chart_of_accounts` NULL | Required when `clearing_model = deferred`. Cheques land in *effets à l'encaissement* until cleared. **NEEDS VERIFICATION — the exact compte 51 subdivision (5:) for effets remis à l'encaissement in the revised SYSCOHADA plan.** Ships empty; configured by the accountant. |
| `clearing_model` | ENUM(`immediate`,`deferred`) | `cheque` and `bank_deposit` are `deferred` |
| `requires_reference` | BOOL | MoMo transaction id, cheque number, transfer reference |
| `reference_pattern` | VARCHAR(120) NULL | Optional regex, validated at capture |
| `fee_bearer` | ENUM(`school`,`payer`,`none`) | |
| `fee_model` | ENUM(`none`,`fixed`,`percentage`) | |
| `fee_fixed_amount` | BIGINT SIGNED NULL | |
| `fee_rate_bp` | BIGINT NULL | Integer basis points (00-core §7.2) |
| `fee_account_id` | FK NULL | **6317** for mobile money commission |
| `rounds_tendered_to` | INT NULL | See §16 |
| `in_kind_valuation_basis` | ENUM(`invoice`,`market_quote`,`independent_valuation`) NULL | Required when `code = in_kind` |
| `is_active`, `display_order` | | |

**CHECK:** `(fee_model='none' AND fee_account_id IS NULL) OR (fee_model<>'none' AND fee_account_id IS NOT NULL)`
**CHECK:** `(clearing_model='immediate' AND pending_account_id IS NULL) OR (clearing_model='deferred' AND pending_account_id IS NOT NULL)`

> **`waiver` and `scholarship` are removed from payment methods (H).** A waiver is not treasury; no cash moves. They are `FeeAdjustment.reason_type` values (§8). A migration from any v1 data maps them accordingly.

> **Why `fee_account_id` matters.** v1's worked example posted the full 350,000 FCFA to treasury and ignored the operator commission. The MTN/Orange settlement statement shows the net; the ledger showed the gross; the account could never be reconciled. See the worked example in §15.6.

### 2.5 `fee_structures` and `fee_structure_lines`

`fee_structures`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `academic_year_id` | FK → `academic_years` | RESTRICT |
| `school_section_id` | FK | RESTRICT |
| `class_level_id` | BIGINT UNSIGNED **NOT NULL DEFAULT 0** | Sentinel row id 0 = "any level" |
| `stream_id` | BIGINT UNSIGNED **NOT NULL DEFAULT 0** | Sentinel row id 0 = "any stream" |
| `enrollment_status_scope` | ENUM(`any`,`new`,`returning`,`repeating`) NOT NULL DEFAULT `any` | |
| `boarding_scope` | ENUM(`any`,`day`,`boarding`) NOT NULL DEFAULT `any` | |
| `name` | VARCHAR(160) | |
| `status` | ENUM(`draft`,`active`,`archived`) | |
| `version` | INT | Bumped on every published change; invoices stamp the version |
| `effective_from`, `effective_to` | DATE | `effective_to` **exclusive** |

**`UNIQUE(academic_year_id, school_section_id, class_level_id, stream_id, enrollment_status_scope, boarding_scope, effective_from)`**

> **Why sentinel rows (H).** MySQL UNIQUE indexes treat every NULL as distinct, so a nullable `stream_id` in the key permits unlimited duplicate rows — and then two structures match one student with no resolution order, and two developers bill two different amounts. `Stream` and `ClassLevel` therefore each carry a reserved row with `id = 0`, code `__ANY__`, `is_system = true`, un-deletable and hidden from pickers.

**Resolution rule — stated, not implied:** *most specific match wins*. Specificity is scored by counting non-sentinel, non-`any` discriminators: `class_level_id`(8) + `stream_id`(4) + `boarding_scope`(2) + `enrollment_status_scope`(1). The highest score among matching active structures wins. **A tie is a configuration error**, rejected at save time by `ValidateFeeStructureCoverage`, not resolved silently at invoicing time. The validator enumerates the cartesian product of (level × stream × boarding × status) for the section and reports every cell with 0 matches or ≥2 top-scoring matches.

`fee_structure_lines`

| Column | Notes |
|---|---|
| `id`, `fee_structure_id` | FK CASCADE (config child) |
| `fee_item_id` | FK RESTRICT |
| `amount` | BIGINT SIGNED, `CHECK (amount >= 0)` |
| `term_id` | FK → `assessment_periods` NULL — the term this line is billed in; NULL = annual |
| `service_period_start`, `service_period_end` | DATE NULL — defaults for §6 |
| `is_optional` | BOOL |
| `display_order` | SMALLINT |

`UNIQUE(fee_structure_id, fee_item_id, term_id)`.

### 2.6 `installment_plans` and `installment_plan_lines`

| `installment_plans` | Notes |
|---|---|
| `id`, `academic_year_id`, `name` | |
| `fee_structure_id` | FK NULL — plan may be structure-specific or global |
| `basis` | ENUM(`percentage`,`fixed`) |
| `is_default` | BOOL — one default per (academic_year, structure), generated-column UNIQUE |

| `installment_plan_lines` | Notes |
|---|---|
| `sequence_no` | TINYINT, 1-based |
| `label`, `label_fr` | "1ère tranche" |
| `percentage_bp` | BIGINT NULL — basis points |
| `fixed_amount` | BIGINT SIGNED NULL |
| `due_date` | DATE, or `due_offset_days` from term start |

`UNIQUE(installment_plan_id, sequence_no)`.

**Sum constraint (H), enforced in `SaveInstallmentPlan`:**
- `basis = percentage` → `Σ percentage_bp = 1 000 000` (= 100%) exactly.
- `basis = fixed` → validated against the invoice total at application time, not at save.

**Residual rule.** Percentage plans applied to integer FCFA leave francs unassigned. Amounts are produced by `Money::allocate([...ratios])` (largest-remainder, 00-core §7.3) and **the last instalment absorbs the residual**. Worked example in §15.2.

---

## 3. Entity: `invoices`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `invoice_no` | VARCHAR(40) `as_cs` | **UNIQUE globally.** Gaps permitted (00-core §12). Format configurable, §14 |
| `enrollment_id` | FK → `enrollments` | RESTRICT |
| `student_id` | FK → `students` | RESTRICT. Denormalised for query paths that must not join through enrollment |
| `academic_year_id` | FK | RESTRICT. **Dual calendar** (02-accounting C3) |
| `fiscal_year_id` | FK | RESTRICT |
| `fee_structure_id` | FK NULL | RESTRICT |
| `fee_structure_version` | INT NULL | The version billed under |
| `term_id` | FK → `assessment_periods` NULL | RESTRICT |
| `installment_plan_id` | FK NULL | RESTRICT |
| `type` | ENUM(`standard`,`supplementary`,`opening_balance`) | |
| `issue_date` | DATE NOT NULL | |
| `due_date` | DATE NOT NULL | Header-level fallback; the operative dates are per instalment (§3.2) |
| `currency` | CHAR(3) NOT NULL DEFAULT 'XAF' | Ledger is XAF-only (02-accounting) |
| `status` | ENUM(`draft`,`issued`,`cancelled`) | See §3.1 |
| `cancelled_at`, `cancelled_by`, `cancellation_reason` | | RESTRICT on actor |
| `is_migration` | BOOL DEFAULT 0 | Migrated invoices do **not** re-trigger posting rules (02-accounting) |
| `journal_entry_id` | FK NULL | RESTRICT |
| `notes` | TEXT NULL | |
| `version` | INT NOT NULL DEFAULT 0 | Optimistic lock (00-core §10.6) |
| `idempotency_key` | VARCHAR(80) NULL | UNIQUE |
| timestamps, `created_by` | | |

**Constraints**
- `UNIQUE(invoice_no)`
- `UNIQUE(enrollment_id, fee_structure_id, term_id)` — **issue idempotency** (00-core §10.4). Because `fee_structure_id` and `term_id` are nullable, both are stored with the sentinel `0` convention in a companion generated column pair used by the index, for the same MySQL-NULL reason as §2.5.
- `CHECK (due_date >= issue_date)`
- `CHECK (issue_date` within both `academic_year` and `fiscal_year` bounds`)` — enforced in-Action (a CHECK cannot subquery in MySQL 8) and asserted by the nightly integrity job.

**ON DELETE:** never cascaded into (00-core §10.5). `enrollment_id`, `student_id`, `academic_year_id`, `fiscal_year_id`, all actor FKs: **RESTRICT**.

### 3.1 Status semantics

| Status | Meaning | Counted in balance? |
|---|---|---|
| `draft` | Created by a batch run, not yet issued. No ledger entry. Freely editable and deletable. | **No** |
| `issued` | Posted. Immutable except `notes` and status→`cancelled`. | **Yes** |
| `cancelled` | Cancelled **in full and before any allocation, adjustment or credit note exists**. Reversing journal entry raised. | **No** |

**Cancellation is narrow by design.** `CancelInvoice` refuses if any of: a non-voided `PaymentAllocation` targets any of its lines; a `FeeAdjustment` exists; a `CreditNote` line references it; a `WriteOff` exists; the invoice is `is_migration`. Everything else is corrected by **credit note** (§9) — which is what OHADA requires anyway (a *facture d'avoir*), and what leaves an audit trail.

> **`overdue` is deliberately not a status column (H).** It is a derived state, and it is derived **per instalment**, not per invoice: `outstanding_for_instalment > 0 AND instalment.due_date < as_of AND invoice.status = 'issued'`. A stored flag needs a nightly job and is stale between runs. The derived predicate is exposed as an Eloquent scope `overdueAsOf(Carbon $asOf)` and as a generated report column. An invoice can be simultaneously *current* on tranche 3 and *overdue* on tranche 1; a single header flag cannot say that.

### 3.2 `invoice_lines`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `invoice_id` | FK | RESTRICT (never cascade into a financial record) |
| `line_no` | SMALLINT | |
| `fee_item_id` | FK NULL | **RESTRICT-free, non-enforcing, carried for reporting.** Nullable so a deleted-then-archived item does not break history; but present so "how much did we bill for canteen this year" is answerable. The snapshot columns below are authoritative for money |
| `description` | VARCHAR(200) | **Snapshot** of the fee item name at issue |
| `description_fr` | VARCHAR(200) | |
| `fee_category_code` | VARCHAR(30) | Snapshot |
| `collection_basis` | ENUM(`own_revenue`,`agent_for_third_party`) | **Snapshot.** A later change to the fee item must not reclassify historical revenue |
| `third_party_fund_id` | FK NULL | Snapshot |
| `revenue_account_id` | FK NULL | Snapshot |
| `recognition_method` | ENUM(...) | Snapshot |
| `tax_code_id` | FK NULL | Snapshot |
| `quantity` | INT NOT NULL DEFAULT 1 | |
| `unit_amount` | BIGINT SIGNED, `CHECK (unit_amount >= 0)` | |
| `amount` | BIGINT SIGNED, `CHECK (amount >= 0)` | `= quantity * unit_amount`, asserted in-Action |
| `tax_amount` | BIGINT SIGNED NOT NULL DEFAULT 0 | |
| `service_period_start` | DATE NULL | **C4** |
| `service_period_end` | DATE NULL | **C4** |
| `analytic_values_json` | JSON NULL | Analytic dimension members (02-accounting) |

`UNIQUE(invoice_id, line_no)`.
**CHECK:** `(service_period_start IS NULL) = (service_period_end IS NULL)`
**CHECK:** `service_period_end >= service_period_start`
**In-Action invariant:** `recognition_method = 'straight_line_over_period'` ⇒ both service-period dates NOT NULL.

### 3.3 `invoice_installments`

Aging is by **instalment due date, not invoice date** (H). Instalments are therefore first-class rows, not a rendering of the plan.

| Column | Notes |
|---|---|
| `id`, `invoice_id` | FK RESTRICT |
| `sequence_no` | TINYINT |
| `label`, `label_fr` | Snapshot from the plan line |
| `amount` | BIGINT SIGNED, `CHECK (amount >= 0)` |
| `due_date` | DATE NOT NULL |
| `is_cancelled` | BOOL DEFAULT 0 — set by `WithdrawalSettlement` for unbilled future tranches |
| `cancelled_reason` | VARCHAR(200) NULL |

`UNIQUE(invoice_id, sequence_no)`.
**In-Action invariant:** `Σ amount WHERE NOT is_cancelled` + `Σ amount WHERE is_cancelled` `= invoice gross total`, always. Cancelling a tranche never silently reduces the invoice; it is always paired with a credit note (§9) or a write-off (§10).

Instalments are a **payment schedule over the invoice as a whole**, not a partition of lines. Allocation is line-level (A2); instalment satisfaction is derived by ordering: an instalment is satisfied when cumulative non-voided allocated + adjusted + credited + written-off across the whole invoice reaches its cumulative amount. This is stated explicitly because the two granularities coexist and a developer will otherwise invent a third.

---

## 4. Invoicing Actions

### 4.1 `IssueInvoices` (batch)

Signature: `IssueInvoices::forEnrollments(array $enrollmentIds, IssueInvoiceOptions $o): IssueInvoiceResult`
Batch signature is mandatory (00-core §6.2 rule 5). There is **no** single-enrollment public entry point; the UI calls the batch with one id.

Steps, in one transaction per chunk of 200 enrollments:
1. Authorize (`fees.invoice.issue`).
2. Resolve the fee structure per enrollment (§2.5 resolution rule). Unresolvable or ambiguous → collect into `result->rejected[]`, do not throw the batch.
3. Evaluate audience/exclusion criteria per fee item; freeze the outcome.
4. Compute tax per line via `03-tax-procurement`'s `ResolveTax` (cross-module Action, batch signature).
5. Build instalments from the plan; `Money::allocate` for percentage plans; last tranche absorbs the residual.
6. Allocate `invoice_no` from the row-locked sequence (00-core §12, gaps permitted).
7. Insert; set `status = 'issued'`.
8. Build `RevenueRecognitionSchedule` rows for `straight_line_over_period` lines (§6).
9. Auto-allocate any unallocated student credit, oldest-first (§12.4) — **this is what stops a pre-paying parent appearing as a defaulter (C10)**.
10. Dispatch `FeeInvoiceIssued` **after commit** (00-core §6.2 rule 6).

Idempotency: the Action-level `idempotency_key` plus the `UNIQUE(enrollment_id, fee_structure_id, term_id)` backstop. A duplicate returns the existing invoice rather than raising.

### 4.2 `CancelInvoice`
Guards per §3.1. Emits `fee.invoice.cancelled` (a posting event `02-accounting` C-list adds). Reversal is dated **in the earliest open accounting period**, never the original date (02-accounting C9).

### 4.3 `PreviewInvoiceRun`
Read-only dry run producing the same result object without writes. The invoice batch screen requires a preview before commit — a mis-scoped run across 1,200 students is not correctable by deletion (A3), only by 1,200 credit notes.

### 4.4 `BulkAdjustFees` (H)
Applies a `FeeAdjustment` across a roster: class group, hostel block, transport route, or an explicit enrollment list. One approval covers the batch; each resulting adjustment is an individual audited row with the batch id stamped on it.

### 4.5 `RecalculateInvoiceTotals` — does not exist
Deliberately absent. Totals are derived (A1).

### 4.6 `IssueSupplementaryInvoice`
For mid-year status changes (§2.2.1). Type `supplementary`; excluded from the `UNIQUE(enrollment_id, fee_structure_id, term_id)` index by carrying `fee_structure_id = NULL`.

---

## 5. The balance formula — **C1**

### 5.1 Statement

For an **invoice line** ℓ, as at date *d*:

```
outstanding(ℓ, d) =
      invoiced(ℓ)
    − adjustments(ℓ, d)
    − credit_notes(ℓ, d)
    − write_offs(ℓ, d)
    − allocated(ℓ, d)
```

where

```
invoiced(ℓ)        = ℓ.amount + ℓ.tax_amount
                     for the parent invoice WHERE invoice.status = 'issued'
                     (draft and cancelled invoices contribute 0)

adjustments(ℓ, d)  = Σ a.amount
                     FROM fee_adjustments a
                     WHERE a.invoice_line_id = ℓ.id
                       AND a.status = 'approved'
                       AND a.effective_date <= d
                     (a.amount is SIGNED: a surcharge is negative and INCREASES outstanding)

credit_notes(ℓ, d) = Σ cnl.amount
                     FROM credit_note_lines cnl JOIN credit_notes cn
                     WHERE cnl.invoice_line_id = ℓ.id
                       AND cn.status = 'issued'
                       AND cn.issue_date <= d

write_offs(ℓ, d)   = Σ wl.amount
                     FROM write_off_lines wl JOIN write_offs w
                     WHERE wl.invoice_line_id = ℓ.id
                       AND w.status = 'approved'
                       AND w.approved_on <= d

allocated(ℓ, d)    = Σ pa.amount
                     FROM payment_allocations pa JOIN payments p
                     WHERE pa.invoice_line_id = ℓ.id
                       AND pa.reversed_at IS NULL
                       AND p.value_date <= d
                       AND NOT EXISTS (SELECT 1 FROM payment_voids v
                                       WHERE v.payment_id = p.id AND v.status = 'confirmed')
                       AND p.clearing_state <> 'bounced'
```

Invoice outstanding = Σ over its lines. Student outstanding = Σ over issued invoices for the student, **minus** `unallocated_credit(student, d)` **for presentation only** — never for ledger classification (A5, C9).

### 5.2 The two exclusions that C1 is about

`AND NOT EXISTS (… payment_voids …)` and `AND p.clearing_state <> 'bounced'` are the entire defect. v1 counted allocations of voided payments, so **voiding a fully-allocating payment left the parent showing as paid, forever** — and a bounced cheque did the same. Both are cheap to omit and invisible until a parent disputes a balance a year later.

**Mandatory Pest tests (release-blocking):**
- `void_of_fully_allocating_payment_restores_full_balance` — invoice 350 000; payment 350 000 fully allocated; assert outstanding 0; void; assert outstanding **350 000**.
- `bounced_cheque_restores_full_balance` — identical with `clearing_state` transitioning to `bounced`.
- `voided_payment_does_not_appear_on_fee_statement_as_credit` — it appears as a credit **and** a matching debit reversal, netting to zero, never as a silent disappearance.
- Property test: for any random sequence of invoice/adjust/credit/pay/void/refund operations, `Σ outstanding(ℓ) ≥ 0` unless an over-allocation guard was explicitly bypassed by a migration import.

### 5.3 Line-level invariant, checked under lock

```
Σ allocations(ℓ) + Σ adjustments(ℓ) + Σ credit_notes(ℓ) + Σ write_offs(ℓ)  ≤  invoiced(ℓ)
```

Checked inside the `FOR UPDATE` window of §11 for every mutation that touches any of the four terms. Violation aborts the transaction; there is no partial write.

---

## 6. Revenue recognition and cut-off — **C4**

### 6.1 The problem

A September invoice for annual tuition of 350 000 FCFA recognises 100% of revenue in exercice N. But the OHADA exercice is **1 January – 31 December** (02-accounting), and roughly 60% of the teaching service is delivered in N+1. Recognising it all in N overstates the 31 December result, the IS base and the minimum-tax base. The OHADA *principe d'indépendance des exercices* requires accrual.

### 6.2 `revenue_recognition_schedules`

| Column | Notes |
|---|---|
| `id`, `invoice_line_id` | FK RESTRICT |
| `fiscal_year_id`, `period_month` | The accounting month the portion belongs to |
| `amount` | BIGINT SIGNED |
| `status` | ENUM(`scheduled`,`recognized`,`deferred`,`reversed`) |
| `journal_entry_id` | FK NULL RESTRICT |

`UNIQUE(invoice_line_id, fiscal_year_id, period_month)`.

Built by `BuildRecognitionSchedule` at issue, using `Money::allocate` over the number of **calendar days** in each month within `[service_period_start, service_period_end]`. Days, not months, because a service period rarely starts on the 1st. The final month absorbs the residual.

### 6.3 The year-end deferral posting

At 31 December, `RecognizeDeferredRevenue` computes, per line:
```
unearned = Σ schedule.amount WHERE period is after the fiscal year end
```
and posts, for the aggregate per revenue account:
```
Dr  7xxx  (the line's revenue account)      unearned
    Cr  477  Produits constatés d'avance                unearned
```
reversed on **1 January** of the following exercice by an automatically generated counter-entry carrying `reverses_entry_id` (02-accounting C9).

`recognition_method = 'on_collection'` lines are not recognised at issue at all; the credit at issue goes to **4191 Clients, avances et acomptes reçus** and moves to revenue as cash arrives. Reserved for genuinely contingent items.

### 6.4 Accounts to seed
**476** Charges constatées d'avance · **477** Produits constatés d'avance · **4181** Clients, factures à établir · **4191** Clients, avances et acomptes reçus. These four are named in the audit brief and are used by this module. Their exact 5-digit school extensions are the school's choice.

Worked example: §15.7.

---

## 7. Agent-collected funds — **C5**

An `agent_for_third_party` line **never touches class 7**. Posting at issue:

```
Dr  4111  Clients — <student partner>          amount
    Cr  47xx  <third-party fund liability>              amount
```

On collection, the treasury debit is identical to any other receipt; the 4111 credit clears. On remittance:

```
Dr  47xx  <third-party fund liability>         remitted
    Cr  521x / 571  treasury                            remitted
```

**Turnover reported for the acompte d'IS, the minimum tax and the TVA threshold excludes agent lines entirely.** The finance dashboard's "Total Revenue" tile therefore has two figures behind it: *gross collected* and *own revenue*. The tile shows own revenue; a tooltip shows funds held on behalf of third parties. Showing gross would reproduce exactly the misstatement C5 exists to prevent.

Worked example: §15.8.

---

## 8. `fee_adjustments` — **C2**

v1's `FeeAdjustment` had **no foreign key to anything** — no student, no invoice, no line — yet the balance formula subtracted it. It was unimplementable.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `reference_no` | VARCHAR(40) `as_cs` UNIQUE | Own series |
| `invoice_line_id` | FK **NOT NULL** | RESTRICT |
| `enrollment_id` | FK **NOT NULL** | RESTRICT. Denormalised from the line for direct querying |
| `student_id` | FK NOT NULL | RESTRICT |
| `academic_year_id`, `fiscal_year_id` | FK NOT NULL | RESTRICT (dual calendar) |
| `amount` | BIGINT **SIGNED** NOT NULL | Positive reduces outstanding; **negative is a surcharge and increases it** |
| `reason_type` | ENUM(`correction`,`scholarship_internal`,`scholarship_donor_funded`,`sibling_discount`,`staff_child`,`hardship`,`early_payment_discount`,`surcharge_late_payment`,`goodwill`) | |
| `reason_note` | VARCHAR(400) NOT NULL | |
| `adjustment_account_id` | FK → `chart_of_accounts` NOT NULL | Resolved from `reason_type`, §8.1 |
| `counterpart_account_id` | FK NULL | Used by donor-funded scholarships, §8.1 |
| `donor_id` | FK → `suppliers`/`organisations` NULL | NOT NULL iff `reason_type = scholarship_donor_funded` |
| `application_method` | ENUM(`pro_rata`,`earliest_first`,`latest_first`,`specific_instalment`) | Affects which instalment is relieved, §8.2 |
| `target_installment_id` | FK NULL | NOT NULL iff `application_method = specific_instalment` |
| `effective_date` | DATE NOT NULL | Drives the `<= d` filter in §5 |
| `status` | ENUM(`pending`,`approved`,`rejected`,`reversed`) | |
| `granted_by` | FK users NOT NULL RESTRICT | |
| `approved_by` | FK users NULL RESTRICT | |
| `approved_at` | DATETIME NULL | |
| `reversed_by_adjustment_id` | FK NULL UNIQUE | Corrections are new signed rows, never edits (A3) |
| `bulk_batch_id` | UUID NULL | Set by `BulkAdjustFees` |
| `journal_entry_id` | FK NULL RESTRICT | |

**CHECK:** `amount <> 0`
**CHECK:** `(status='approved') = (approved_at IS NOT NULL)`
**Segregation of duties:** `approved_by <> granted_by`, enforced in `ApproveFeeAdjustment` and by an authorization policy, not only by a CHECK — the useful error message lives in the Action.
**Approval threshold:** adjustments above a configurable `fees.adjustment.approval_threshold` require the Principal role; below it, the Bursar's supervisor suffices.

### 8.1 Reason type → accounting (the point of C2)

Different reason types are **materially different transactions**. Netting them all against tuition misstates the accounts.

| `reason_type` | Nature | Posting at approval |
|---|---|---|
| `correction` | The original invoice was wrong | **Reverses the original revenue account of the line.** `Dr <line.revenue_account_id> / Cr 4111 partner=student` |
| `sibling_discount`, `staff_child`, `hardship`, `early_payment_discount`, `goodwill` | **Contra-revenue** — a price reduction the school grants | `Dr 4198 RRR et autres avoirs à accorder / Cr 4111 partner=student` |
| `scholarship_internal` | The school bears the cost as a policy | Contra-revenue, as above, but reported separately for the scholarship register |
| `scholarship_donor_funded` | **Income *and* an expense.** Netting it against tuition understates both turnover and charitable expenditure | Two entries: (1) recognise the receivable from the donor `Dr 4111/4:  partner=donor / Cr <donor income account>`; (2) settle the student `Dr <the same donor-funded expense or the student receivable> / Cr 4111 partner=student`. **The exact SYSCOHADA accounts for donor grant income and the matching scholarship expense are NEEDS VERIFICATION** — candidates in class 77/75 for the income side and class 65/63 for the expense side have not been confirmed against the revised plan for a Cameroonian école privée. Ships empty; the accountant configures both accounts before the reason type can be used, and the reason type is disabled until they are set. |
| `surcharge_late_payment` | Additional income | `Dr 4111 partner=student / Cr <penalty income account>`. **Account NEEDS VERIFICATION** (class 77 *Revenus financiers* vs class 75 *Autres produits* for a late-payment penalty on a school fee under SYSCOHADA). Ships empty. |

**4198** *RRR et autres avoirs à accorder* is used for contra-revenue and for credit notes (§9). Its verified role is exactly this: reductions granted to clients.

### 8.2 Application method

A 50 000 adjustment against a 350 000 invoice payable in three tranches of 120 000 / 120 000 / 110 000:

| Method | Effect |
|---|---|
| `pro_rata` | `Money::allocate(50 000, [120,120,110])` → 17 143 / 17 143 / 15 714 (last absorbs residual) |
| `earliest_first` | 50 000 off tranche 1 |
| `latest_first` | 50 000 off tranche 3 |
| `specific_instalment` | 50 000 off the named tranche |

The adjustment itself is still recorded against **one invoice line**; `application_method` governs only the derived instalment satisfaction (§3.3), not the ledger.

---

## 9. `credit_notes` — **C7**

Over-invoicing requires a *facture d'avoir* under OHADA, not an "adjustment". A credit note is a **document with its own legal identity and its own sequence**, mirroring `Invoice`.

`credit_notes`

| Column | Notes |
|---|---|
| `id` | |
| `credit_note_no` | VARCHAR(40) `as_cs` **UNIQUE**. Own series, `AV/2026/000123` by default |
| `invoice_id` | FK NOT NULL RESTRICT — a credit note always references the invoice it corrects |
| `enrollment_id`, `student_id` | FK RESTRICT |
| `academic_year_id`, `fiscal_year_id` | FK RESTRICT |
| `issue_date` | DATE |
| `reason_type` | ENUM(`over_invoiced`,`service_not_delivered`,`withdrawal`,`duplicate_invoice`,`price_correction`,`goodwill`) |
| `reason_note` | VARCHAR(400) NOT NULL |
| `status` | ENUM(`draft`,`issued`,`cancelled`) |
| `settlement_mode` | ENUM(`apply_to_account`,`refund`) — whether the credit sits as student credit or triggers a `Refund` (§12) |
| `approved_by`, `approved_at` | RESTRICT |
| `journal_entry_id` | FK NULL RESTRICT |
| `printed_pdf_hash` | CHAR(64) NULL — SHA-256 of the issued PDF (00-core §13/§14 discipline) |
| `idempotency_key` | UNIQUE |

`credit_note_lines`: `credit_note_id`, `invoice_line_id` (FK NOT NULL RESTRICT), `description` snapshot, `amount` (`CHECK (amount > 0)`), `tax_amount`, `revenue_account_id` snapshot, `collection_basis` snapshot.
`UNIQUE(credit_note_id, invoice_line_id)`.

**Invariant, under the §11 lock:** per line, `Σ credit_note amounts ≤ invoiced(ℓ) − Σ adjustments(ℓ) − Σ write_offs(ℓ)`. You cannot credit more than remains billed.

Posting (`fee.credit_note.issued`):
```
Dr  4198  RRR et autres avoirs à accorder     amount
    Cr  4111  Clients — partner=student                amount
```
For an **agent** line, the credit reverses the class 47 liability instead of 4198 — the school never held that as revenue, so there is nothing to contra.
Where TVA applied, the tax is credited to the deductible/collected account per `03-tax-procurement`.

Worked example: §15.5.

---

## 10. `write_offs` and doubtful debt — **C8**

### 10.1 `write_offs`

| Column | Notes |
|---|---|
| `id`, `reference_no` UNIQUE | |
| `student_id`, `enrollment_id` | RESTRICT |
| `academic_year_id`, `fiscal_year_id` | RESTRICT |
| `total_amount` | BIGINT SIGNED, `CHECK (> 0)` |
| `reason` | ENUM(`irrecoverable`,`student_untraceable`,`deceased`,`legal_settlement`,`de_minimis`) |
| `reason_note` | VARCHAR(400) NOT NULL |
| `is_tax_deductible` | BOOL NOT NULL — drives the DSF réintégration working paper |
| `legal_evidence_ref` | VARCHAR(200) NULL — **NOT NULL when `is_tax_deductible = true`.** The CGI requires evidence of irrecoverability (bailiff's report, court decision, proof of the debtor's disappearance) for the loss to be deductible. Without it the loss is réintégré |
| `approved_by`, `approved_at` | RESTRICT, and `approved_by <> created_by` |
| `status` | ENUM(`pending`,`approved`,`rejected`,`reversed`) |
| `journal_entry_id` | FK NULL RESTRICT |

`write_off_lines`: `write_off_id`, `invoice_line_id` FK RESTRICT, `amount`. `UNIQUE(write_off_id, invoice_line_id)`.

Posting (`receivable.written_off`) — **NEEDS VERIFICATION** of the exact charge account. SYSCOHADA distinguishes ordinary-activity losses on receivables from HAO losses, and the correct account for a school's uncollectible parent fee (class 65 *Autres charges* vs a class 6:/8: treatment) has not been confirmed. Ships empty; configured by the accountant before the write-off feature is enabled. The credit side is unambiguous: `Cr 4111` (or 4162 if already reclassified, §10.2).

### 10.2 `doubtful_debt_policies` and the provision run

A school's aged parent receivables are always material. Not provisioning overstates assets and the result.

`doubtful_debt_policies` — header: `academic_year_id`, `name`, `is_active`, `approved_by`.
`doubtful_debt_policy_buckets` — `policy_id`, `min_days_overdue`, `max_days_overdue` NULL for the open-ended top bucket, `provision_rate_bp`.
**Coverage invariant:** buckets are contiguous, non-overlapping, start at 0 and the top bucket is open-ended — validated at save, the same shape of invariant `01-assessment` applies to grade bands.

`RunDoubtfulDebtProvision(fiscal_year_id, as_of)`:
1. Age every open receivable **by instalment due date** (§11.3).
2. **Reclassify** the doubtful portion out of 4111: `Dr 4162 Créances douteuses / Cr 4111`, per student partner. `4161 Créances litigieuses` is used instead where legal action has started (`is_in_litigation` on the student ledger).
3. **Provision:** `Dr <doubtful-debt charge account> / Cr 491`.
   > **NEEDS VERIFICATION — compte 491.** The brief flags 491 as unverified for the depreciation-of-receivables provision under the revised SYSCOHADA plan, and the matching class 65/69 charge account is likewise unconfirmed. **Both ship empty.** The provision run refuses to execute until both are configured, with an on-screen message naming what is missing.
4. **Reversal the following year:** the prior year's provision is reversed in full at the start of the next exercice and re-computed, rather than adjusted. This is simpler to audit and matches the reprise/dotation presentation.
5. `UNIQUE(fiscal_year_id)` on the run header; `FOR UPDATE` on the run row (00-core §11 pattern).

---

## 11. `payments` — capture, locking, clearing, void

### 11.1 `payments`

**Payment is conceptually immutable (A3).** v1 declared it immutable and then gave it five mutable columns. Here, the only columns that change after insert are `clearing_state` (a controlled state machine, §11.4) and `unallocated_amount` (a derived cache, §12.3, maintained only inside the invoice lock). The void is a **separate record** (§11.5), and `is_voided` is **derived**, not stored.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `receipt_no` | VARCHAR(40) `as_cs` **UNIQUE globally** | Gaps permitted (00-core §12). Uniqueness scope stated explicitly: **global across the instance**, not per fiscal year, not per cash desk |
| `student_id` | FK NOT NULL RESTRICT | Payment is against the **student account**, not necessarily an invoice (C10) |
| `enrollment_id` | FK NULL RESTRICT | The enrollment in effect at `value_date`, for reporting |
| `academic_year_id`, `fiscal_year_id` | FK NOT NULL RESTRICT | |
| `payment_method_id` | FK NOT NULL RESTRICT | |
| `amount` | BIGINT SIGNED, `CHECK (amount > 0)` | Gross amount received from the payer |
| `fee_amount` | BIGINT SIGNED NOT NULL DEFAULT 0, `CHECK (>= 0)` | Operator/bank commission |
| `fee_bearer` | ENUM(`school`,`payer`,`none`) | Snapshot from the method |
| `net_to_treasury` | BIGINT SIGNED | Generated column: `amount - (fee_bearer='school' ? fee_amount : 0)` |
| `tendered_amount` | BIGINT SIGNED NULL | Cash only; see §16 |
| `change_given` | BIGINT SIGNED NULL | Cash only |
| `rounding_difference` | BIGINT SIGNED NOT NULL DEFAULT 0 | §16 |
| `reference` | VARCHAR(120) NULL | Required when the method says so |
| `payer_name` | VARCHAR(200) NOT NULL | **(H)** The person paying is frequently not the registered guardian — an uncle, an employer, a sponsor |
| `payer_guardian_id` | FK NULL RESTRICT | Optional link when the payer *is* a registered guardian |
| `payer_phone` | VARCHAR(30) NULL | For the MoMo reconciliation and the SMS receipt |
| `value_date` | DATE NOT NULL | The date money changed hands |
| `posting_date` | DATE NOT NULL | May differ if `value_date` falls in a closed period (AUDCIF Art. 22, 02-accounting C4) |
| `cash_desk_session_id` | FK NULL RESTRICT | §11.7 |
| `clearing_state` | ENUM(`cleared`,`pending`,`bounced`) NOT NULL | `immediate` methods insert as `cleared` |
| `cleared_on`, `bounced_on`, `bounce_reason` | | |
| `in_kind_description`, `in_kind_valuation_basis`, `in_kind_valuation_ref` | | Required for `in_kind` |
| `unallocated_amount` | BIGINT SIGNED NOT NULL, `CHECK (>= 0)` | Derived cache (§12.3) |
| `notes` | TEXT NULL | |
| `is_migration` | BOOL | |
| `journal_entry_id` | FK NULL RESTRICT | |
| `idempotency_key` | VARCHAR(80) UNIQUE | Protects the double-clicked Collect button |
| `received_by` | FK users NOT NULL RESTRICT | |
| `created_at` | | |

**ON DELETE:** never. All FKs RESTRICT.

### 11.2 Locking — **C3**

Two cashiers both read outstanding = 350 000 and both allocate it. Balance is computed-never-stored, which is correct for consistency and fatal for check-then-act.

`RecordPayment` and `AllocatePayment` both execute:

```php
DB::transaction(function () use ($invoiceIds, ...) {
    // 1. Deterministic lock order: ascending invoice id. Prevents deadlock
    //    when two cashiers allocate across an overlapping invoice set.
    $invoices = Invoice::whereIn('id', collect($invoiceIds)->sort()->values())
                       ->lockForUpdate()->get();

    // 2. Recompute outstanding INSIDE the lock, from §5. Never trust the
    //    figure the browser posted back.
    // 3. Assert §5.3 line invariant.
    // 4. Insert allocations.
    // 5. Recompute and persist unallocated_amount.
});
```

Additionally, when a payment carries **no** invoice target (pure student credit, C10), the lock is taken on the `student_accounts` row (§12.3) with `FOR UPDATE`, so two cashiers cannot both consume the same credit.

The UI posts the invoice/line ids and the **amounts the cashier typed**, plus an `expected_outstanding` per line. If the recomputed outstanding differs, the Action does not silently truncate: it aborts with a `StaleBalanceException` carrying the new figures, and the screen re-renders with a visible "the balance changed while you were on this screen" banner. Silent truncation is how a cashier hands out a receipt for money the ledger did not take.

### 11.3 Aging (H)

Aging is **by instalment due date**, with an explicit `as_of` parameter and a stated axis.

```
age_days(instalment, as_of) = as_of − instalment.due_date
```
Buckets: `current` (not yet due), `1–30`, `31–60`, `61–90`, `91–180`, `180+`. The bucket boundaries are configurable; the **axis is fixed as instalment due date** and is printed in the report header so the reader knows what they are looking at. An invoice with three tranches appears in up to three buckets simultaneously. Aging by invoice date — v1's implicit behaviour — puts a September invoice with a March tranche into the 180+ bucket in March, which is simply wrong.

`as_of` defaults to `business_date()` (00-core §7.5), never `now()->toDateString()` in UTC.

### 11.4 Cheque clearing (H)

State machine, with a posting event at each transition — v1's event list had neither.

| Transition | Event | Posting |
|---|---|---|
| insert as `pending` | `fee.payment.recorded` (deferred variant) | `Dr <method.pending_account_id> effets à l'encaissement / Cr 4111 partner=student` — **not** treasury |
| `pending → cleared` | `cheque.cleared` | `Dr 521x bank / Cr <pending_account_id>` |
| `pending → bounced` | `cheque.bounced` | `Dr 4111 partner=student / Cr <pending_account_id>`, plus a separate entry for any bank charge (`bank.charge.recorded`) |

A `bounced` payment is excluded from `allocated()` by §5, so the receivable reappears automatically. The allocations are **not deleted** — they are marked `reversed_at` so the history of what the payment was once believed to cover survives. `bounced → cleared` is forbidden; a re-presented cheque is a **new payment**.

Transitions use conditional UPDATE with an affected-rows check (`WHERE clearing_state = 'pending'`), never read-then-write (00-core §10.4).

### 11.5 `payment_voids` (H)

| Column | Notes |
|---|---|
| `id`, `payment_id` | FK NOT NULL RESTRICT. **UNIQUE where status = 'confirmed'** (generated-column pattern) — a payment can be voided once |
| `reason_type` | ENUM(`keying_error`,`duplicate_capture`,`wrong_student`,`funds_not_received`,`cashier_error`,`fraud_investigation`) |
| `reason_note` | VARCHAR(400) NOT NULL |
| `voided_by` | FK users NOT NULL RESTRICT |
| `approved_by` | FK users NULL RESTRICT |
| `voided_at` | DATETIME |
| `reversal_journal_entry_id` | FK NULL RESTRICT |
| `status` | ENUM(`pending_approval`,`confirmed`,`rejected`) |

**Segregation of duties, enforced in `VoidPayment`:**
1. **`voided_by <> payment.received_by`.** The person who recorded a payment cannot void it. Hard rule, no override.
2. If the payment's `cash_desk_session_id` is **closed**, the void additionally requires the `fees.payment.void_after_close` permission (Bursar's supervisor / Principal) and an `approved_by`. Voiding after the till is counted and banked changes a reconciled figure.
3. If the payment's accounting period is closed, the reversal is dated **in the earliest open period** (02-accounting C4/C9), never the original date, and the original `value_date` is retained on the reversal for provenance.

**Void cascade, in order, inside one transaction:**
1. `FOR UPDATE` on every invoice the payment allocated to, ascending id.
2. Set `reversed_at`, `reversed_by`, `reversal_reason` on every `payment_allocation` of the payment. Rows are never deleted.
3. Recompute and persist `unallocated_amount = 0` (the payment no longer contributes anything).
4. Recompute student credit (§12.3).
5. Instalment satisfaction re-derives automatically; affected instalments re-open and may immediately be `overdue` (§3.1).
6. Emit `fee.payment.voided` after commit → reversing journal entry with `reverses_entry_id` set and a mandatory `reversal_reason` (02-accounting C9).
7. Mark every `DocumentPrintLog` receipt row for this payment as `voided`, so a reprint of the original receipt is blocked and any existing printed copy is discoverable.

`is_voided` is a derived accessor: `EXISTS(payment_voids WHERE payment_id = ? AND status = 'confirmed')`. There is no `payments.is_voided` column to drift.

### 11.6 `payment_allocations`

| Column | Notes |
|---|---|
| `id`, `payment_id` | FK NOT NULL RESTRICT |
| `invoice_id` | FK NULL RESTRICT |
| `invoice_line_id` | FK NULL RESTRICT |
| `student_account_id` | FK NULL RESTRICT — **C10**: an allocation may target the student account rather than a line |
| `amount` | BIGINT SIGNED, `CHECK (amount > 0)` |
| `allocated_at`, `allocated_by` | RESTRICT |
| `reversed_at`, `reversed_by`, `reversal_reason` | NULL until reversed |
| `lettering_code` | VARCHAR(20) NULL — set by auto-lettering (02-accounting C10) |

**CHECK:** `(invoice_line_id IS NOT NULL) <> (student_account_id IS NOT NULL)` — exactly one target.
**In-Action invariant:** `Σ allocations(payment, not reversed) + payment.unallocated_amount = payment.amount`, asserted at the end of every transaction that touches allocations.
`UNIQUE(payment_id, invoice_line_id)` where not reversed (generated-column pattern) — one live allocation row per (payment, line); a top-up increases the existing row's amount rather than inserting a second.

Auto-lettering: on allocation, the 4111 debit lines (from the invoice) and credit lines (from the payment) for the same student partner are grouped under a `Lettering` code; the group is marked `full` only when `Σdebit = Σcredit` (02-accounting C10). Unlettered items are the source of the aged-receivables listing.

### 11.7 `cash_desk_sessions`

| Column | Notes |
|---|---|
| `id`, `opened_by`, `opened_at`, `opening_float` | RESTRICT |
| `business_date` | DATE — from `business_date()`, not UTC |
| `closed_by`, `closed_at` | |
| `counted_cash`, `expected_cash`, `variance` | `variance = counted − expected`, SIGNED |
| `variance_reason` | VARCHAR(400) — **mandatory when variance ≠ 0** |
| `status` | ENUM(`open`,`closed`,`reconciled`) |
| `journal_entry_id` | FK NULL |

One open session per (user, business_date) — generated-column UNIQUE. Closing with a variance emits `cashdesk.closed_with_variance`; the posting accounts (shortage/overage) are owned by `02-accounting`, which flags them **NEEDS VERIFICATION**.

---

## 12. Payment before invoice, student credit, and overpayment — **C10, C9**

### 12.1 The problem

Parents pay at registration, before the invoice run. v1 counted only *allocated* payments, so a parent who had paid appeared in the defaulters report and received a dunning SMS. This is the single most common way a school-fees module loses the customer's trust.

### 12.2 `student_accounts`

One row per (student, academic_year). The lockable anchor for credit.

| Column | Notes |
|---|---|
| `id`, `student_id`, `academic_year_id` | `UNIQUE(student_id, academic_year_id)` |
| `unallocated_credit` | BIGINT SIGNED NOT NULL DEFAULT 0, `CHECK (>= 0)` — derived cache |
| `is_in_litigation` | BOOL — routes provisioning to 4161 |
| `financial_clearance` | BOOL — §13 |
| `dunning_suppressed_until` | DATE NULL |

### 12.3 Derived caches are caches, not truth

`payments.unallocated_amount` and `student_accounts.unallocated_credit` are **maintained only inside the §11.2 lock** and are **reconciled nightly** against the formula:

```
unallocated_credit(student, year) =
      Σ payments.amount   WHERE not voided AND clearing_state <> 'bounced'
    − Σ allocations.amount WHERE not reversed
    − Σ refunds.amount     WHERE status = 'paid'
```

A divergence is an integrity alert, not a silent correction. They exist because the defaulters query and the collection screen run on every keystroke and cannot afford the full derivation; A1 is not violated because the **authoritative** figure is always the formula, and any report used for money uses the formula.

### 12.4 Auto-allocation

`AllocateStudentCredit` runs (a) when an invoice is issued, (b) manually from the collection screen, (c) never as an unattended nightly job — allocating a parent's deposit to a bill they dispute, overnight, without a human, is a support call.

Order: oldest **unpaid instalment due date** first, then invoice id, then line no. The order is stated so two developers produce the same allocation.

### 12.5 Defaulters definition

```
is_defaulter(student, as_of) :=
      total_outstanding(student, as_of) > threshold
  AND unallocated_credit(student, as_of) < total_outstanding(student, as_of)
  AND EXISTS an instalment with due_date < as_of and outstanding > 0
  AND NOT student_account.dunning_suppressed_until >= as_of
```
The second clause is C10's fix. A student whose credit covers the debt is not a defaulter even if nobody has run the allocation.

### 12.6 Overpayment reclassification — **C9**

OHADA **non-compensation**: you may not net a credit balance for one student against a debit balance for another, nor present a net receivable that hides both.

`RunReceivableReclassification(as_of)`, nightly and at every period close:
1. For each student, compute `net = Σ outstanding − unallocated_credit`.
2. Students with `net < 0` are in credit. The credit portion is presented in **4191 Clients, avances et acomptes reçus** — a **liability**.
3. Students with `net > 0` remain in **4111**.
4. **No netting across students.** The reclassification entry is built from per-student partner lines (02-accounting C2's auxiliary layer), so the entry has as many line pairs as there are students in credit.
5. The entry is **reversed on the first day of the next period** and re-run, so it is a presentation reclassification and never disturbs the operational sub-ledger.

Worked example: §15.9.

---

## 13. Refunds — **C6**

The word does not appear in v1. Schools refund overpayments, withdrawn-student deposits and cancelled transport constantly. Without a refund process, cashiers will void unrelated payments or hand out cash off-ledger — and both are worse than any bug.

`refunds`

| Column | Notes |
|---|---|
| `id` | |
| `refund_receipt_no` | VARCHAR(40) `as_cs` **UNIQUE**. Own series, distinct from `receipt_no` |
| `student_id`, `enrollment_id` | RESTRICT |
| `academic_year_id`, `fiscal_year_id` | RESTRICT |
| `source_type` | ENUM(`unallocated_credit`,`credit_note`,`deposit_return`,`overpayment`) |
| `credit_note_id` | FK NULL RESTRICT — NOT NULL when `source_type = credit_note` |
| `payment_id` | FK NULL RESTRICT — the originating payment, where identifiable |
| `amount` | BIGINT SIGNED, `CHECK (> 0)` |
| `reason_type` | ENUM(`withdrawal`,`overpayment`,`service_cancelled`,`duplicate_payment`,`deposit_return`,`other`) |
| `reason_note` | VARCHAR(400) NOT NULL |
| `payment_method_id` | FK NOT NULL RESTRICT — how the money goes out |
| `treasury_account_id` | FK NOT NULL RESTRICT — snapshot from the method |
| `payee_name` | VARCHAR(200) NOT NULL |
| `payee_guardian_id` | FK NULL RESTRICT |
| `payee_id_document_ref` | VARCHAR(120) NULL — cash refunds require an ID reference |
| `requested_by`, `approved_by`, `approved_at`, `paid_by`, `paid_on` | RESTRICT |
| `status` | ENUM(`requested`,`approved`,`rejected`,`paid`,`cancelled`) |
| `journal_entry_id` | FK NULL RESTRICT |
| `idempotency_key` | UNIQUE |

**Segregation of duties:** `approved_by <> requested_by`; `paid_by` may equal neither only if the school has fewer than three finance users, in which case the Action requires the `fees.refund.pay_self_approved` permission and logs an elevated-permission audit entry.

**Guard:** `amount ≤ available_credit(student)` at approval **and** re-checked under the student-account lock at payment. A refund cannot create a receivable.

Posting (`fee.refund.issued`), at `paid`:
```
Dr  4191  Clients, avances et acomptes reçus      amount    partner=student
    Cr  <treasury_account_id>                              amount
```
Where the refund is settling a credit note whose credit still sits in 4111, the debit is 4111 instead. `ResolveRefundDebitAccount` picks based on whether a reclassification (§12.6) has moved the credit.

**`WithdrawalSettlement` Action (H).** Composite, one transaction:
1. Cancel all future unbilled instalments (`invoice_installments.is_cancelled = true`).
2. Compute the **earned / unearned split** using the same service-period day arithmetic as §6.2. Earned stays billed; unearned is credited.
3. Issue a `CreditNote` for the unearned portion, `reason_type = withdrawal`.
4. Net the credit against any remaining outstanding.
5. Residual credit → `Refund`; residual debit → dunning, or a `WriteOff` if approved.
6. Set `student_accounts.financial_clearance` accordingly.
7. The **Transfer Certificate** (`10-documents`) gates on `financial_clearance`; a school will not release a leaving certificate to a debtor, and the product must not force them to.

Worked example: §15.4.

---

## 14. Series formats and sequences

Per-document-type series, configurable in Settings, following 00-core §12's generalised form:

| Document | Default format | Uniqueness scope | Gapless? |
|---|---|---|---|
| Invoice | `INV/{YYYY}/{######}` | global | No |
| Receipt | `RCPT/{YYYY}/{######}` | **global across the instance** | No |
| Credit note | `AV/{YYYY}/{######}` | global | No |
| Refund receipt | `RFD/{YYYY}/{######}` | global | No |
| Adjustment | `ADJ/{YYYY}/{######}` | global | No |
| Write-off | `WO/{YYYY}/{######}` | global | No |
| `JournalEntry.piece_no` | owned by `02-accounting` | per (journal, fiscal year) | **Yes** |

Tokens: `{YYYY}`, `{YY}`, `{MM}`, `{SECTION}`, `{DESK}`, `{######}` (zero-padded counter width). All allocated from a row-locked `sequence` table, never `max()+1`. Changing a format is permitted; the counter does not reset unless an explicit reset with a reason is performed (audited).

---

## 15. Worked examples

All amounts are whole FCFA. All arithmetic is `Money`.

### 15.1 Baseline invoice

Student: **Doe, John**, Form 1A (Grade 7 in the mockup), academic year 2025/2026, fiscal year 2026.

| Line | Item | `collection_basis` | Amount |
|---|---|---|---|
| 1 | Tuition Fee | own_revenue | 200 000 |
| 2 | Development Fee | own_revenue | 50 000 |
| 3 | Examination Fee (GCE registration) | **agent_for_third_party** | 40 000 |
| 4 | Library Fee | own_revenue | 20 000 |
| 5 | ICT Fee | own_revenue | 40 000 |
| | **Total** | | **350 000** |

Tuition is exempt from TVA (accredited establishment, `03-tax-procurement`); no tax lines here.

Posting at issue (`fee.invoice.issued`), tuition on `straight_line_over_period` 2025-09-08 → 2026-07-10, the rest `on_issue`:

```
Dr 4111  Clients — partner=Doe,John        350 000
   Cr 70611  Frais de scolarité                     200 000
   Cr 707x   Produits accessoires — développement     50 000
   Cr 707x   Produits accessoires — bibliothèque      20 000
   Cr 707x   Produits accessoires — TIC               40 000
   Cr 47xx   Fonds GCE détenus pour compte de tiers   40 000
```

> Note **70611**: compte 7061 is *Services vendus dans la Région* — the 4th digit encodes **geography**, not service type — so tuition extends at 5 digits. Boarding, transport and miscellaneous belong in **707 Produits accessoires** (02-accounting). The specific 707x sub-accounts are the school's extension choice, seeded empty.

### 15.2 Instalment allocation with residual

Plan: three tranches at 40% / 30% / 30% of 350 000.
`Money::allocate(350 000, [4000, 3000, 3000])` basis points:

| Tranche | Exact | Floor | Remainder | Award |
|---|---|---|---|---|
| 1 | 140 000.000 | 140 000 | .000 | 140 000 |
| 2 | 105 000.000 | 105 000 | .000 | 105 000 |
| 3 | 105 000.000 | 105 000 | .000 | 105 000 |
| Σ | | 350 000 | | **350 000** ✓ |

Now a harder case — 1/3 each of 350 000, i.e. `allocate(350 000, [1,1,1])`:

| Tranche | Exact | Floor | Award |
|---|---|---|---|
| 1 | 116 666.67 | 116 666 | 116 666 |
| 2 | 116 666.67 | 116 666 | 116 667 |
| 3 | 116 666.67 | 116 666 | **116 667** ← last absorbs the residual |
| Σ | | 349 998 | **350 000** ✓ |

Largest-remainder awards the two spare francs to the highest remainders; the **last instalment absorbs** any that survive (00-core §7.3). `Σ parts === total` is asserted inside the value object, so a plan that does not sum cannot be persisted.

### 15.3 Allocation of a part payment (mockup case)

The cashier collects **120 000** cash against the 350 000 invoice; 240 000 has already been paid (mockup panel 3 shows Total 350 000, Paid 240 000, Balance 120 000 — so this payment clears the invoice).

Allocation order is oldest-instalment-first, then line order. Outstanding per line before this payment (240 000 already allocated across lines 1–3 and part of 4):

| Line | Invoiced | Already allocated | Outstanding |
|---|---|---|---|
| 1 Tuition | 200 000 | 200 000 | 0 |
| 2 Development | 50 000 | 40 000 | 10 000 |
| 3 Exam (agent) | 40 000 | 0 | 40 000 |
| 4 Library | 20 000 | 0 | 20 000 |
| 5 ICT | 40 000 | 0 | 40 000 |
| | | **240 000** | **110 000** |

Wait — outstanding is 110 000, but the screen said 120 000. **This is exactly the C3 race**: another cashier allocated 10 000 while this screen was open. `RecordPayment` recomputes inside the `FOR UPDATE` lock, finds 110 000 ≠ the posted `expected_outstanding` of 120 000, and raises `StaleBalanceException`. The screen re-renders with the corrected figures and the cashier confirms 110 000, or takes 120 000 and the surplus 10 000 becomes `unallocated_credit` (§12).

Assuming the cashier proceeds with 120 000:

| Line | Allocated now | Outstanding after |
|---|---|---|
| 2 | 10 000 | 0 |
| 3 | 40 000 | 0 |
| 4 | 20 000 | 0 |
| 5 | 40 000 | 0 |
| **Σ allocated** | **110 000** | |
| **Unallocated credit** | **10 000** | held on the student account |

Ledger:
```
Dr 571  Caisse                                    120 000
   Cr 4111 Clients — partner=Doe,John                     110 000
   Cr 4191 Clients, avances et acomptes reçus — Doe,John   10 000
```
The 10 000 goes to **4191, not a negative 4111** (C9, A5).

### 15.4 Void

Continue from §15.3. The receipt was issued to the wrong student. `VoidPayment`:

- `voided_by` must not be the cashier who took it (§11.5 rule 1). The Bursar's supervisor performs it.
- All four allocation rows get `reversed_at`; **not deleted**.
- `unallocated_amount` → 0; `student_accounts.unallocated_credit` → 0.
- Outstanding recomputes to **120 000** ← this is the C1 test. Under v1's formula it would have stayed 0.
- Reversal entry, dated in the earliest open period (say the original was 2026-06-30 in a now-closed June; the reversal is dated 2026-07-01):
```
Dr 4111 Clients — partner=Doe,John                 110 000
Dr 4191 Clients, avances et acomptes reçus          10 000
   Cr 571  Caisse                                         120 000
```
carrying `reverses_entry_id`, `reversal_reason = 'wrong_student'`, `is_reversal = true`.
- The `DocumentPrintLog` row for RCPT-000125 is marked `voided`; reprint is blocked.
- **Both the original and the reversal remain in the statements and net to zero.** Neither is `draft`. A developer filtering `status <> 'reversed'` would remove the original and leave the reversal, flipping the sign of the whole transaction (02-accounting C9).

### 15.5 Credit note

The school over-invoiced the ICT Fee: it should have been 25 000, not 40 000.

This is **not** an adjustment — it is over-invoicing, and OHADA requires a *facture d'avoir*.

`CreditNote AV/2026/000041`, `reason_type = price_correction`, one line against invoice line 5, amount 15 000.

```
Dr 4198  RRR et autres avoirs à accorder           15 000
   Cr 4111  Clients — partner=Doe,John                     15 000
```

Line 5 recomputes: `invoiced 40 000 − credit_notes 15 000 − allocated 40 000 = −15 000`. Negative outstanding on a line is permitted and surfaces as student credit at the account level (`settlement_mode = apply_to_account`), or triggers a `Refund` (`settlement_mode = refund`). The §5.3 invariant is `Σ allocations + Σ adjustments + Σ credit_notes + Σ write_offs ≤ invoiced` — here 40 000 + 15 000 = 55 000 > 40 000, which **would fail**. Resolution: `IssueCreditNote` with `settlement_mode = apply_to_account` first **reverses 15 000 of the allocation** on line 5 back to unallocated credit, then applies the credit note. The invariant then holds: 25 000 + 15 000 = 40 000. This ordering is part of the Action and is covered by a named test.

Contrast with a `FeeAdjustment` of the same 15 000 and `reason_type = hardship`: no legal document, no `credit_note_no`, posts to 4198 as contra-revenue, reduces what is owed rather than restating what was billed. **The distinction is: a credit note says "we billed the wrong amount"; an adjustment says "we billed correctly and are granting a reduction."** They post to the same 4198 for contra-revenue reasons, but only one of them prints a numbered legal document, and only one of them changes the reported gross turnover.

### 15.6 Mobile money with commission

Parent pays 350 000 by MTN MoMo. Operator commission 1% = 3 500, borne by the **school** (`fee_bearer = school`).

v1's worked example posted 350 000 to treasury and ignored the commission, so the MoMo statement (which shows 346 500 settled) could never be reconciled.

```
Dr 552   Téléphone Portable (monnaie électronique)   346 500
Dr 6317  Frais sur effets / commissions                3 500
   Cr 4111  Clients — partner=Doe,John                        350 000
```

`net_to_treasury` = 346 500 and reconciles to the operator statement line. Where `fee_bearer = payer`, the parent tenders 353 500, the school receives 350 000 net, `payments.amount = 350 000`, `fee_amount = 3 500`, and there is no 6317 line — the commission never entered the school's books.

> **NEEDS VERIFICATION — 6317.** The brief states mobile-money fees go to **6317**; the account name and its exact scope under the revised SYSCOHADA plan are taken from that brief and not independently confirmed here. Compte **552** *Téléphone Portable* under **55** *Instruments de monnaie électronique* is verified.

### 15.7 Revenue deferral at 31 December

Tuition line: 200 000, service period **2025-09-08 → 2026-07-10**.

Total days = 24 (Sep) + 31 + 30 + 31 + 31 + 28 + 31 + 30 + 31 + 30 + 10 (Jul) = **307 days**.
Days falling in fiscal year 2025 (to 31 Dec 2025) = 24 + 31 + 30 + 31 = **116**.
Days falling in fiscal year 2026 = 307 − 116 = **191**.

`Money::allocate(200 000, [116, 191])`:

| Exercice | Exact | Award |
|---|---|---|
| 2025 | 75 570.03 | 75 570 |
| 2026 | 124 429.97 | **124 430** ← absorbs residual |
| Σ | | **200 000** ✓ |

At 31 December 2025:
```
Dr 70611  Frais de scolarité                      124 430
   Cr 477  Produits constatés d'avance                    124 430
```
Reversed 1 January 2026 by an auto-generated entry with `reverses_entry_id` set:
```
Dr 477   Produits constatés d'avance              124 430
   Cr 70611  Frais de scolarité                          124 430
```

Without this, exercice 2025 shows 200 000 of tuition for a service that is 38% delivered — overstating the result, the IS base and the minimum-tax base, in a way an OHADA auditor will find in the first hour.

### 15.8 Agent funds — full cycle

GCE registration, 40 000 per student, 120 candidates = 4 800 000 collected.

At invoice issue (per student): `Dr 4111 40 000 / Cr 47xx 40 000`. **No class 7.**
At collection: `Dr 571 40 000 / Cr 4111 40 000`.
At remittance to the GCE Board: `Dr 47xx 4 800 000 / Cr 521x 4 800 000`.

**Turnover impact: zero.** Under v1's treatment, 4 800 000 would have entered class 7, and — assuming the acompte d'IS is a percentage of turnover — the school would pay tax on money belonging to the examination board, every year, permanently. The third-party-funds statement shows opening 0, collected 4 800 000, remitted 4 800 000, closing 0, tying exactly to the 47xx GL balance.

### 15.9 Overpayment reclassification — non-compensation

Three students at 31 December:

| Student | Outstanding | Unallocated credit | Net |
|---|---|---|---|
| A | 150 000 | 0 | +150 000 |
| B | 0 | 80 000 | −80 000 |
| C | 40 000 | 0 | +40 000 |

**Wrong (v1, netting):** present 4111 = 110 000. This compensates B's liability against A and C's receivables — forbidden by OHADA non-compensation, and it hides an 80 000 obligation to a parent.

**Correct:** the reclassification entry moves B's credit only:
```
Dr 4111  Clients — partner=B                        80 000
   Cr 4191  Clients, avances et acomptes reçus — B          80 000
```
Balance sheet presentation: **4111 = 190 000 (asset)**, **4191 = 80 000 (liability)**. Reversed on 1 January and re-run.

Note that the entry has one line pair *per student in credit*; there is no aggregated "net credit balances" line, because the auxiliary layer must reconcile student-by-student (02-accounting C2).

---

## 16. Cash rounding (H)

Cameroonian cash transactions round to **5 FCFA** in practice — coins below 5 are effectively unobtainable.

**Decision: the system rounds the *tendered amount*, never the invoice.** The invoice, the receivable and the revenue stay at their exact FCFA values. `payment_methods.rounds_tendered_to = 5` for cash, NULL for everything else.

- The cashier enters the amount to collect (`amount`). If `amount` is not a multiple of 5, the screen shows a rounded `tendered_amount` and a `rounding_difference = tendered_amount − amount`.
- `payments.amount` — what is allocated to the receivable — is the **exact** figure. `rounding_difference` posts separately.
- Posting: the difference goes to a cash-rounding account. **NEEDS VERIFICATION — the appropriate SYSCOHADA account for cash-rounding differences** (candidates in 658 *Charges diverses* / 758 *Produits divers*, which 02-accounting also flags as unverified for cash-desk variance). Ships empty; the module refuses to accept a non-multiple-of-5 cash tender until the account is configured, and until then simply requires exact amounts.
- Rounding differences are reported in aggregate on the cash-desk close, so a systematic drift is visible.

The alternative — rounding the invoice — was rejected: it makes the fee schedule the school publishes differ from what the ledger says, and it makes the same fee item bill differently depending on the payment method.

---

## 17. Screen: Fee Collection (cashier)

**The highest-traffic screen in the product.** Mockup: `flow wizards.png` panel 3. Also reachable from `finance dashboard.png` → the green **Collect Payment** button and the Quick Actions rail ("Collect Student Payment").

Route `/finance/collect` · Livewire component `Fees\Livewire\FeeCollection` · permission `fees.payment.record` · roles Bursar, Administrator, Super Admin.

### 17.1 Layout

Two columns inside the standard shell (`09-ui`). Left ≈ 45%, right ≈ 55% on desktop; stacked on tablet and below.

**Left column**

1. **Select Student** — a single search input with a magnifier affordance (mockup shows `Doe, John (HA2026001)`). Searches matricule, full name, guardian name and guardian phone. Debounced 250 ms, minimum 2 characters, returns at most 20 rows, each showing name · matricule · class group · outstanding. Keyboard: ↑/↓ to move, Enter to select, Esc to clear. Barcode/ID-card scan into the same field is supported (the scanner types the matricule and presses Enter).
2. **Student card** — photo, `Doe, John`, `Grade 7 - A (HA2026001)`, and **`Balance: 120,000 FCFA`** rendered in the alert colour when > 0, in the success colour when 0, and with an explicit `Credit: X FCFA` line when the student holds unallocated credit. The credit line is mandatory: it is the visible half of C10's fix, and without it the cashier will take money the school does not need.
3. **Fee Breakdown** — table `Description | Amount (FCFA)`, one row per outstanding invoice line, grouped by invoice with the invoice number and instalment due date as a group header. Overdue instalments are badged. Agent-collected lines carry a small "held for third party" marker — the cashier is frequently asked what the exam fee is for. A **Total** row closes the table.

**Right column — Payment Details panel**

| Field | Behaviour |
|---|---|
| Total Fees | Read-only. Σ invoiced for the selected scope |
| Paid Amount | Read-only. Σ non-voided, non-bounced allocations |
| Balance Due | Read-only, emphasised. §5 formula |
| **Payment Amount** | Editable. Defaults to Balance Due. Accepts less (part payment) and more (creates credit). Locale-formatted thousands separators as the user types (Alpine-local, no server round-trip) |
| **Payment Method** | Select, populated from `payment_methods WHERE is_active`, ordered by `display_order`. Cash first |
| **Reference** | Shown and **required** only when `payment_methods.requires_reference`. Validated against `reference_pattern` client-side and re-validated server-side |
| Payer name | Defaults to the primary guardian's name, **editable** — the payer is frequently not the guardian (H) |
| Payer phone | Defaults from the guardian; used for the SMS receipt and MoMo reconciliation |
| Value date | Defaults to `business_date()`. Back-dating requires `fees.payment.backdate` |
| Tendered / Change | Cash only, shown when `rounds_tendered_to` is set (§16) |
| Allocation | Collapsed by default: "Allocate automatically (oldest first)". Expanding shows a per-line editable allocation grid whose sum is constrained to the payment amount |

**Actions:** a primary **Collect Payment** button and a secondary print icon (mockup shows both). Collect is disabled while the form is invalid and **disabled immediately on click** with a spinner.

### 17.2 Behaviour contract

- **Alpine-local state.** Amount formatting, allocation-grid arithmetic, method-driven field visibility and validation all run client-side. **The screen makes at most one server request per collection**, plus the debounced student search. The 01-assessment marks grid holds the same contract for the same reason: this screen is used a hundred times an hour on a LAN.
- **Idempotency (00-core §6.2 rule 7).** The Livewire component generates one `idempotency_key` per form instance, sent with the collect request. A double-click, a flaky Wi-Fi retry or an impatient F5 returns the **same receipt**, never a second one. The key is regenerated only when the form resets after a successful collection.
- **Stale balance.** On `StaleBalanceException` (§11.2) the panel re-renders with the new figures and an amber banner naming who changed it and when. The cashier's typed amount is preserved.
- **Receipt after commit, never before.** 00-core §3: "a receipt is rendered only after the DB commit returns." On success the screen opens the receipt preview (§18) with the new `receipt_no`, and — if the school has configured a default receipt printer — sends it directly.
- **Offline degradation.** SMS receipt notification queues to the outbox; the screen never blocks on it.
- **Cash desk.** If the user has no open `cash_desk_session` for `business_date()`, the first collection of the day prompts to open one with an opening float. Collect is blocked until a session exists for cash-method payments.
- **Accessibility / speed.** Full keyboard path: `/` focuses search → Enter selects → Tab to amount → Tab to method → Enter collects. No mouse required.

### 17.3 Server contract

One call: `RecordPayment::forStudent($studentId, RecordPaymentInput $in)` → `{ payment_id, receipt_no, allocations[], new_outstanding, unallocated_credit }`. The Action performs §11.2's locking, §5.3's invariant check, sequence allocation, insert, and dispatches `FeePaymentRecorded` after commit. No business logic lives in the Livewire component.

---

## 18. Screen: Receipt Printing

Mockup: `flow wizards.png` panel 4. Route `/finance/receipts/print` · component `Fees\Livewire\ReceiptPrinting` · permission `fees.receipt.print`.

### 18.1 Layout

Left rail of controls, right pane a live preview of the rendered document at paper aspect ratio.

| Control | Behaviour |
|---|---|
| **Select Receipt** | Searchable select, default the most recent. Shows `RCPT-000125` in the mockup. Also reachable pre-selected from the collection screen and the Recent Transactions table on the dashboard |
| **Template** | `Official Receipt` \| `Duplicate`. **Every print after the first is forced to `Duplicate`** — see §18.3 |
| **Paper Size** | `A4` \| `A5` \| `80mm thermal`. Mockup shows A5. Default configurable per school; thermal is the realistic default for a busy cash desk |
| **Number of Copies** | Integer 1–5. Each copy is logged individually |
| **Show School Stamp** | Checkbox, default from settings. Renders the uploaded stamp image |
| **Language** | EN / FR, defaulting to the guardian's `preferred_language` |
| **Print / Download / Close** | Mockup's three buttons. Print goes to the browser print dialog against the rendered PDF; Download saves it |

### 18.2 Document content

Rendered from `10-documents`' receipt template. Mandatory content, and **all of it is snapshotted at payment time**, never re-derived from mutable records:

- School letterhead: name, crest, address, phone, email; the bilingual **state header** where enabled (00-core §13.1 — permitted).
- **School fiscal identity: NIU, RCCM, régime fiscal, centre des impôts** (`03-tax-procurement`). *"Every invoice and receipt the system prints is legally deficient without the NIU and régime."* The renderer **refuses to produce a receipt** if `SchoolProfile.niu` is unset, with a message pointing at the setup wizard.
- `OFFICIAL RECEIPT` / `REÇU OFFICIEL` title, and `Receipt No: RCPT-000125`, `Date: 07/07/2026`.
- `Received From:` **payer name** (not necessarily the guardian), `Admission No: HA2026001`, student name, class group.
- **The sum in words** — mockup: *"The Sum of One Hundred Twenty Thousand FCFA Only."* Generated by a localised number-to-words formatter, EN and FR, with a golden-file test per language at boundary values (0, 1, 21, 71, 80, 100, 1 000, 1 000 000). French orthography for 71/80/81 and the pluralisation of *cent*/*vingt* are the classic failures.
- `In Payment Of:` — the fee breakdown actually allocated, line by line, **plus any amount carried to credit** shown explicitly as "Advance / Avance".
- `Amount`, `Payment Method`, `Reference`.
- Outstanding balance after this payment — the single most-asked question at the counter.
- **Dual signatures:** `Accountant Signature` and `Authorized Signature` (both visible in the mockup), rendered as signature-line placeholders or as uploaded signature images per settings.
- School stamp when enabled. **Never a ministry seal** (00-core §13.2).
- QR code carrying a **self-contained signed verification token** (00-core §13.5), verifiable offline on LAN deployments.

### 18.3 Duplicate control

`DocumentPrintLog` (00-core §14) records every render: who, when, which payment, which template, how many copies, and whether it is a duplicate.

- The **first** successful print of a receipt may use the `Official` template.
- Every subsequent print is forced to `Duplicate` and carries a diagonal **`DUPLICATA`** watermark. The template selector disables `Official` and shows why.
- **Two identical originals for one payment is a control failure**, not a convenience.
- A receipt whose payment has been voided (§11.5) **cannot be printed at all**; the screen shows the void reason, date and actor instead.
- Reprint requires `fees.receipt.reprint`; the count of reprints per receipt is visible on the payment record and on the audit trail.

---

## 19. Screen: Student Fee Statement

A running account in the classic ledger shape, and the document a parent asks for at the counter.

Columns: **Date | Reference | Description | Debit | Credit | Balance**, opening with a **Balance b/f** row.

| Event | Debit | Credit |
|---|---|---|
| Invoice issued | invoice total | |
| Fee adjustment (positive) | | adjustment amount |
| Fee adjustment (negative / surcharge) | surcharge amount | |
| Credit note issued | | credit note total |
| Payment received | | payment amount |
| **Payment voided** | payment amount | |
| Cheque bounced | payment amount | |
| Refund paid | refund amount | |
| Write-off approved | | write-off amount |

Running balance is computed in PHP through `Money`, in strict `(date, id)` order. **Voided payments appear as a credit and a matching debit** — they are never removed. A parent who sees a payment vanish from their statement will assume theft, and they will be right to ask.

Parameters: student, `as_of` date, academic year (or "all years"), language, include/exclude fully-settled invoices. Exports: PDF, XLSX, CSV. The header prints the `as_of` date, the aging axis (§11.3) and the school's fiscal identity.

---

## 20. The second debt stream — library fines

`06-assets-stores` gives students a second debt stream. Left unresolved, the report card's fee-balance block and the defaulters report both understate what is owed.

**Resolution.** Library fines on a **student** are receivables on **4111** exactly like fees, but they are **not** fee invoices. They are raised by the Library module as `Fine` records that emit `library.fine.levied`, which posts `Dr 4111 partner=student / Cr 707x or 758` (`02-accounting`'s event list adds `library.fine.levied`; v1 had only `.collected`, so the receivable was never recognised).

This module exposes one cross-module read: `Fees\Actions\GetStudentTotalObligation::forStudents(array $studentIds, Carbon $asOf): array`, returning `{ fee_outstanding, other_outstanding, total_outstanding, unallocated_credit }` where `other_outstanding` is the sum of published obligations from other modules implementing the `StudentObligationSource` contract (Library today; Welfare/damage charges later).

- The **Fee Collection** screen shows fee lines and, in a separate clearly-labelled block, other obligations. A single payment may settle both; `payment_allocations` targets the Library `Fine` through a polymorphic `obligation_type`/`obligation_id` pair as an alternative to `invoice_line_id`, and the `CHECK` in §11.6 extends to three mutually exclusive targets.
- The **report card fee block** and the **Transfer Certificate clearance gate** (§13) both use `total_outstanding`, not `fee_outstanding`.
- Fines on **staff** are a payroll deduction, not a receivable (`05-hr-payroll`), and never appear here.

---

## 21. Reports

| Report | Key parameters | Notes |
|---|---|---|
| Fee collection summary | date range, cash desk, method | Ties to the dashboard donut: Collected / Outstanding / Overdue |
| Aged receivables | `as_of`, buckets, section, class group | **Axis: instalment due date** (§11.3). Sourced from unlettered items (02-accounting C10) |
| Defaulters list | `as_of`, threshold, class group | Per §12.5 — excludes students in credit |
| Fee statement (student) | student, `as_of` | §19 |
| Third-party funds held & remitted | fund, period | §2.3. Must tie to the 47xx GL balance |
| Revenue recognition & deferral | fiscal year | §6. Feeds the year-end 477 entry |
| Adjustments & waivers register | period, reason type, granted_by | Fraud-control report; reason type breakdown |
| Credit notes register | period | Numbered sequence with gaps highlighted |
| Refunds register | period, method | |
| Write-offs & provisions | fiscal year | Feeds the DSF réintégration working paper |
| Collection by method | period | Reconciles to each treasury account and to the MoMo/bank statement |
| Cash desk close / variance | date, user | |
| Fee structure coverage | academic year | The §2.5 validator's output, as a report |
| Unallocated credits | `as_of` | The 4191 population |

---

## 22. Domain events emitted

All dispatched **after commit** (00-core §6.2 rule 6). Consumed by `02-accounting`'s posting engine.

`fee.invoice.issued` · `fee.invoice.cancelled` · `fee.adjustment.approved` · `fee.credit_note.issued` · `fee.payment.recorded` · `fee.payment.voided` · `fee.refund.issued` · `cheque.cleared` · `cheque.bounced` · `receivable.written_off` · `receivable.provision.recognized` · `receivable.reclassified` · `third_party_fund.remitted` · `revenue.deferred` · `revenue.deferral.reversed` · `cashdesk.closed_with_variance`

---

## 23. Open items requiring verification before Phase 6

Restating every `NEEDS VERIFICATION` in this document. **Per 00-core §16, each ships empty and the dependent feature refuses to run until configured.** A wrong seeded account code that looks authoritative is worse than a blank field.

| # | Item | Blocks |
|---|---|---|
| 1 | Class 47 sub-account for funds held on behalf of an APEE / examination board | Agent fee items (§2.3) |
| 2 | Compte 51 subdivision for *effets remis à l'encaissement* | Cheque payments (§2.4) |
| 3 | Donor-grant income account and matching scholarship expense account | `scholarship_donor_funded` adjustments (§8.1) |
| 4 | Late-payment penalty income account | `surcharge_late_payment` adjustments (§8.1) |
| 5 | Charge account for an uncollectible student receivable (ordinary vs HAO) | Write-offs (§10.1) |
| 6 | Compte **491** for depreciation of receivables, and its matching charge account | Doubtful-debt provisions (§10.2) |
| 7 | Cash-rounding difference account | Non-multiple-of-5 cash tenders (§16) |
| 8 | **6317** name and scope for mobile-money commission | Confirm only; 552 is verified (§15.6) |
| 9 | Whether the acompte d'IS / minimum tax base is defined on turnover in a way that agent-collected funds are excluded by presentation alone, or whether a separate declaration line is required | The §7 turnover exclusion (accountant sign-off, 00-core gate 6) |
| 10 | 707x sub-accounts the school will use for boarding, transport, development levy, library and ICT | Fee item configuration (§15.1) |

Items 1–8 are configuration blockers. Item 9 is a question for the school's accountant, folded into 00-core blocking gate 6. Item 10 is a school-specific extension choice, made in the setup wizard.
