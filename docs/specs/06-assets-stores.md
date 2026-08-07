# 06 — Assets, Inventory, Library

**Version:** 2.0
**Status:** Draft for review
**Owns:** fixed asset register, depreciation, disposal, impairment, revaluation, investment subsidies, asset custody and maintenance; stores and stock valuation; the library.
**Binding parent:** `00-core.md`. Where this document appears to disagree with `00-core.md`, `00-core.md` wins.

**Cross-references (do not duplicate):**
`02-accounting.md` — chart of accounts, `PostingRule`/`PostingRuleLine`, `JournalEntry`, `AccountingPeriod`, partner/auxiliary layer, analytic axes, reversal semantics, `piece_no`, period close ordering.
`03-tax-procurement.md` — `Supplier`, `PurchaseOrder`, `SupplierInvoice`, goods receipt, `TaxCode`, prorata de déduction, non-recoverable input VAT capitalisation.
`04-fees.md` — `Invoice`, `PaymentAllocation`, student receivable, the unified-student-debt resolution (§10.7 below).
`05-hr-payroll.md` — payroll deduction mechanism used by staff library fines.
`07-students.md` — `Enrollment`, `EnrollmentSegment`; library membership keys.
`09-ui.md`, `10-documents.md` — screen chrome and printable layout.

**Build phase:** 9 (Assets & stores). Depends on Phase 4 (ledger), Phase 5 (procurement & tax).

---

## 0. Scope, and the two defects this document exists to fix

1. **Inventory could not post a stock issue at all.** v1 defined `StockMovement` as a signed quantity delta with no `unit_cost`, and no valuation method. The `stock.issued` posting rule named accounts but had no amount source, so the rule was unexecutable. §7 chooses **weighted average cost** and specifies the arithmetic to the franc.
2. **Depreciation, disposal and donated assets had no defensible SYSCOHADA treatment.** §4–§6 specify gross disposal, `mise en service` start, subsidy release, and a catch-up formula that makes runs idempotent by construction rather than by assertion.

**Not in scope here:** the purchase side (`03`), the sale-to-parent receivable (`04`), the ledger mechanics (`02`).

---

## 1. Conventions used throughout

| Concern | Rule (from `00-core.md`) |
|---|---|
| Money | `BIGINT SIGNED`, whole FCFA. Never `DECIMAL`, never `FLOAT`. |
| Rates | integer basis points, `BIGINT`. 12.5% = `1250`. |
| Quantities | `DECIMAL(14,3)` — see §7.1 for why quantity is *not* money and *not* integer |
| Identifier columns | `utf8mb4_0900_as_cs` (`tag_number`, `item_code`, `isbn`, `barcode`, `accession_no`) |
| Deletion | RESTRICT on anything with financial history; `is_archived` flag on reference data |
| Dual calendar | every financially-significant row carries **both** `fiscal_year_id` and `academic_year_id` |
| Idempotency | every mutating Action takes an `idempotency_key` |
| Events | posting-triggering events are `ShouldDispatchAfterCommit` |

**Account-code discipline.** Every account referenced below is either (a) stated by `02-accounting.md` as verified, or (b) marked **`NEEDS VERIFICATION`**. A code marked NEEDS VERIFICATION is **not seeded**: the migration creates the configuration column, the seeder leaves it `NULL`, and the dependent Action **refuses to run** with a message naming the missing configuration. This is `00-core.md` §16 applied at column granularity. See the consolidated register in §11.

---

# PART A — FIXED ASSETS

## 2. Entities

### 2.1 `asset_categories`

Reference data. Owns both the accounting and the fiscal depreciation policy, and the three inventory-style account links used when an asset is bought through stores.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(20) `as_cs` | **UNIQUE** |
| `name`, `name_fr` | VARCHAR(120) | |
| `parent_id` | BIGINT NULL | FK self, **ON DELETE RESTRICT**; max depth 3, cycle check in Action |
| `asset_account_id` | FK `chart_of_accounts` RESTRICT | the class-2 gross account (e.g. 2442) |
| `accumulated_depreciation_account_id` | FK RESTRICT | the mirroring class-28 account |
| `depreciation_expense_account_id` | FK RESTRICT | 681x — *subdivision NEEDS VERIFICATION* |
| `disposal_nbv_account_id` | FK RESTRICT | class 81 (811/812/816) |
| `disposal_proceeds_account_id` | FK RESTRICT | class 82 (821/822/826) |
| `impairment_provision_account_id` | FK NULL RESTRICT | class 29 — *subdivision NEEDS VERIFICATION* |
| `impairment_expense_account_id` | FK NULL RESTRICT | *NEEDS VERIFICATION* |
| `revaluation_equity_account_id` | FK NULL RESTRICT | écart de réévaluation — *106 NEEDS VERIFICATION* |
| `in_progress_account_id` | FK NULL RESTRICT | assets under construction — *249 / 23-series NEEDS VERIFICATION* |
| `depreciation_method` | ENUM(`none`,`straight_line`,`declining_balance`) | `none` is legitimate: land |
| `useful_life_months` | SMALLINT UNSIGNED NULL | NULL iff method = `none` |
| `declining_rate_bp` | BIGINT NULL | required iff method = `declining_balance` |
| `default_residual_rate_bp` | BIGINT NOT NULL DEFAULT 0 | see §2.2 residual reconciliation |
| `prorata_convention` | ENUM(`daily`,`monthly`,`full_month`,`half_year`) NULL | **NULL until configured — see §5.2** |
| `tax_method` | ENUM(`none`,`straight_line`,`declining_balance`) NULL | fiscal (CGI) policy |
| `tax_rate_bp` | BIGINT NULL | CGI maximum deductible rate — *table NEEDS VERIFICATION* |
| `tax_useful_life_months` | SMALLINT NULL | |
| `derogatory_depreciation_account_id` | FK NULL RESTRICT | *151 NEEDS VERIFICATION* |
| `capitalisation_threshold` | BIGINT SIGNED NOT NULL DEFAULT 0 | FCFA; `0` = capitalise everything |
| `below_threshold_behaviour` | ENUM(`expense_only`,`expense_and_track`) NOT NULL DEFAULT `expense_only` | |
| `below_threshold_expense_account_id` | FK NULL RESTRICT | required iff threshold > 0 |
| `requires_serial_number` | BOOLEAN DEFAULT 0 | |
| `is_archived` | BOOLEAN DEFAULT 0 | never `SoftDeletes` |

**Invariants**
- `A1` `depreciation_method = 'none'` ⟺ `useful_life_months IS NULL`. CHECK-enforced.
- `A2` `declining_balance` requires `declining_rate_bp IS NOT NULL`.
- `A3` `asset_account_id` must resolve to a class-2 account; `accumulated_depreciation_account_id` to class 28. Validated in the Action against `ChartOfAccount.code` prefix, not by CHECK (the chart is data).
- `A4` `capitalisation_threshold > 0` requires `below_threshold_expense_account_id`.
- `A5` Changing any account FK on a category with posted assets is **forbidden**. Create a successor category and reassign, so history stays reconcilable. Enforced in the Action, not by DB.

### 2.2 `assets`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `tag_number` | VARCHAR(40) `as_cs` NOT NULL | **UNIQUE** — the physical sticker |
| `serial_number` | VARCHAR(80) `as_cs` NULL | **UNIQUE** (MySQL permits many NULLs; that is correct here — "no serial" is not a duplicate) |
| `asset_category_id` | FK RESTRICT | |
| `parent_asset_id` | BIGINT NULL FK self RESTRICT | component accounting, §4.6 |
| `name`, `description` | | |
| `status` | ENUM(`draft`,`in_progress`,`in_service`,`idle`,`under_maintenance`,`impaired`,`disposed`,`written_off`,`lost`) | |
| `acquisition_date` | DATE NOT NULL | |
| `acquisition_cost` | BIGINT SIGNED NOT NULL, CHECK ≥ 0 | see `cost_basis` |
| `cost_basis` | ENUM(`ht`,`ttc_non_recoverable_vat_capitalised`) NOT NULL | required by `03-tax-procurement.md` prorata rule |
| `non_recoverable_vat_amount` | BIGINT NOT NULL DEFAULT 0 | included in `acquisition_cost` when basis is `ttc_…` |
| `residual_value` | BIGINT NOT NULL DEFAULT 0 | **an amount.** See invariant `A7` |
| `in_service_date` | DATE NULL | **mandatory before any depreciation** |
| `depreciation_start_date` | DATE NULL | *derived*, stored, immutable once first schedule row exists |
| `useful_life_months` | SMALLINT NULL | copied from category at capitalisation; independently editable (change in estimate, §5.5) |
| `depreciation_method` | ENUM as category | copied at capitalisation |
| `prorata_convention` | ENUM as category | copied at capitalisation — **snapshot, not a live lookup** |
| `acquisition_type` | ENUM(`purchase`,`donation`,`grant_funded`,`self_constructed`,`transfer_in`,`opening_balance`) | |
| `fair_value_at_donation` | BIGINT NULL | required iff `acquisition_type = 'donation'` |
| `donor_id` | BIGINT NULL FK `partners`/`suppliers` RESTRICT | |
| `investment_subsidy_id` | BIGINT NULL FK RESTRICT | §6 |
| `supplier_id` | BIGINT NULL FK RESTRICT | |
| `supplier_invoice_id` | BIGINT NULL FK RESTRICT | |
| `location_id` | FK `rooms`/`store_locations` NULL SET NULL | physical location only, no accounting effect |
| `custodian_staff_id` | BIGINT NULL FK RESTRICT | |
| `school_section_id` | BIGINT NULL FK RESTRICT | drives the default analytic split |
| `fiscal_year_id`, `academic_year_id` | FK RESTRICT | dual calendar, `02-accounting.md` C3 |
| `insurance_policy_ref`, `warranty_expires_on` | | |
| `disposal_id` | BIGINT NULL FK | set when disposed |
| `notes` | TEXT | |

**Invariants**
- `A6` `in_service_date >= acquisition_date`.
- `A7` **Residual reconciliation.** `AssetCategory.default_residual_rate_bp` is a *default generator*, never a live divisor. At capitalisation the Action computes `residual_value = floor(acquisition_cost × default_residual_rate_bp / 1_000_000)` and stores the **amount**. All depreciation arithmetic reads `Asset.residual_value` only. v1's ambiguity between the two is thereby closed.
- `A8` `residual_value < acquisition_cost` (CHECK), and `residual_value >= 0`.
- `A9` Depreciation may not be computed while `in_service_date IS NULL`. The `RunDepreciation` Action **skips** such assets and lists them in the run's exception report — it does not silently ignore them.
- `A10` `parent_asset_id` may not form a cycle; depth ≤ 3. Cycle check by ancestor walk inside the Action under `FOR UPDATE` on the root.
- `A11` A parent asset that has components is **not itself depreciated** on the componentised portion: `Asset.acquisition_cost` on the parent excludes every component's cost. Enforced by the `SplitIntoComponents` Action, which debits nothing new — it reduces the parent and creates children summing exactly to the reduction (`Money::allocate`, largest remainder). Assertion: `parent.cost + Σ children.cost` is unchanged before and after.
- `A12` `status = 'disposed'` requires `disposal_id IS NOT NULL` and freezes every mutating Action except reversal.
- `A13` No hard delete, ever (AUDCIF Art. 24, ten years). Global model observer per `02-accounting.md` C5.

### 2.3 `asset_custody_movements`

Append-only. `asset_id`, `from_staff_id` NULL, `to_staff_id`, `from_location_id`, `to_location_id`, `moved_on`, `reason`, `acknowledged_at` NULL, `acknowledged_by` NULL, `document_ref`. No accounting effect. Index `(asset_id, moved_on)`.

### 2.4 `asset_maintenance_requests`

`asset_id` NULL (a request may precede identification), `inventory_item_id` NULL (the mockup links maintenance from the Inventory screen), `reported_by`, `reported_at`, `priority`, `status(open|assigned|in_progress|done|cancelled)`, `assigned_to_staff_id`, `supplier_id` NULL, `estimated_cost`, `actual_cost`, `closed_at`, `supplier_invoice_id` NULL.

**Accounting rule:** maintenance is expensed unless it *extends useful life or capacity*, in which case it is a **capitalisation** creating either an increase in `acquisition_cost` (with a prospective useful-life review) or a new component. The Action requires an explicit operator choice — `expense` or `capitalise` — and records the justification. It never infers from amount.

---

## 3. Assets under construction

Schools build classroom blocks across two or three exercices; a work-in-progress asset must not depreciate.

- An `Asset` with `status = 'in_progress'` posts accumulated cost to `AssetCategory.in_progress_account_id` (*NEEDS VERIFICATION*: `249` for corporeal in-progress, and the 23-series in-progress account for buildings — `02-accounting.md` must confirm both before seeding).
- Cost accumulates through `asset_construction_costs` (`asset_id`, `supplier_invoice_id` NULL, `journal_entry_id`, `amount`, `incurred_on`, `description`) — append-only.
- **`CommissionAssetAction`** requires `in_service_date`, transfers the accumulated balance from the in-progress account to `asset_category.asset_account_id`, sets `status = 'in_service'`, derives `depreciation_start_date`, and dispatches `asset.commissioned` after commit.
- **Invariant `A14`:** an `in_progress` asset produces **no** `DepreciationSchedule` row. Enforced by a WHERE clause in the run and asserted by a Pest test.

---

## 4. Depreciation

### 4.1 `depreciation_runs`

| Column | Notes |
|---|---|
| `id` | PK |
| `fiscal_year_id` | FK RESTRICT |
| `period_month` | TINYINT 1–12 (`00-core.md` §5 naming) |
| `status` | ENUM(`draft`,`calculated`,`approved`,`posted`,`cancelled`) |
| `run_by`, `run_at`, `approved_by`, `approved_at` | actor FKs RESTRICT |
| `journal_entry_id` | NULL until posted, FK RESTRICT |
| `assets_processed`, `total_charge` | denormalised, for the run report |
| `exceptions_json` | assets skipped and why |
| `idempotency_key` | UNIQUE |

**`UNIQUE(fiscal_year_id, period_month)`** — this is the mechanism v1 asserted and never built.
**Locking:** `SELECT … FOR UPDATE` on the `DepreciationRun` row (`00-core.md` §11). Status transitions use conditional `UPDATE … WHERE status = 'calculated'` with an affected-rows check.
**Segregation of duties:** `approved_by <> run_by`, enforced in the Action (`02-accounting.md` maker-checker).

### 4.2 `depreciation_schedules`

One row per asset per period, both bases.

| Column | Notes |
|---|---|
| `asset_id` | FK RESTRICT |
| `depreciation_run_id` | FK RESTRICT |
| `fiscal_year_id`, `period_month` | |
| `basis` | ENUM(`accounting`,`fiscal`) NOT NULL |
| `opening_accumulated` | BIGINT |
| `charge` | BIGINT SIGNED — **signed**: a catch-up correction can be negative |
| `closing_accumulated` | BIGINT |
| `net_book_value` | BIGINT, derived and stored for reporting |
| `depreciable_base` | BIGINT — `cost − residual` as at this period |
| `months_elapsed` | SMALLINT |
| `is_catch_up` | BOOLEAN |
| `journal_entry_id` | NULL for `fiscal` basis (never posted) |

**Constraints**
- **`UNIQUE(asset_id, fiscal_year_id, period_month, basis)`** (`00-core.md` §10.4, extended by `basis`).
- **`UNIQUE(asset_id, depreciation_run_id, basis)`**.
- `CHECK (closing_accumulated = opening_accumulated + charge)`.
- `CHECK (closing_accumulated <= depreciable_base)` — the cap that stops over-depreciation.

**Only the `accounting` basis posts to the ledger.** The `fiscal` basis feeds the DSF réintégrations working paper and, if the school elects it, the amortissement dérogatoire (§4.7).

### 4.3 The catch-up formula (this is the whole idempotency design)

For asset *a* and target period end date *T*:

```
entitlement(a, T) = min( depreciable_base(a),
                         round_half_up( depreciable_base(a) × elapsed_units(a, T) / total_units(a) ) )

charge(a, T)      = entitlement(a, T) − Σ(charges already posted for a, accounting basis)
```

- `depreciable_base = acquisition_cost − residual_value` (straight-line).
- `elapsed_units` / `total_units` are in the unit implied by `prorata_convention` (days or months).
- **Consequences, all desirable:**
  - Re-running a posted period yields `charge = 0` and produces **no journal line**. Idempotent without a no-op guard.
  - An asset capitalised after a run has already posted receives its full arrears in the next run, correctly dated, as a single `is_catch_up = true` row.
  - The `min(depreciable_base, …)` cap makes the final period absorb the rounding residual automatically, satisfying `00-core.md` §7.3: *accumulated depreciation exactly equals `cost − residual`*. No separate "last period" special case is needed.
  - A useful-life correction (§5.5) is absorbed prospectively without restating prior periods, because `entitlement` is recomputed from current parameters while `Σ posted` is historical fact.
- **Declining balance:** `charge = round_half_up(NBV_opening × declining_rate_bp / 1_000_000)`, floored so `closing_accumulated ≤ depreciable_base`. Catch-up for declining balance is computed by **replaying** the period sequence from `depreciation_start_date` to *T* in memory and differencing against posted totals — a closed-form entitlement does not exist. The replay is deterministic and pure (`Domain/`, no Eloquent).

### 4.4 Worked example — acquisition and depreciation

School buys a minibus. Supplier invoice, HT 30,000,000, TVA 19.25% = 5,775,000. The school's `prorata_de_deduction` for the year is 0% (tuition exempt, no taxable activity attributable) — so **the input VAT is non-recoverable and capitalised** (`03-tax-procurement.md`).

`acquisition_cost = 35,775,000`, `cost_basis = ttc_non_recoverable_vat_capitalised`, `non_recoverable_vat_amount = 5,775,000`.
`residual_value = 0`. `useful_life_months = 120`. Straight line. `acquisition_date = 2026-07-20`, `in_service_date = 2026-09-01`, convention `monthly`.

**Acquisition entry** (posted by `03`'s supplier-invoice Action, which calls `CapitaliseAssetAction` in the same transaction):

| Account | Label | Debit | Credit |
|---|---|---|---|
| 245x | Matériel de transport *(subdivision NEEDS VERIFICATION)* | 35,775,000 | |
| 481x | Fournisseurs d'investissements | | 35,775,000 |

Note the credit is **481 Fournisseurs d'investissements**, not 401 — capex, per `03-tax-procurement.md`.

**Depreciation.** `depreciable_base = 35,775,000`. `total_units = 120` months. Monthly charge is *not* stored as a constant; each period recomputes entitlement.

- 2026-09 (`elapsed = 1`): entitlement = round(35,775,000 × 1/120) = 298,125. Posted Σ = 0. **charge = 298,125.**
- 2026-10: entitlement = round(35,775,000 × 2/120) = 596,250. Posted Σ = 298,125. **charge = 298,125.**
- Depreciation for FY2026 (Sept–Dec, 4 months) = 1,192,500.

| Account | Label | Debit | Credit |
|---|---|---|---|
| 681x | Dotations aux amortissements d'exploitation *(subdivision NEEDS VERIFICATION)* | 298,125 | |
| 284x | Amortissements du matériel de transport *(subdivision NEEDS VERIFICATION)* | | 298,125 |

**Now the catch-up case.** Suppose the asset was keyed in late and only capitalised on 2026-12-20, after the September, October and November runs had posted. The December run computes: entitlement = round(35,775,000 × 4/120) = 1,192,500; Σ posted = 0; **charge = 1,192,500**, one row, `is_catch_up = true`. No back-dated entry into closed periods — which is also what AUDCIF Art. 22 requires (`02-accounting.md` C4: record in the first open period, retain the original value date). `DepreciationSchedule.period_month = 12`, and the underlying `JournalEntry.value_date` is 2026-09-01.

**Rounding proof at the end of life.** Σ of 120 charges of 298,125 = 35,775,000 exactly here. When it does not divide evenly — say cost 1,000,000 over 7 months — entitlements run 142,857 / 285,714 / 428,571 / 571,429 / 714,286 / 857,143 / **1,000,000**, giving charges 142,857 / 142,857 / 142,857 / 142,858 / 142,857 / 142,857 / **142,857**. Σ = 1,000,000. The residual lands wherever the rounding puts it, and the cap guarantees the total. This is the property test.

### 4.5 Mid-period disposal

An asset disposed on day *d* of period *P* is depreciated **up to and including the disposal date** under its own convention, in the same transaction as the disposal, *before* the NBV is derived. It then produces no further schedule rows (the run's WHERE excludes `status IN ('disposed','written_off','lost')`). If a scheduled run for *P* later executes, `charge = entitlement − Σ posted = 0`.

### 4.6 Component accounting (*approche par composants*)

- A component is an `Asset` with `parent_asset_id` set, its **own** `useful_life_months` and its own schedule.
- **The parent is not double-depreciated** — invariant `A11` guarantees the parent's cost excludes component cost.
- Disposal of the parent **cascades**: `DisposeAssetAction` disposes every descendant in the same transaction, each posting its own gross 81/82 legs. Attempting to dispose a component independently is permitted (a replaced engine) and does not affect the parent.
- Cycle prevention: `A10`.

### 4.7 Fiscal divergence

The `fiscal` basis schedule is computed by the same engine with `tax_method` / `tax_rate_bp` / `tax_useful_life_months`. Two dispositions, an explicit school-level setting:

1. **`reintegration` (default).** Nothing posts. The difference `Σ accounting charge − Σ fiscal charge` per fiscal year appears on the **réintégrations working paper** feeding the DSF.
2. **`derogatory`.** The excess of fiscal over accounting is posted as an *amortissement dérogatoire* to compte **151** under 15 Provisions réglementées, with the counterpart in class 85. **Both codes NEEDS VERIFICATION** — until `02-accounting.md` confirms them, this option is not selectable in the UI and the setting is hard-disabled.

CGI maximum deductible rates per asset class: **NEEDS VERIFICATION.** Seeder ships the `tax_rate_bp` column empty; the fiscal basis is simply not generated until a rate is entered.

---

## 5. Depreciation conventions and estimate changes

### 5.1 `depreciation_start_date` derivation

```
depreciation_start_date = in_service_date        (all conventions; the convention governs
                                                  how the FIRST period is measured, not the date)
```

### 5.2 `prorata_convention` semantics — stated because the standard is unverified

| Value | `elapsed_units` for period end *T* | `total_units` |
|---|---|---|
| `daily` | days from `depreciation_start_date` to *T* inclusive | `useful_life_months × 30` (a 360-day commercial year) |
| `monthly` | whole months elapsed, where the start month counts iff `day(start) ≤ 15` | `useful_life_months` |
| `full_month` | whole months elapsed, start month always counts in full | `useful_life_months` |
| `half_year` | 6 months in the year of commissioning regardless of date, then 12/yr | `useful_life_months` |

> **NEEDS VERIFICATION — blocking for Phase 9.** Whether SYSCOHADA/AUDCIF prescribes daily prorata, monthly prorata, or leaves it to the entity's stated accounting policy, and whether the 360-day convention above is the accepted commercial-year basis in OHADA practice. **The seeder leaves `prorata_convention` NULL and `RunDepreciation` refuses to execute** until every category with a depreciating method has one set, presenting this table and requiring the school's accountant to choose. Two schools must not get different numbers from the same product by accident; they may get different numbers by *declared policy*.

Whichever value is chosen is **snapshotted onto `Asset` at capitalisation**, so a later category edit cannot retroactively rewrite a posted schedule.

### 5.3 Depreciation policy is per-asset once capitalised

`Asset.depreciation_method`, `useful_life_months`, `prorata_convention` and `residual_value` are copies, not lookups. Category edits affect only assets capitalised afterwards. This is the same reproducibility principle `05-hr-payroll.md` applies to payslips and `01-assessment.md` to report cards.

### 5.4 Ordering within the year-end close

Depreciation runs at the position `02-accounting.md` C8 fixes: soft lock → physical inventory → cut-off → doubtful debt → **depreciation** → provisions → trial balance → tax provision → closing → appropriation → à-nouveaux → hard lock. Depreciation therefore reads a stock and asset population that is already counted, and it is a mandatory signed-off step on the `YearEndChecklist`.

### 5.5 Change in estimate

Correcting `useful_life_months` or `residual_value` is a **change in accounting estimate, applied prospectively.** No restatement, no reversal of prior charges. Mechanically this needs no special code: §4.3's `entitlement − Σ posted` produces the corrected go-forward charge automatically, and the difference is absorbed over the remaining life. The Action requires a `reason` and writes an `AuditLog` entry; if the new entitlement is **below** Σ already posted, `charge` is negative and the entry is a credit to 681x — which is correct, and is why `charge` is `BIGINT SIGNED`.

---

## 6. Disposal, donation, impairment, revaluation

### 6.1 `asset_disposals`

| Column | Notes |
|---|---|
| `asset_id` | FK RESTRICT, **UNIQUE** (one disposal per asset; a reversal creates no second row, it cancels this one) |
| `disposal_type` | ENUM(`sale`,`scrap`,`donation_out`,`loss`,`theft`,`trade_in`) |
| `disposal_date` | DATE |
| `proceeds_amount` | BIGINT NOT NULL DEFAULT 0, CHECK ≥ 0 |
| `buyer_partner_id` | NULL FK RESTRICT — required iff `disposal_type = 'sale'` |
| `settlement` | ENUM(`receivable`,`cash`,`bank`,`mobile_money`) |
| `nbv_at_disposal` | BIGINT — snapshotted |
| `accumulated_at_disposal` | BIGINT — snapshotted |
| `gain_or_loss` | BIGINT SIGNED **GENERATED ALWAYS AS (`proceeds_amount − nbv_at_disposal`) STORED** |
| `approved_by`, `approved_at`, `reason`, `document_ref` | |
| `journal_entry_id` | FK RESTRICT |

`gain_or_loss` is a **generated column** — it is a derived reporting figure, it is **never posted**, and being generated it cannot drift from its inputs. That closes v1's independent-storage defect by construction.

### 6.2 Worked example — disposal, gross

The minibus from §4.4. `acquisition_cost = 35,775,000`, straight-line over 120 months from 2026-09-01, `residual = 0`, convention `monthly`. Sold on **2029-12-31** for **22,000,000** to a partner on credit.

Depreciation to disposal: elapsed = 2026-09 → 2029-12 = **40 months**.
`accumulated_at_disposal = round(35,775,000 × 40/120) = 11,925,000`.
`nbv_at_disposal = 35,775,000 − 11,925,000 = 23,850,000`.

**Leg 1 — retire the asset at gross (SYSCOHADA requires the two P&L lines; a net posting is the v1 defect, `02-accounting.md` C7):**

| Account | Label | Debit | Credit |
|---|---|---|---|
| 284x | Amortissements matériel de transport | 11,925,000 | |
| 812 | Valeurs comptables des cessions d'immobilisations corporelles | 23,850,000 | |
| 245x | Matériel de transport | | 35,775,000 |

**Leg 2 — recognise the proceeds:**

| Account | Label | Debit | Credit |
|---|---|---|---|
| 485 | Créances sur cessions d'immobilisations | 22,000,000 | |
| 822 | Produits des cessions d'immobilisations corporelles | | 22,000,000 |

Both legs are lines of **one** `JournalEntry` (one `piece_no`, `Σdebit = Σcredit = 35,850,000`). Line 1 of leg 2 carries `partner_type = 'partner'`, `partner_id = buyer` because 485 is configured `is_collective` (`02-accounting.md` C2).

`gain_or_loss` = 22,000,000 − 23,850,000 = **−1,850,000**. It appears on the disposal report and the fixed-asset note. **It appears in no journal line.** The P&L already carries the loss correctly as 812 (23,850,000 debit) against 822 (22,000,000 credit).

`812` / `822` are stated as verified by `02-accounting.md` C7. The 245x and 284x subdivisions are **NEEDS VERIFICATION**.

**Scrap with zero proceeds:** leg 1 only. **Fully-depreciated scrap:** leg 1 with `812 = 0` — and a zero-amount line violates `00-core.md` §10.3 `CHECK ((debit = 0) <> (credit = 0))`, so the posting rule **omits** the 812 line entirely rather than posting zero. The entry is then `Dr 284x / Cr 245x` for the full cost.

### 6.3 `investment_subsidies` — donated and grant-funded assets

A donated 30M FCFA bus at zero understates assets and depreciation; at fair value with the credit dumped into class 7 it fabricates operating income. The SYSCOHADA treatment is **14 Subventions d'investissement**, released to income in step with the depreciation charge.

| Column | Notes |
|---|---|
| `id` | PK |
| `reference` | VARCHAR `as_cs` **UNIQUE** |
| `donor_partner_id` | FK RESTRICT |
| `subsidy_account_id` | FK RESTRICT — class 14 (*subdivision NEEDS VERIFICATION*) |
| `release_income_account_id` | FK RESTRICT — **845 NEEDS VERIFICATION** (see below) |
| `granted_amount` | BIGINT |
| `granted_on` | DATE |
| `agreement_ref`, `conditions` | |
| `fiscal_year_id`, `academic_year_id` | |
| `status` | ENUM(`active`,`fully_released`,`clawed_back`) |

`investment_subsidy_releases`: `investment_subsidy_id`, `asset_id`, `depreciation_run_id`, `fiscal_year_id`, `period_month`, `amount`, `journal_entry_id`.
**`UNIQUE(investment_subsidy_id, asset_id, depreciation_run_id)`** — the same idempotency shape as the schedule.

> **NEEDS VERIFICATION — 845.** Compte 84 *Produits hors activités ordinaires* includes a 845 subdivision, which is the plausible home for the subsidy release (*Quote-part de subventions d'investissement virée au résultat*). The commonly-cited **865 does not appear in the revised compte 86 listing** and must not be used. Until confirmed, `release_income_account_id` is NULL, the release step is **skipped with an exception** and the asset still depreciates — the subsidy simply sits in 14 until configured. It is never posted to a guessed account.

### 6.4 Worked example — donated asset and subsidy release

A partner donates a bus, fair value **30,000,000**, received 2026-09-01, commissioned same day, 10-year straight line, residual 0, convention `monthly`.

`acquisition_type = 'donation'`, `fair_value_at_donation = 30,000,000`, `acquisition_cost = 30,000,000`, `investment_subsidy_id` set, `granted_amount = 30,000,000`.

**On donation:**

| Account | Label | Debit | Credit |
|---|---|---|---|
| 245x | Matériel de transport | 30,000,000 | |
| 14x | Subventions d'investissement *(subdivision NEEDS VERIFICATION)* | | 30,000,000 |

**Each month, two entries — the charge and the mirrored release.** Monthly entitlement step = round(30,000,000 × n/120) − prior = **250,000**.

| Account | Label | Debit | Credit |
|---|---|---|---|
| 681x | Dotations aux amortissements | 250,000 | |
| 284x | Amortissements matériel de transport | | 250,000 |

| Account | Label | Debit | Credit |
|---|---|---|---|
| 14x | Subventions d'investissement | 250,000 | |
| 845 | Quote-part de subvention virée au résultat *(NEEDS VERIFICATION)* | | 250,000 |

**Net P&L effect: zero**, every month, for ten years — which is the whole point. The balance sheet carries the asset at NBV and the remaining subsidy at exactly the same figure.

**Release proportion rule.** The release is *the same proportion of the subsidy as the charge is of the depreciable base*, computed as an entitlement-and-difference, mirroring §4.3 so that catch-up and rounding behave identically:

```
release_entitlement(T) = min( granted_amount,
                              round_half_up( granted_amount × cumulative_charge(T) / depreciable_base ) )
release(T)             = release_entitlement(T) − Σ releases already posted
```

**Partial funding.** If the grant covers only part of the cost (e.g. an 18,000,000 grant toward a 30,000,000 bus), the same formula releases 60% of each period's charge. Σ releases = 18,000,000 exactly at end of life, by the same `min()` cap.

**On disposal before full release,** the unreleased balance of the subsidy is written off to the same 845 account in the disposal transaction. This must **not** be netted against 812/822.

**Grant clawback** (`status = 'clawed_back'`) reverses the unreleased balance against a liability to the donor. Posting rule `asset.subsidy.clawed_back` — add to the `02-accounting.md` event list.

### 6.5 `asset_impairments`

`asset_id`, `test_date`, `carrying_amount`, `recoverable_amount`, `impairment_loss` (= carrying − recoverable, CHECK > 0), `basis` (`value_in_use`|`fair_value_less_costs`), `evidence_ref`, `approved_by/at`, `journal_entry_id`, `reversed_by_impairment_id` NULL.

Posting `Dr` impairment expense `/ Cr` class 29 provision. **Both accounts NEEDS VERIFICATION**; the feature is configuration-gated exactly as §6.3.
After impairment, the depreciable base becomes `carrying_amount − residual` over the remaining life — a change in estimate (§5.5), absorbed by the entitlement formula. `status` moves to `impaired`.

### 6.6 `asset_revaluations`

Under OHADA, revaluation is a **regulated campaign** (legal or free revaluation, applied to a whole category, with prescribed disclosure), not a free-form edit of one row. Therefore:

- `revaluation_campaigns`: `reference`, `legal_basis`, `campaign_date`, `asset_category_ids`, `approved_by/at`, `status`.
- `asset_revaluations`: `campaign_id`, `asset_id`, `carrying_before`, `revalued_amount`, `écart` (SIGNED), `journal_entry_id`.
- Credit goes to **`AssetCategory.revaluation_equity_account_id`** — *écart de réévaluation, **106 NEEDS VERIFICATION***.
- **The feature ships disabled** behind a permission and the account gate. A school cannot revalue by accident.

---

# PART B — INVENTORY / STORES

## 7. Valuation — the decision

### 7.1 Method: weighted average cost, maintained as a value total

**Chosen: weighted average cost (CUMP — coût unitaire moyen pondéré).**

Rationale: SYSCOHADA admits CUMP and FIFO; CUMP is dramatically simpler under concurrency (one row to lock, no layer table, no layer-consumption ordering) and eliminates the class of bug where two concurrent issues consume the same FIFO layer. LIFO is not admitted.

**The critical design point: the average is never stored as a unit price.** Storing `weighted_avg_cost` as a rounded integer per unit and multiplying it by quantity accumulates error until the stock ledger no longer ties to the GL. Instead each `(item, location)` balance row carries:

- `quantity_on_hand` `DECIMAL(14,3)`
- `value_on_hand` `BIGINT SIGNED` — whole FCFA, **authoritative**

and the unit cost is *derived at the moment of use*:

```
unit_cost_derived = value_on_hand / quantity_on_hand      (rational, never stored)
issue_cost        = round_half_up( issue_qty × value_on_hand / quantity_on_hand )
```

with one override: **if `issue_qty == quantity_on_hand`, `issue_cost = value_on_hand` exactly**, so emptying a bin always leaves value zero. This is the same "last instalment absorbs the residual" rule as `00-core.md` §7.3, applied to stock.

`items.weighted_avg_cost` **may** be materialised as a display column (the mockup shows "Unit Price (FCFA)"), but it is documented as *derived, display-only, never an input to any posting*. An architecture test asserts no Action reads it.

**Quantity type.** `DECIMAL(14,3)`. Stores hold litres of fuel and kilograms of rice as well as pieces. `00-core.md`'s no-DECIMAL rule is about **money**; quantity is not money and is explicitly allow-listed.

**Only receipts change the average.** Issues, transfers-out, and negative stock-take adjustments consume at the current derived average and leave it unchanged. Transfers-in at the sending location's derived cost. This is invariant `I1` and is Pest-tested.

### 7.2 `item_categories`

`code` **UNIQUE**, `name`, `name_fr`, `parent_id` RESTRICT, `is_archived`, plus the three accounts `02-accounting.md` C6 requires:

| Column | Purpose | Typical code |
|---|---|---|
| `purchase_account_id` | Achats | 601 marchandises / 602 matières premières / 604 fournitures consommables |
| `stock_account_id` | Stock (balance sheet) | 31 Marchandises / 32 Matières premières / 33 Autres approvisionnements |
| `variation_account_id` | Variation des stocks | 6031 / 6032 / 6033 |
| `sales_account_id` NULL | required iff any item is `merchandise` | 701 Ventes de marchandises |
| `cost_of_sales_uses_variation` | BOOLEAN DEFAULT 1 | see §8.5 |
| `default_tax_code_id` NULL | FK, `03-tax-procurement.md` | |

`601`/`604`/`6031`/`6032`/`6033`/`31`/`32`/`33`/`701` are stated as verified by `02-accounting.md` C6. **Their finer subdivisions (e.g. `3111`) are NEEDS VERIFICATION** and the school extends at 5+ digits per `02-accounting.md`'s codification lock.

**Invariant `I2`:** all three (four for merchandise) accounts are mandatory before an item in the category can move. `PostStockMovement` refuses otherwise, naming the missing account.

### 7.3 `items`

| Column | Notes |
|---|---|
| `item_code` | VARCHAR(30) `as_cs` **UNIQUE** — `ITM0001` in the mockup |
| `barcode` | VARCHAR(64) `as_cs` **UNIQUE** NULL — mockup: "Search by name, code, barcode" |
| `name`, `description` | |
| `item_category_id` | FK RESTRICT |
| `item_type` | ENUM(`consumable`,`equipment`,`merchandise`) NOT NULL — from the mockup's *Item Type* filter |
| `unit_of_measure_id` | FK RESTRICT (`PCS`, `BOX`, `KG`, `L`) |
| `is_stock_tracked` | BOOLEAN DEFAULT 1 |
| `reorder_level`, `reorder_quantity` | DECIMAL(14,3) — drives *Low Stock* |
| `standard_sale_price` | BIGINT NULL — merchandise only |
| `sale_tax_code_id` | FK NULL |
| `asset_category_id` | FK NULL — set when an `equipment` receipt should also create an `Asset` (§8.6) |
| `status` | ENUM(`active`,`discontinued`,`archived`) — mockup shows *Discontinued* |
| `image_path`, `notes` | |

**Invariants**
- `I3` `item_type = 'merchandise'` requires `item_category_id` to have `sales_account_id`.
- `I4` `item_type = 'equipment'` **may** carry `asset_category_id`; if set, receipt triggers asset creation (§8.6).
- `I5` `status = 'discontinued'` blocks receipts and sales but permits issues, transfers and stock-takes — you must be able to run down the remaining stock. `archived` blocks everything and requires `quantity_on_hand = 0` at every location.

### 7.4 `store_locations`

`code` **UNIQUE**, `name`, `type` (`store`|`lab`|`av_room`|`library`|`kitchen`|`classroom`), `school_section_id` NULL, `keeper_staff_id` NULL RESTRICT, `is_sellable_point` BOOLEAN, `is_active`. The mockup's *Location* filter and "Store A / Store B / Lab Store / AV Room / Library" values map directly.

### 7.5 `stock_balances` — the locked row

| Column | Notes |
|---|---|
| `item_id`, `store_location_id` | **PRIMARY KEY (`item_id`, `store_location_id`)** |
| `quantity_on_hand` | DECIMAL(14,3) NOT NULL DEFAULT 0 |
| `quantity_reserved` | DECIMAL(14,3) NOT NULL DEFAULT 0 — the mockup's *Reserved* column |
| `value_on_hand` | BIGINT SIGNED NOT NULL DEFAULT 0 |
| `last_movement_at` | |

`quantity_available` is **derived** (`on_hand − reserved`) and never stored — the mockup shows In Stock / Reserved / Available as three columns; only two are facts.

**Invariants**
- `I6` `quantity_on_hand >= 0`. Negative stock is **rejected**, not permitted-and-warned. A school store that can go negative produces a stock account that cannot be reconciled to a physical count.
- `I7` `quantity_reserved >= 0` and `quantity_reserved <= quantity_on_hand`.
- `I8` `quantity_on_hand = 0 ⟺ value_on_hand = 0`. CHECK-enforceable in MySQL 8 as `CHECK ((quantity_on_hand = 0) = (value_on_hand = 0))`.
- `I9` `value_on_hand >= 0`.

**Locking.** Every movement takes `SELECT … FOR UPDATE` on the affected `stock_balances` row(s). A transfer locks **both** rows, **ordered by `(item_id, store_location_id)` ascending**, to prevent the deadlock two simultaneous opposite transfers would otherwise produce. This ordering rule is mandatory and Pest-tested with concurrent transactions. Added to the `00-core.md` §11 concurrency table.

### 7.6 `stock_movements` — append-only

| Column | Notes |
|---|---|
| `id` | PK |
| `movement_type` | ENUM(`receipt`,`issue`,`transfer_out`,`transfer_in`,`adjustment_in`,`adjustment_out`,`sale`,`return_in`,`return_out`,`opening_balance`) |
| `item_id`, `store_location_id` | FK RESTRICT |
| `quantity` | DECIMAL(14,3) **SIGNED** — signed delta, positive in, negative out |
| `unit_cost` | BIGINT — **the derived cost actually applied**, snapshotted |
| `total_cost` | BIGINT SIGNED — signed like `quantity`; this is the authoritative amount |
| `balance_qty_after`, `balance_value_after` | snapshotted running balance — makes the stock ledger printable as at any date without replay |
| `moved_on` | DATE |
| `reference_type`, `reference_id` | polymorphic: `SupplierInvoice`, `StockIssue`, `StockTransfer`, `StockTakeLine`, `MerchandiseSale` |
| `journal_entry_id` | FK NULL RESTRICT |
| `store_requisition_id` | FK NULL — the requesting department |
| `analytic_value_ids` | via `JournalEntryLineAnalytic`, not stored here |
| `fiscal_year_id`, `academic_year_id` | dual calendar |
| `performed_by`, `created_at` | actor RESTRICT |
| `reversal_of_movement_id` | FK NULL, **UNIQUE** — mirrors `02-accounting.md` C9 |

**Invariants**
- `I10` `sign(quantity) = sign(total_cost)` (or both zero). CHECK.
- `I11` Movements are **never updated or deleted.** A BEFORE UPDATE/DELETE trigger rejects. Corrections are compensating movements with `reversal_of_movement_id`, mirroring the ledger's reversal rule. Reversing a reversal is forbidden.
- `I12` `unit_cost` is stored for audit and equals `abs(total_cost) / abs(quantity)` rounded — it is **descriptive**, `total_cost` is authoritative. Never recompute a total from `unit_cost`.
- `I13` Every movement other than `opening_balance` has a `journal_entry_id` once its accounting period is posted, or an explicit `posting_deferred_reason`.

Index: `(item_id, store_location_id, moved_on, id)` — the stock-ledger query; `(reference_type, reference_id)`; `(moved_on)`.

### 7.7 `stock_reservations`

`item_id`, `store_location_id`, `quantity`, `reserved_for_type/id` (a requisition, an exam, a class), `reserved_by`, `expires_on`, `status(active|released|consumed)`. A reservation **holds no cost and posts nothing** — it only moves `quantity_reserved`. Released automatically past `expires_on` by a scheduled job. `active_key` generated column supports the "one active reservation per (holder, item)" rule where applicable.

### 7.8 `store_requisitions` and `stock_issues`

`store_requisitions`: department/section requesting, requested_by, approved_by, status, lines with requested/approved/issued quantities. This is the internal-consumption analogue of a purchase requisition and is what makes the analytic split (`Section = Primary`, `Activity = Canteen`) defensible.

`stock_issues`: header (`issue_no` from the sequence allocator, `store_location_id`, `issued_to_staff_id`, `store_requisition_id` NULL, `issued_on`, `posted_entry_id`) plus lines. **One `JournalEntry` per issue header**, not per line — a 12-line issue is one piece.

### 7.9 `stock_transfers`

Header + lines, `from_location_id`, `to_location_id`, `status(draft|in_transit|received|cancelled)`. Two movements per line (`transfer_out`, `transfer_in`) at the **sending location's derived cost**.

**Accounting:** a transfer between two locations of the same legal entity, both mapping to the same `stock_account_id`, is **not a ledger event** — it posts nothing. It *is* a ledger event when the two categories map to different stock accounts (rare, but a reclassification from 33 to 31 when a consumable is redesignated merchandise). The Action checks and posts only on difference. `inventory.transfer` remains in the `02-accounting.md` event list for that case.

### 7.10 `stock_takes` (physical inventory)

| Entity | Fields |
|---|---|
| `stock_takes` | `reference` UNIQUE, `store_location_id` (or `is_full_count`), `count_date`, `status(draft|counting|counted|approved|posted|cancelled)`, `counted_by`, `verified_by`, `approved_by`, `fiscal_year_id`, `academic_year_id`, `journal_entry_id` |
| `stock_take_lines` | `stock_take_id`, `item_id`, `system_quantity` (frozen at freeze time), `system_value` (frozen), `counted_quantity`, `variance_quantity` GENERATED, `variance_value`, `reason_code`, `note` |

**`UNIQUE(stock_take_id, item_id)`.**

**Freeze semantics.** On `draft → counting`, the Action snapshots `system_quantity`/`system_value` for every item at the location. Movements during counting are **blocked** at that location (a `counting` flag checked under the balance row lock) — the alternative, allowing movements and reconciling afterwards, is where stock-take arithmetic goes wrong in every system that tries it. For a school this blocking window is minutes, not days.

**Approval is segregated:** `approved_by <> counted_by`. Variances above a configurable threshold require a second approver.

Stock-take is a **mandatory step on the `YearEndChecklist`** and runs immediately after the soft lock, before cut-off entries (`02-accounting.md` C8).

---

## 8. Inventory worked examples

Throughout: item `ITM0004` *A4 Copier Paper (Box)*, category *Office Supplies* → `purchase_account = 604`, `stock_account = 33`, `variation_account = 6033`. Opening balance at Store C: **0**.

### 8.1 Stock receipt (purchase from supplier, HT, no recoverable VAT)

Supplier invoice: 100 boxes @ 24,500 HT = 2,450,000; TVA 19.25% = 471,625 non-recoverable (prorata 0%, so it is a cost — `03-tax-procurement.md`).

The **purchase** entry is posted by `03-tax-procurement.md`'s `SupplierInvoice` Action:

| Account | Label | Debit | Credit |
|---|---|---|---|
| 604 | Achats stockés de matières et fournitures consommables | 2,921,625 | |
| 401 | Fournisseurs | | 2,921,625 |

(The non-recoverable VAT is included in the purchase, per the prorata rule. Had the school been in a recovery position, 604 would carry 2,450,000 and 445x the recoverable VAT — *445 subdivision NEEDS VERIFICATION*.)

The **entry into stock** is a separate posting, and this is the leg v1 omitted entirely, which is why the Compte de résultat showed nothing on the variation-de-stocks line:

| Account | Label | Debit | Credit |
|---|---|---|---|
| 33 | Autres approvisionnements | 2,921,625 | |
| 6033 | Variation des stocks d'autres approvisionnements | | 2,921,625 |

**603x is credited on inflow and debited on outflow.** This is the single most commonly reversed sign in the module; the posting rules carry a comment and a golden test.

Balance after: `quantity_on_hand = 100.000`, `value_on_hand = 2,921,625`. Derived unit cost = 29,216.25 — **not rounded, not stored**.

### 8.2 A second receipt at a different price — the average moves

Receipt 2: 60 boxes @ 27,000 HT + 19.25% non-recoverable = 60 × 32,197.5. Money is integer, so the supplier invoice line total is authoritative: HT 1,620,000 + TVA 311,850 = **1,931,850**.

| Account | Debit | Credit |
|---|---|---|
| 33 | 1,931,850 | |
| 6033 | | 1,931,850 |

Balance: `quantity = 160.000`, `value = 4,853,475`. Derived unit cost = **30,334.21875**. Nothing was rounded; there is no drift to accumulate.

### 8.3 Stock issue — the operation v1 could not post

25 boxes issued to the Primary section's administration on 2026-10-14.

```
issue_cost = round_half_up( 25 × 4,853,475 / 160 )
           = round_half_up( 758,355.46875 )
           = 758,355
```

| Account | Label | Debit | Credit |
|---|---|---|---|
| 6033 | Variation des stocks d'autres approvisionnements | 758,355 | |
| 33 | Autres approvisionnements | | 758,355 |

`StockMovement`: `quantity = −25.000`, `total_cost = −758,355`, `unit_cost = 30,334`, `balance_qty_after = 135.000`, `balance_value_after = 4,095,120`.

Analytic: the line carries `Section = Primary` and `Activity = Administration` via `JournalEntryLineAnalytic`, splits summing to the line (`02-accounting.md` H).

**Derived unit cost after the issue = 4,095,120 / 135 = 30,334.222…** — it moved by 0.004 FCFA because of the single rounding. It does **not** move again on the next issue by a compounding amount, because the average is recomputed from totals every time rather than carried forward as a rounded scalar. Over 10,000 issues the stock account still ties to the physical count, which is the property test.

**Emptying the bin.** Issue the remaining 135 boxes: `issue_qty == quantity_on_hand`, so `issue_cost = value_on_hand = 4,095,120` exactly (not `round(135 × 4,095,120/135)`, which happens to agree here but does not in general). Balance → `0 / 0`, satisfying `I8`.

### 8.4 Stock-take variance

Year-end count at Store C. System says 135.000 boxes, value 4,095,120. Physical count: **132**.

```
variance_quantity = 132 − 135 = −3.000
variance_value    = round_half_up( 3 × 4,095,120 / 135 ) = round_half_up(91,002.666…) = 91,003   (as a shortage, negative)
```

| Account | Label | Debit | Credit |
|---|---|---|---|
| 6033 | Variation des stocks d'autres approvisionnements | 91,003 | |
| 33 | Autres approvisionnements | | 91,003 |

Balance: `132.000 / 4,004,117`. Posting event `inventory.stocktake.variance` (`02-accounting.md`).

An **overage** posts the mirror (`Dr 33 / Cr 6033`) at the *current derived* unit cost, since there is no purchase document to price it from. Where the overage is material, the reason code forces a documented explanation before approval.

> A defensible alternative is to route shortages to a loss account (658-family) rather than 603x, especially where theft is established. `02-accounting.md` states **658/758 NEEDS VERIFICATION** for cash variance; the same uncertainty applies here. **Default: 603x** (the brief's instruction, and the treatment that keeps the stock account tied to the count). A `stock_take_lines.loss_account_id` override column exists but is **not seeded** and is unavailable in the UI until the account question is resolved.

### 8.5 Merchandise sale (canteen / bookshop / uniforms)

The 2022 Finance Law removed the exemption on private schools' commercial activities: uniform sales, textbook sales, canteen and student transport are **taxable** (`03-tax-procurement.md`). Merchandise therefore needs a sale path v1 never modelled.

Sell 1 school jumper. Category *Uniforms* → `purchase = 601`, `stock = 31`, `variation = 6031`, `sales = 701`. Stock: 200 units, value 1,840,000 (derived unit cost 9,200). Sale price HT **15,000**, TVA 19.25% = 2,887.5 → **2,888** (half-up), TTC **17,888**, cash.

**Revenue leg:**

| Account | Label | Debit | Credit |
|---|---|---|---|
| 571 | Caisse *(subdivision NEEDS VERIFICATION)* | 17,888 | |
| 701 | Ventes de marchandises | | 15,000 |
| 443x | TVA facturée *(subdivision NEEDS VERIFICATION)* | | 2,888 |

Per `00-core.md` §7.3 the **balancing line is `total − Σ(other lines)`**: the cash debit is derived, never independently rounded.

**Cost-of-sales leg** — a stock issue like any other:

| Account | Label | Debit | Credit |
|---|---|---|---|
| 6031 | Variation des stocks de marchandises | 9,200 | |
| 31 | Marchandises | | 9,200 |

Gross margin 15,000 − 9,200 = 5,800 is **derived for reporting**, never posted (same principle as `gain_or_loss`).

**If sold on credit to a student**, the debit is 4111 with `partner_type = 'student'` and the receivable joins the student's fee statement per §10.7 — it is `04-fees.md`'s `Invoice`, not a parallel debt.

`cost_of_sales_uses_variation = 1` produces the scheme above (SYSCOHADA's Compte de résultat derives cost of sales from *Achats ± Variation*). Setting it to `0` would require a 6-series cost-of-sales account that the SYSCOHADA model does not have; **the flag exists only to make the choice explicit and its only supported value is `1`.**

### 8.6 Equipment receipt that becomes a fixed asset

An item with `item_type = 'equipment'` and `asset_category_id` set, received above `AssetCategory.capitalisation_threshold`, triggers `CapitaliseAssetAction` **in the same transaction** as the goods receipt: the stock entry leg is replaced by the asset entry (`Dr 2xxx / Cr 481x`), no `StockMovement` is created, and an `Asset` row is created with `tag_number` allocated from the sequence allocator and `status = 'draft'` pending `in_service_date`.

Below the threshold, `below_threshold_behaviour` decides:
- `expense_only` — `Dr below_threshold_expense_account_id / Cr 401`. No asset, no stock.
- `expense_and_track` — the same posting, **plus** a non-depreciating `Asset` row with `depreciation_method = 'none'` and `acquisition_cost = 0` **for custody tracking only**. It carries a tag, a custodian and a location; it never appears in the fixed-asset note. It is explicitly *not* an off-balance-sheet asset.

---

## 9. Inventory screen contract (from `Inventory management.png`)

**Header KPIs:** Total Items · Total Stock Value (FCFA — `Σ value_on_hand`, as at now, **stated basis**) · Low Stock Items (`available ≤ reorder_level` and `> 0`) · Out of Stock (`on_hand = 0`) · Total Categories. Each shows a period-over-period delta against the previous term; **the comparison basis is stated on hover**, because "28,750,000 FCFA, ↑9.30% from last term" is meaningless without one.

**Filters:** Category · Item Type · Stock Status · Location · free-text search over name, `item_code`, `barcode`.

**Grid columns:** # · Item Code · Item Name · Category · Unit · Location · In Stock · **Reserved** · **Available** · Unit Price · Total Value · Status · Actions. `Available` and `Unit Price` are computed, flagged as such in the column header tooltip.

The grid is **one row per `(item, location)`**, not per item — the mockup's Location column proves this. An "all locations" roll-up is a separate toggle summing `value_on_hand`, and its Unit Price cell is blank rather than an average of averages.

**Actions:** Add New Item · Import Items (CSV, dry-run preview, per-row error report, idempotent by `item_code`) · Inventory Reports · Stock Adjustment · Transfer Items · Generate Report.

**Panels:** Stock Status Overview donut (Available / Low Stock / Out of Stock / Reserved — note these four are **not** mutually exclusive as drawn; the spec defines them as a priority ladder: out-of-stock → low → reserved-portion → available) · Recent Stock Movements · Top Low Stock Items.

**Performance:** the grid is server-paginated, `preventLazyLoading` applies, and the KPI row is a single aggregate query against `stock_balances`, never a per-item loop (`00-core.md` §6.2 rule 8).

---

# PART C — LIBRARY

## 10. Entities

v1 devoted one sentence to the library. A 5,248-volume collection (the mockup's figure) with 1,156 members and 87 overdue items is an operational subsystem with money in it.

### 10.1 `books` (the bibliographic record)

`id` · `isbn` VARCHAR(20) `as_cs` **UNIQUE NULL** · `title` · `subtitle` · `author` · `co_authors` · `publisher` · `publication_year` · `edition` · `language` · `book_category_id` FK RESTRICT · `dewey_or_call_number` · `pages` · `summary` · `cover_path` · `replacement_cost` BIGINT · `is_reference_only` BOOLEAN (dictionaries and atlases never circulate) · `is_archived`.

`book_categories`: managed taxonomy (`code` UNIQUE, `name`, `name_fr`, `parent_id` RESTRICT). The mockup's right rail — Mathematics 850, Science 1,120, Language 1,035… — counts **copies**, not titles; the spec states which.

### 10.2 `book_copies` (the physical thing)

`id` · `book_id` FK RESTRICT · `accession_no` VARCHAR(30) `as_cs` **UNIQUE** · `barcode` VARCHAR(64) `as_cs` **UNIQUE** · `shelf_location_id` FK RESTRICT · `acquisition_id` FK NULL · `acquired_on` · `acquisition_cost` BIGINT · `condition` ENUM(`new`,`good`,`fair`,`poor`) · `status` ENUM(`available`,`issued`,`reserved`,`lost`,`damaged`,`withdrawn`,`in_repair`) · `withdrawn_on`, `withdrawal_reason`.

`shelf_locations`: `code` UNIQUE (`Shelf A1`), `name`, `section`, `capacity`.

**Copies are the unit of circulation.** The mockup's grid shows *Copies 12 / Available 8* per title — `Available` is `COUNT(copies WHERE status='available')`, derived, never a stored counter. A stored counter is exactly how libraries end up issuing a book that is not on the shelf.

### 10.3 `library_members`

| Column | Notes |
|---|---|
| `id` | PK |
| `member_no` | VARCHAR(20) `as_cs` **UNIQUE** — printed on the Library Card |
| `member_type` | ENUM(`student`,`staff`,`external`) |
| `student_id` | FK NULL RESTRICT |
| `staff_member_id` | FK NULL RESTRICT |
| `enrollment_id` | FK NULL RESTRICT — see below |
| `academic_year_id` | FK RESTRICT |
| `membership_class_id` | FK — borrowing limits |
| `status` | ENUM(`active`,`suspended`,`expired`,`closed`) |
| `joined_on`, `expires_on`, `suspended_reason` | |
| `card_issued_at`, `card_printed_count` | |

**Keying decision.** Per `07-students.md` C3, operational allocations key on **enrollment**. Library membership does the same — `enrollment_id` scopes the membership to a year so last year's membership does not stay active forever — **but the fine receivable and the borrowing history key on `student_id`**, because a debt and a borrowing record survive the year. Both columns are present and both are populated; the invariant is `student_id = enrollment.student_id`.

`CHECK`: exactly one of `student_id` / `staff_member_id` is non-null, unless `member_type = 'external'` in which case both are null and `external_name`/`external_contact` are required.

`membership_classes`: `code`, `name`, `max_concurrent_issues`, `loan_days`, `max_renewals`, `renewal_days`, `fine_per_day` BIGINT, `fine_grace_days`, `max_reservations`, `can_borrow_reference` BOOLEAN.

### 10.4 `library_issues` — the constraint that matters

| Column | Notes |
|---|---|
| `id` | PK |
| `issue_no` | sequence, **UNIQUE** |
| `book_copy_id` | FK RESTRICT |
| `library_member_id` | FK RESTRICT |
| `issued_on` DATE, `due_on` DATE | |
| `issued_by` FK RESTRICT | |
| `returned_on` DATE NULL, `received_by` FK NULL | |
| `renewal_count` SMALLINT DEFAULT 0 | |
| `status` | ENUM(`open`,`returned`,`overdue`,`lost`,`written_off`) |
| `return_condition` | ENUM(`good`,`damaged`,`lost`) NULL |
| `open_copy_key` | **BIGINT GENERATED ALWAYS AS (CASE WHEN status IN ('open','overdue') THEN book_copy_id END) STORED** |

**`UNIQUE KEY uq_open_issue (open_copy_key)`** — the `00-core.md` §10.1 pattern. **The last copy cannot be issued twice**, enforced by the database, not by a check-then-act read. This is `00-core.md` §10.2's "both directions": one open issue per copy, *and* `max_concurrent_issues` per member enforced under `FOR UPDATE` on the member row.

**Locking:** `SELECT … FOR UPDATE` on the `book_copies` row (`00-core.md` §11), then the member row, **in that fixed order**.

**`status = 'overdue'` is a distinct persisted state**, not a computed `due_on < today` — the same decision `04-fees.md` makes for invoices. A nightly job promotes `open → overdue`, which is also what makes "87 Overdue Books, ↑12 this week" on the mockup a queryable fact and what triggers fine accrual deterministically.

`library_renewals`: `issue_id`, `renewed_on`, `previous_due_on`, `new_due_on`, `renewed_by`. Append-only. Renewal is **refused** when the copy has an active reservation or the member has any unpaid fine above a configurable threshold.

`library_reservations`: `book_id` (title-level, not copy-level — a member reserves *a* copy), `library_member_id`, `reserved_on`, `expires_on`, `status(waiting|ready|fulfilled|expired|cancelled)`, `notified_at`, queue `position`. When a copy is returned and a reservation is waiting, the copy goes to `reserved` and the queue head is notified through `Communication` (degrading to an outbox when offline, per `00-core.md` §3).

### 10.5 `library_fines`

| Column | Notes |
|---|---|
| `id` | PK |
| `fine_no` | sequence UNIQUE |
| `library_issue_id` | FK RESTRICT NULL (a fine can also be levied for damage without an overdue) |
| `library_member_id` | FK RESTRICT |
| `fine_type` | ENUM(`overdue`,`damage`,`loss`,`other`) |
| `assessed_on` | DATE |
| `days_overdue` | SMALLINT NULL |
| `amount` | BIGINT, CHECK ≥ 0 |
| `waived_amount` | BIGINT DEFAULT 0 |
| `waived_by`, `waived_reason` | |
| `status` | ENUM(`assessed`,`invoiced`,`paid`,`waived`,`written_off`) |
| `invoice_id` | FK NULL — students, §10.7 |
| `payroll_deduction_id` | FK NULL — staff, §10.7 |
| `journal_entry_id` | FK NULL |
| `settlement_route` | ENUM(`student_receivable`,`staff_payroll_deduction`,`cash_immediate`) |

**Overdue fine arithmetic.** `amount = max(0, days_overdue − grace_days) × membership_class.fine_per_day`, where `days_overdue = business_date() − due_on` counted in **calendar days**, excluding days on which the library was closed per the school calendar (`is_teaching_day = false` and library-closed dates). Accrual runs nightly and is **idempotent**: it recomputes the entitlement and adjusts, exactly like §4.3, rather than adding a day each night — so a job that runs twice, or is missed for a week, still produces the correct figure.

A fine is capped at `book.replacement_cost` (a 200 FCFA/day fine on a 6,000 FCFA book must not reach 40,000). Cap is per `membership_class.fine_cap_policy`.

**Loss.** `status = 'lost'` on the issue sets the copy to `lost`, levies a fine of `replacement_cost` (+ optional processing fee), and — if the collection is capitalised (§10.8) — triggers the write-off of that copy's carrying amount.

### 10.6 Library fine accounting — two genuinely different transactions

**Student fine, 2,000 FCFA levied 2026-11-30.** This is a **receivable**, recognised when levied, not when collected. v1's event list had only `library.fine.collected`, so the receivable was never recognised at all — `02-accounting.md` adds `library.fine.levied`.

| Account | Label | Debit | Credit |
|---|---|---|---|
| 4111 | Clients — *partner_type = student, partner_id = the student* | 2,000 | |
| 707x | Produits accessoires *(707 vs 758 and the subdivision: **NEEDS VERIFICATION** — `02-accounting.md` owns the choice)* | | 2,000 |

Collection is then an ordinary `04-fees.md` payment allocation; no separate library cash flow exists.

**Staff fine, 2,000 FCFA.** Not a receivable — a **payroll deduction** (`05-hr-payroll.md`). Nothing posts at levy; the fine is queued as a payroll input and posts inside the payroll entry:

| Account | Label | Debit | Credit |
|---|---|---|---|
| 42x | Personnel — rémunérations dues *(subdivision **NEEDS VERIFICATION**)* | 2,000 | |
| 707x / 758x | *(as above, **NEEDS VERIFICATION**)* | | 2,000 |

The deduction is subject to `05-hr-payroll.md`'s **deduction cap** (the legally assignable portion of salary). A fine that would breach the cap carries forward to the next month rather than being deducted unlawfully.

`settlement_route` is derived from `member_type` at levy and **snapshotted**, so a member who converts from student to staff (a former pupil hired as a lab assistant) does not have historic fines silently reroute.

**Waivers** are contra-revenue against the same income account, require a permission and a reason, and the waiver approver may not be the person who levied the fine.

### 10.7 The second-debt-stream problem (raised in `04-fees.md` H, resolved here)

**Decision: there is exactly one student debt stream.** A student library fine, once assessed, is issued as an `InvoiceLine` on the student's account via `04-fees.md`'s invoicing Action, against a `FeeItem` of `collection_basis = own_revenue` mapped to the library income account. Consequences:

- The report card's fee-balance block, the defaulters report, the aged-receivables listing and the Fee Statement all show the **total** owed. No parallel ledger.
- `04-fees.md`'s balance formula, allocation locking and void cascade apply unchanged.
- The library screen shows the fine's status by reading through `invoice_id`; it does not maintain its own paid/unpaid flag. `library_fines.status` transitions are driven by events from Fees.
- **Staff fines are the exception and stay out of Fees entirely** — they are a payroll matter.

`library_fines` therefore remains as the *assessment* record (why, how many days, which copy) while the *debt* lives in Fees. This is the same split as `05-hr-payroll.md`'s snapshot-vs-ledger separation.

### 10.8 Is the collection capitalised?

**Decision: configurable, defaulting to expensed, with a stated threshold.**

A 5,248-volume collection at even 8,000 FCFA/volume is ~42M FCFA — unquestionably material, which argues for capitalisation as a *fonds documentaire* in class 2. Against it: individual volumes are far below any sensible `capitalisation_threshold`, they are consumed and lost continuously, and per-copy depreciation schedules for 5,248 rows is an operational burden with no decision value.

Therefore `SchoolProfile.library_capitalisation_policy`:

| Value | Treatment |
|---|---|
| `expensed` (default) | acquisitions expensed on purchase (604 or a dedicated documentation account); `BookCopy.acquisition_cost` is retained for **insurance and replacement-cost purposes only** and posts nothing |
| `capitalised` | each `BookAcquisition` batch creates **one** `Asset` per acquisition batch (not per copy), in a *Fonds documentaire* asset category, depreciated over the category's useful life; losses and withdrawals reduce it via a partial disposal |

> **NEEDS VERIFICATION:** the SYSCOHADA account for a *fonds documentaire* / bibliothèque under class 2, and whether OHADA practice treats a school library as a corporeal fixed asset or an expense. The `capitalised` option is **not selectable** until the account is confirmed. The default (`expensed`) posts to accounts already verified.

`book_acquisitions`: `reference` UNIQUE, `supplier_id` NULL, `supplier_invoice_id` NULL, `acquired_on`, `source` (`purchase`|`donation`|`transfer`), `total_cost`, `copy_count`, `journal_entry_id`, `asset_id` NULL. Donated books follow §6.3 only under the `capitalised` policy; under `expensed` a donation posts nothing and is recorded as a memorandum with a donor acknowledgement letter.

### 10.9 Library screen contract (from `libray management.png`)

**KPIs:** Total Books (copies) · Books Available · Books Issued · Total Members · Overdue Books. Each definition is stated in the tooltip; "Total Books 5,248" is copies, "Book Categories · Mathematics 850" is copies, and the Book List grid is **titles** — three different populations on one screen, which is precisely why each must be labelled.

**Tabs:** Book List · Issued Books · Returned Books · Overdue Books · Members.
**Book List grid:** # · Book Title · Author · Category · ISBN · Location · Copies · Available · Status · Actions (view / edit / overflow).
**Filters:** search by title/author/ISBN · Category · Status · Location.
**Quick actions:** Add New Book · Issue Book · Return Book · New Member.
**Right rail:** Book Categories with counts · Recent Activities (an event feed off `AuditLog`, not a separate table) · **Library Rules** — a `SchoolProfile`-scoped editable ordered list, rendered here and on the Library Card.

**Additional deliverables named in the brief:** Top Borrowers (issues per member over a stated window) · Library Statistics (Daily Visits — requires a `library_visits` turnstile table: `member_id` NULL, `visited_on`, `recorded_by`; Books Added; Lost Books; Fine Collected — sourced from Fees allocations against library fee items, never from `library_fines.status`) · **Library Card** printable (member_no, photo, barcode, validity, rules extract), logged in `DocumentPrintLog` with duplicate watermarking per `00-core.md` §14.

**Issue flow, keyboard-first:** scan member barcode → scan copy barcode → the Action validates (member active, under limit, no blocking fine, copy available, not reference-only) → commits → prints a slip. One request per scan pair; Alpine holds the local state.

---

## 11. Consolidated `NEEDS VERIFICATION` register

Every item below **blocks the dependent feature**: the column ships `NULL`, the seeder inserts nothing, and the Action refuses to run with a message naming the item. Per `00-core.md` §16, *a wrong seeded value is more dangerous than an empty field.*

| # | Item | Blocks | Owner |
|---|---|---|---|
| V1 | `prorata_convention` — whether SYSCOHADA prescribes daily or monthly prorata, and the accepted commercial-year day basis | **All depreciation** | Accountant, Phase 9 gate |
| V2 | Class-28 subdivisions mirroring each class-2 account (284x, 281x…) | Depreciation posting | `02-accounting.md` |
| V3 | 681x subdivision for *dotations aux amortissements* | Depreciation posting | `02-accounting.md` |
| V4 | Class-2 subdivisions: 245x matériel de transport, 231/23x buildings, **249 assets under construction** | Capitalisation, commissioning | `02-accounting.md` |
| V5 | **845** for the investment-subsidy release (865 is confirmed **wrong** — it does not appear in the revised compte 86 listing) | Donated-asset release | `02-accounting.md` |
| V6 | Class-14 subdivision for subventions d'investissement | Donation posting | `02-accounting.md` |
| V7 | **151** amortissements dérogatoires and its class-85 counterpart | Derogatory depreciation (feature disabled) | `02-accounting.md` |
| V8 | **106** écart de réévaluation | Revaluation (feature disabled) | `02-accounting.md` |
| V9 | Class-29 provisions and the matching impairment expense account | Impairment (feature disabled) | `02-accounting.md` |
| V10 | CGI maximum tax-deductible depreciation rates by asset class | Fiscal schedule, DSF réintégrations | `03-tax-procurement.md` |
| V11 | 445x TVA récupérable and 443x TVA facturée subdivisions | Merchandise sale, recoverable-VAT receipts | `03-tax-procurement.md` |
| V12 | 5-digit subdivisions of 31/32/33 and 601/602/604/6031/6032/6033 | Nothing — 4-digit codes are verified and sufficient; subdivisions are a school extension | school |
| V13 | Treasury subdivision 571x Caisse | Cash sales | `02-accounting.md` |
| V14 | **707 vs 758** for library fine income, and the subdivision | Fine levy posting | `02-accounting.md` |
| V15 | 42x subdivision for staff remuneration payable (fine deduction) | Staff fine posting | `05-hr-payroll.md` |
| V16 | Whether stock shortages may/should route to a loss account (658-family) instead of 603x | The `loss_account_id` override (unavailable) | `02-accounting.md` |
| V17 | SYSCOHADA account for a *fonds documentaire*; whether a school library is capitalised in OHADA practice | The `capitalised` library policy (unavailable) | `02-accounting.md` |

**Verified and used without flag** (stated as verified by `02-accounting.md`): 2442 *Matériel informatique* (not 2441, which is *Matériel de bureau*); 81 / 811 / 812 / 816 *Valeurs comptables des cessions*; 82 / 821 / 822 / 826 *Produits des cessions*; 485 *Créances sur cessions d'immobilisations*; 601 / 602 / 604; 6031 / 6032 / 6033; 31 / 32 / 33; 701; 401; 481 *Fournisseurs d'investissements*; 4111 *Clients*; 14 *Subventions d'investissement* (class level).

---

## 12. Posting events emitted by this module

Registered in `02-accounting.md`'s `PostingRule.event` enumeration. All dispatch after commit.

`asset.acquired` · `asset.commissioned` · `asset.depreciated` · `asset.disposed` · `asset.impaired` · `asset.revalued` · `asset.subsidy.released` · `asset.subsidy.clawed_back` · `asset.written_off` · `inventory.received` · `inventory.issued` · `inventory.transfer` · `inventory.stocktake.variance` · `inventory.sale` · `inventory.sale.returned` · `library.fine.levied` · `library.fine.waived` · `library.fine.written_off` · `library.book.lost` · `library.acquisition.recorded`

---

## 13. Acceptance criteria for Phase 9

1. **Depreciation idempotency.** Run a month twice; assert the second run creates zero journal lines and zero schedule rows, and that `UNIQUE(fiscal_year_id, period_month)` rejects a concurrent duplicate.
2. **Depreciation completeness.** Property test over 1..600-month lives and 1..100,000,000 FCFA costs: `Σ charges = cost − residual`, exactly, with no period exceeding the cap.
3. **Catch-up.** Capitalise an asset back-dated three periods after runs have posted; assert one `is_catch_up` row for the full arrears in the current open period, with `value_date` preserved.
4. **Disposal is gross.** Assert the disposal entry contains an 81-family debit and an 82-family credit as separate lines, and that **no line's amount equals `gain_or_loss`**.
5. **Subsidy neutrality.** Over the full life of a fully-funded donated asset, assert `Σ 681x charges = Σ 845 releases`, franc for franc.
6. **Stock ties to the ledger.** After 10,000 randomised receipts, issues, transfers and stock-takes, assert `Σ stock_balances.value_on_hand` = the balance of every `stock_account_id` in the GL, exactly.
7. **No negative stock.** Concurrent issues of the last unit: one succeeds, one fails with a domain error, `quantity_on_hand` never below zero.
8. **Empty-bin rule.** Issue the entire quantity; assert `value_on_hand = 0`.
9. **Transfer deadlock.** Two opposite transfers of the same item between the same two locations, concurrently; assert both complete (the ordered-lock rule) and no deadlock is thrown.
10. **Library double-issue.** Two concurrent issues of the last available copy: exactly one succeeds, by database constraint, with the second failing on `uq_open_issue`.
11. **Fine idempotency.** Run the overdue accrual job five times in one day; assert the fine amount is unchanged.
12. **Configuration gate.** With `prorata_convention` NULL, assert `RunDepreciation` refuses and names the missing configuration rather than defaulting.
