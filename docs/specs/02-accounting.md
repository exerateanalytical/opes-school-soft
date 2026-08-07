# 02 — Accounting (SYSCOHADA double-entry)

**Version:** 2.0
**Date:** 2026-08-07
**Status:** Draft for review
**Owns:** SYSCOHADA chart of accounts, journals, the double-entry ledger, fiscal years and accounting periods, posting rules, auxiliary (tiers) accounting, lettering, analytic accounting, bank reconciliation, statutory books, year-end close, budget, treasury, financial statements, the Finance Dashboard and expense capture.
**Binding parent:** `00-core.md`. Where this document appears to contradict it, `00-core.md` wins and this document is defective.

**Cross-references — content owned elsewhere, not duplicated here:**

| Topic | Owner |
|---|---|
| TVA, prorata de déduction, withholding (AIR/précompte), tax declarations, DSF filing, `TaxCode`, `Supplier`, `PurchaseOrder`, `SupplierInvoice`, `SupplierPayment`, 401/481 payables | `03-tax-procurement.md` |
| Invoices, payments, allocations, credit notes, refunds, write-offs, doubtful-debt provisioning policy, third-party funds, revenue recognition schedules | `04-fees.md` |
| Payroll computation, CNPS/IRPP, payslips, `PayrollRun` lifecycle | `05-hr-payroll.md` |
| Asset register, depreciation computation, impairment, investment subsidies, inventory valuation, library | `06-assets-stores.md` |
| Backup, restore, scheduled-job supervision, health page | `08-operations.md` |
| Printable document templates and print log | `10-documents.md` |

This document specifies **how every one of the above lands in the ledger**. The originating modules own their entities; Accounting owns the posting.

---

## 0. Regulatory frame and what it obliges

The applicable framework is the **SYSCOHADA révisé** as enacted by the **Acte uniforme relatif au droit comptable et à l'information financière (AUDCIF)**, adopted 26 January 2017, applicable to accounts for exercices opened from 1 January 2018. Cameroon is an OHADA member state. Tax obligations derive from the **Code Général des Impôts (CGI)** and are owned by `03-tax-procurement.md`.

Five AUDCIF provisions drive schema, not just reporting. They are named here because a later reader will otherwise treat them as advice:

| Provision | Obligation | Where implemented |
|---|---|---|
| **Art. 17** | Entries are recorded operation by operation, in chronological order, with a supporting document (*pièce justificative*) referenced and datable. | §4 `JournalEntry`, §4.4 attachments |
| **Art. 19** | Four mandatory books: **livre-journal**, **grand livre**, **balance générale**, **livre d'inventaire**. Continuous, gapless numbering. | §14 `StatutoryBook`; `00-core` §12 gapless `piece_no` |
| **Art. 22** | Entries must be rendered **definitive by a clôture informatique at least quarterly**. An operation whose value date falls in an already-closed period is recorded **on the first day of the first open period**, retaining its original value date. | §5 `AccountingPeriod`, §5.4 forward-posting |
| **Art. 24** | Accounting records and supporting documents are retained **10 years**. | §15 retention observer |
| **Exercice** | The exercice coincides with the **calendar year, 1 January – 31 December**. An irregular first exercice is permitted (a school created in March closes its first exercice on 31 December of that year). | §6 `FiscalYear` validation |

> **Product consequence — state this in the setup wizard, hard.** A Cameroonian school's academic year runs roughly September–July. Its **fiscal year does not and cannot follow it.** A school that configures a Sept–Aug "fiscal year" produces accounts that are legally void and a DSF that cannot be filed. `00-core` §8 makes `AcademicYear` contiguous and gapless precisely so that August has an academic year; the ledger keeps its own calendar-year `FiscalYear` alongside. Every financial entity carries **both**. See §7.

**Système Normal vs Système Minimal de Trésorerie (SMT).** SMT is a cash-basis regime for the smallest entities. This product implements the **Système Normal** only. A school on SMT is out of scope; the setup wizard records the regime and refuses to proceed on SMT with a message directing the school to its accountant. *(The turnover thresholds separating the regimes are `NEEDS VERIFICATION` against the current AUDCIF text and CGI — do not seed a number.)*

---

## 1. The three verified account-code corrections

v1 shipped three demonstrably wrong SYSCOHADA codes. They are recorded here as regression tests, not prose. **Any pull request reintroducing them fails CI** — see §21.4.

### 1.1 IT equipment is 2442, not 2441

| | |
|---|---|
| **2441** | *Matériel de bureau* — desks, chairs, filing cabinets, office furniture-adjacent equipment |
| **2442** | *Matériel informatique* — computers, servers, printers, networking hardware, the school's own mini-PC |

v1 mapped the ICT lab to 2441. Every computer purchase landed in office equipment, and the DSF fixed-asset note was wrong on two lines simultaneously (2441 overstated, 2442 zero). `AssetCategory` seeds for ICT point at **2442**.

### 1.2 The 4th digit of 706x encodes geography, not service type

**7061** is *Services vendus dans la Région* — **the 4th digit of compte 706 encodes the geographical destination of the sale**, exactly as it does throughout compte 70. It does not mean "tuition". v1 used 7061 as "tuition fees", which is a category error, not a near miss: it makes the geographic analysis of turnover meaningless and it puts tuition on the same line as any other regional service.

Correct treatment:

- **Tuition and core teaching services** extend compte **706** at **5+ digits** under the appropriate geographic 4th digit — e.g. `70611` for tuition sold in the Region. The 4-digit parent stays with its statutory label.
- **Boarding, transport, canteen, uniforms, ancillary income** belong to compte **707 — *Produits accessoires***, whose verified subdivisions include **7073 *Locations***, **7077**, **7078**. Seeding of specific 707x sub-codes for boarding vs transport is **`NEEDS VERIFICATION`** against the official plan; the school's accountant assigns them in the setup wizard (blocking gate 6, `00-core` §16).

> Note the interaction with `04-fees.md` C5: **APEE contributions and national-exam registration fees are not class 7 at all.** They are collected as an agent, credit a class 47 liability, and never touch revenue. Putting them in 706/707 overstates turnover, which inflates the minimum-tax and acompte-d'IS base — the school pays tax on money that is not its own.

### 1.3 Mobile money is compte 55, not a bank account

Mobile money (MTN MoMo, Orange Money) is **not a bank account and must not be seeded as 5210**. The correct home is:

| Code | Label |
|---|---|
| **55** | *Instruments de monnaie électronique* |
| **552** | *Téléphone Portable* |

Operator commission is a financial service charge posted to **6317** *(frais sur effets et autres frais bancaires / commissions — the school's accountant confirms the exact 631x subdivision at setup)*.

The v1 worked example posted the full 350 000 FCFA received to treasury and ignored the operator commission entirely, so the MoMo account could never reconcile against the operator statement. The corrected worked example is §11.3.

### 1.4 System accounts are not renameable

v1 said seeded accounts are "renameable". That destroys the codification, breaks the DSF mapping, and makes the grand livre unreadable to any auditor who knows the plan.

**Rule.** For any `ChartOfAccount` row with `is_system = true` and `LENGTH(code) BETWEEN 1 AND 4`:

- `code` is **immutable** — no Action exposes an update path; enforced by a BEFORE UPDATE trigger.
- `name` and `name_fr` are **immutable** — the statutory label.
- The school may set **`display_alias`**, shown in UI lists and on management reports, **never** on the grand livre, the balance générale, the livre d'inventaire or the DSF, which always render the statutory label.
- Schools extend the plan freely at **5+ digits** under a system parent. A 5+-digit account is `is_system = false`, fully editable, and inherits its parent's `dsf_line_code` unless overridden.

---

## 2. Chart of accounts

### 2.1 `ChartOfAccount`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(20) | `utf8mb4_0900_as_cs` per `00-core` §4. **UNIQUE.** Digits only |
| `parent_id` | BIGINT NULL | FK self, **ON DELETE RESTRICT** |
| `account_class` | TINYINT | 1–9, generated: `CAST(LEFT(code,1) AS UNSIGNED)`, STORED |
| `depth` | TINYINT | generated: `LENGTH(code)`, STORED |
| `name` | VARCHAR(180) | statutory label (FR is the statutory language of the plan) |
| `name_fr` | VARCHAR(180) | |
| `name_en` | VARCHAR(180) NULL | working translation, never printed on statutory books |
| `display_alias` | VARCHAR(180) NULL | school-chosen; UI + management reports only |
| `type` | ENUM | `asset, liability, equity, revenue, expense, offbalance, analytic` |
| `normal_balance` | ENUM | `debit, credit` |
| `is_postable` | BOOLEAN | false for any account with children. Enforced by trigger (§2.4) |
| `is_system` | BOOLEAN | true for seeded 1–4 digit accounts |
| `is_collective` | BOOLEAN | see §8 |
| `requires_partner` | BOOLEAN | see §8 |
| `allowed_partner_types` | JSON NULL | subset of the partner-type enum; NULL = any |
| `requires_analytic` | BOOLEAN | see §12 |
| `is_lettrable` | BOOLEAN | see §10 |
| `is_reconcilable` | BOOLEAN | treasury accounts subject to bank reconciliation (§13) |
| `dsf_line_code` | VARCHAR(20) NULL | the DSF/États financiers line this account rolls into |
| `dsf_statement` | ENUM NULL | `bilan_actif, bilan_passif, resultat, flux, note` |
| `default_tax_code_id` | BIGINT NULL | FK → `TaxCode` (`03-tax-procurement`), **ON DELETE RESTRICT** |
| `budget_control` | ENUM | `none, warn, block` — §16 |
| `currency` | CHAR(3) | always `XAF`. See §19 |
| `is_archived` | BOOLEAN | archive flag, never `SoftDeletes` (`00-core` §10.5) |
| `opened_at` / `archived_at` | DATE NULL | |
| `notes` | TEXT NULL | |

**Keys and constraints**

```sql
UNIQUE KEY uq_coa_code (code),
KEY ix_coa_parent (parent_id),
KEY ix_coa_class_depth (account_class, depth),
KEY ix_coa_dsf (dsf_statement, dsf_line_code),
CONSTRAINT ck_coa_code_numeric CHECK (code REGEXP '^[0-9]{1,20}$'),
CONSTRAINT ck_coa_class CHECK (account_class BETWEEN 1 AND 9),
CONSTRAINT ck_coa_partner CHECK (NOT (requires_partner = 1 AND is_collective = 0) OR is_collective = 1),
CONSTRAINT ck_coa_currency CHECK (currency = 'XAF')
```

`ON DELETE` for `ChartOfAccount` itself: **RESTRICT everywhere** (`00-core` §10.5). An account is archived, never deleted. Archiving is refused if the account has a non-zero balance in any unclosed fiscal year, or any line in the current or prior fiscal year.

### 2.2 Hierarchy invariants

| # | Invariant | Enforcement |
|---|---|---|
| CoA-1 | `parent_id` is NULL iff `depth = 1` | BEFORE INSERT/UPDATE trigger |
| CoA-2 | `parent.code` is a strict prefix of `code`, and `parent.depth = depth − 1` | trigger |
| CoA-3 | No cycles | guaranteed by CoA-2 (prefix ordering is a strict partial order) |
| CoA-4 | An account with ≥1 non-archived child has `is_postable = false` | trigger on child insert; nightly job re-asserts |
| CoA-5 | `is_system = true` ⇒ `code`, `name`, `name_fr` immutable | BEFORE UPDATE trigger raising SIGNAL |
| CoA-6 | `type` and `normal_balance` are consistent with `account_class` per the seed table | validation Action at save; nightly job |
| CoA-7 | Every postable account reachable from a class 1–8 root has a non-null `dsf_line_code` before a fiscal year may be closed | `YearEndChecklist` step (§17) |

### 2.3 What ships seeded, and what does not

`00-core` §16 rule: **a wrong seeded value is more dangerous than an empty field.** Applied here:

**Seeded** — the SYSCOHADA révisé class and 2-digit account skeleton, plus the 3–4 digit accounts named explicitly in this document and in the sibling briefs, each carrying a `citation` string in the seeder pointing at the AUDCIF plan. Nothing else.

**Not seeded — `NEEDS VERIFICATION`, blank, and the dependent feature refuses to run until configured** (blocking gate 6, `00-core` §16 — "Accountant: TVA treatment, withholding rates, revenue recognition basis, Système Normal vs SMT, DSF mapping"):

| Item | Why not seeded |
|---|---|
| The 707x subdivision for boarding vs transport vs canteen | Only 7073 *Locations*, 7077, 7078 are verified; the mapping of school ancillary services to them is the accountant's call |
| The exact 5-digit tuition extensions under 706 | Depend on the school's geographic scope and the accountant's preference |
| The 631x subdivision for mobile-money commission | 6317 is the target per the brief; the precise label is to be confirmed |
| **658 (cash shortage) / 758 (cash overage)** | Flagged `NEEDS VERIFICATION` in the brief. Class 65/75 *Autres charges / produits* is the right neighbourhood; the exact subdivision is not confirmed. §11.5 |
| **491 (provision for doubtful receivables)** | Flagged `NEEDS VERIFICATION` in `04-fees.md` C8 |
| **845 (quote-part de subvention virée au résultat)** | Flagged `NEEDS VERIFICATION` in `06-assets-stores.md`. The commonly-cited "865" is **wrong** — it does not appear in the revised compte 86 listing. Do not seed 865 under any circumstances |
| **151 (amortissements dérogatoires)** | `NEEDS VERIFICATION` per `06-assets-stores.md` |
| **106 (écart de réévaluation)** | `NEEDS VERIFICATION` per `06-assets-stores.md` |
| **428x (provision for leave)** | `NEEDS VERIFICATION` per `05-hr-payroll.md` |
| The full DSF line mapping | The DSF form itself is the authority; mapping is done once with the accountant |

**Verified and seeded**, cited to the AUDCIF plan and to the sibling briefs that verified them:

| Code | Label | Used by |
|---|---|---|
| **14** | Subventions d'investissement | donated/grant assets — `06` |
| **2442** | Matériel informatique | §1.1 |
| **2441** | Matériel de bureau | §1.1 |
| **249** | Avances et acomptes versés sur immobilisations *(assets under construction — see `06`)* | `06` |
| **28x** | Amortissements | §11.6 |
| **29x** | Dépréciations des immobilisations | `06` |
| **31 / 32 / 33** | Marchandises / Matières premières / Autres approvisionnements | §11.4 |
| **401** | Fournisseurs, dettes en compte | `03` |
| **481** | Fournisseurs d'investissements (4812, 4817, 4818) | `03` |
| **411** | Clients — collective (**4111** Clients, **4112** Groupe, **4114** État) | §8 |
| **4161 / 4162** | Créances litigieuses / Créances douteuses | `04` |
| **4181** | Clients, factures à établir | `04` |
| **4191** | Clients, avances et acomptes reçus | `04` |
| **4198** | Rabais, remises, ristournes et autres avoirs à accorder | `04` |
| **47** | Débiteurs et créditeurs divers *(agent/third-party funds)* | `04` |
| **476 / 477** | Charges constatées d'avance / Produits constatés d'avance | §17.4, `04` |
| **485** | Créances sur cessions d'immobilisations | §11.6 |
| **52** | Banques | §13 |
| **55 / 552** | Instruments de monnaie électronique / Téléphone Portable | §1.3 |
| **57** | Caisse | §11.5 |
| **601 / 602 / 604** | Achats de marchandises / matières premières / matières consommables | §11.4 |
| **6031 / 6032 / 6033** | Variations des stocks (marchandises / matières premières / autres approvisionnements) | §11.4 |
| **6317** | Frais bancaires / commissions *(mobile-money fees)* | §1.3, §11.3 |
| **701** | Ventes de marchandises | `06` |
| **706** | Services vendus *(4th digit = geography; tuition at 5+ digits)* | §1.2 |
| **707** | Produits accessoires (**7073** Locations, 7077, 7078) | §1.2 |
| **81 / 811 / 812 / 816** | Valeurs comptables des cessions d'immobilisations | §11.6 |
| **82 / 821 / 822 / 826** | Produits des cessions d'immobilisations | §11.6 |
| **89 / 891 / 892 / 895 / 899** | Impôts sur le résultat | §17.6 |
| **11 / 12 / 13** | Report à nouveau / Résultat net / Résultat en instance d'affectation | §18 |

### 2.4 Postability

A journal line may only reference an account where `is_postable = true AND is_archived = false`. Enforced in the Action **and** by a BEFORE INSERT trigger on `JournalEntryLine`, because posting rules and imports both write lines.

---

## 3. `Journal`

v1 treated journals as a string. They are an entity, and the livre-journal is a statutory book keyed on them.

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(10) | `as_cs`. **UNIQUE** |
| `name`, `name_fr` | VARCHAR(120) | |
| `type` | ENUM | `sales, purchases, cash, bank, mobile_money, payroll, operations_diverses, opening, closing` |
| `default_debit_account_id` | BIGINT NULL | FK → CoA, RESTRICT |
| `default_credit_account_id` | BIGINT NULL | FK → CoA, RESTRICT |
| `treasury_account_id` | BIGINT NULL | required when `type IN (cash, bank, mobile_money)` |
| `requires_maker_checker` | BOOLEAN | §20 |
| `piece_no_format` | VARCHAR(40) | e.g. `{journal}/{fy}/{seq:6}` |
| `is_active` | BOOLEAN | |
| `is_archived` | BOOLEAN | RESTRICT on delete (`00-core` §10.5) |

**Constraint.** `CHECK (type NOT IN ('cash','bank','mobile_money') OR treasury_account_id IS NOT NULL)` — expressed as a validation Action plus a nightly integrity check, since MySQL CHECK cannot dereference the FK.

Seeded journals: `VE` ventes, `AC` achats, `CA` caisse, `BQ` banque, `MM` mobile money, `PA` paie, `OD` opérations diverses, `AN` à-nouveaux, `CL` clôture. `AN` and `CL` are `is_system` and only writable by the year-end Actions (§18) and the opening-balance import (§18.4).

---

## 4. The ledger

### 4.1 `JournalEntry`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `journal_id` | BIGINT | FK → `Journal`, **RESTRICT** |
| `piece_no` | VARCHAR(40) NULL | **gapless**, allocated only at posting (`00-core` §12). NULL while `draft` |
| `date` | DATE | the **accounting date** — the date the entry sits at in the books |
| `value_date` | DATE | the **economic date** of the operation. May differ from `date`; see §5.4 |
| `accounting_period_id` | BIGINT | FK → `AccountingPeriod`, RESTRICT. **Derived from `date` in the Action, never supplied by the caller** |
| `fiscal_year_id` | BIGINT | FK → `FiscalYear`, RESTRICT. **Derived from `date`** |
| `academic_year_id` | BIGINT | FK → `AcademicYear`, RESTRICT. **Derived from `date`** (`00-core` §8 guarantees exactly one match) |
| `label` | VARCHAR(255) | |
| `reference` | VARCHAR(80) NULL | external document reference |
| `status` | ENUM | `draft, posted, reversed` |
| `source_type` | VARCHAR(80) NULL | polymorphic origin, e.g. `Fees\Invoice` |
| `source_id` | BIGINT NULL | |
| `posting_rule_id` | BIGINT NULL | FK → `PostingRule`, RESTRICT |
| `posting_rule_version` | INT NULL | stamped at posting |
| `reverses_entry_id` | BIGINT NULL | FK self, RESTRICT. **UNIQUE.** §9 |
| `reversed_by_entry_id` | BIGINT NULL | FK self, RESTRICT. **UNIQUE** |
| `is_reversal` | BOOLEAN | generated: `reverses_entry_id IS NOT NULL`, STORED |
| `reversal_reason` | VARCHAR(500) NULL | mandatory when `is_reversal` |
| `is_migration` | BOOLEAN | §18.4 — suppresses posting-rule evaluation and downstream events |
| `is_forward_posted` | BOOLEAN | §5.4 |
| `total_debit`, `total_credit` | BIGINT SIGNED | denormalised, maintained in-Action, asserted equal |
| `created_by`, `posted_by`, `approved_by` | BIGINT NULL | FK → users, **RESTRICT** |
| `posted_at`, `approved_at` | DATETIME NULL | |
| `idempotency_key` | VARCHAR(120) NULL | `00-core` §6.2 rule 7 |
| `attachment_count` | INT | §4.4 |
| timestamps | | no `SoftDeletes` — §15 |

**Keys**

```sql
UNIQUE KEY uq_je_piece (journal_id, fiscal_year_id, piece_no),
UNIQUE KEY uq_je_source_rule (source_type, source_id, posting_rule_id),   -- 00-core §10.3
UNIQUE KEY uq_je_reverses (reverses_entry_id),
UNIQUE KEY uq_je_reversed_by (reversed_by_entry_id),
UNIQUE KEY uq_je_idem (idempotency_key),
KEY ix_je_period (accounting_period_id, status),
KEY ix_je_date (date),
KEY ix_je_value_date (value_date),
KEY ix_je_source (source_type, source_id),
CONSTRAINT ck_je_reversal_reason CHECK (reverses_entry_id IS NULL OR reversal_reason IS NOT NULL),
CONSTRAINT ck_je_no_self_reverse CHECK (reverses_entry_id IS NULL OR reverses_entry_id <> id),
CONSTRAINT ck_je_piece_when_posted CHECK (status = 'draft' OR piece_no IS NOT NULL),
CONSTRAINT ck_je_totals CHECK (total_debit = total_credit)
```

> `uq_je_source_rule` deliberately permits `(NULL, NULL, NULL)` repeatedly — MySQL UNIQUE ignores NULL tuples — which is correct: manual entries have no source. Automated entries always populate all three.

### 4.2 `JournalEntryLine`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `journal_entry_id` | BIGINT | FK, **ON DELETE RESTRICT** — never CASCADE (`00-core` §10.5). Lines of a draft are removed by the Action explicitly, in the same transaction, before the parent |
| `sequence` | SMALLINT | display order, 1-based |
| `account_id` | BIGINT | FK → CoA, **RESTRICT** |
| `label` | VARCHAR(255) | |
| `debit` | BIGINT SIGNED | ≥ 0 |
| `credit` | BIGINT SIGNED | ≥ 0 |
| `partner_type` | ENUM NULL | `student, guardian, supplier, staff, organisation` — §8 |
| `partner_id` | BIGINT NULL | polymorphic, **not** an FK; integrity by nightly job + Action validation |
| `lettering_id` | BIGINT NULL | FK → `Lettering`, **ON DELETE SET NULL** (unlettering deletes the group) |
| `tax_code_id` | BIGINT NULL | FK → `TaxCode`, RESTRICT |
| `tax_base_amount` | BIGINT SIGNED NULL | |
| `due_date` | DATE NULL | drives aged balances on class 4 lines |
| `reconciliation_match_id` | BIGINT NULL | FK → `ReconciliationMatch`, SET NULL — §13 |
| `source_line_type` / `source_line_id` | VARCHAR(80)/BIGINT NULL | traceability to `InvoiceLine`, `PayrollLine`, `StockMovement` |

**Keys**

```sql
UNIQUE KEY uq_jel_seq (journal_entry_id, sequence),
KEY ix_jel_account_entry (account_id, journal_entry_id),
KEY ix_jel_partner (partner_type, partner_id, account_id),
KEY ix_jel_lettering (lettering_id),
KEY ix_jel_due (account_id, due_date),
CONSTRAINT ck_jel_one_side CHECK ((debit = 0) <> (credit = 0)),   -- 00-core §10.3
CONSTRAINT ck_jel_nonneg CHECK (debit >= 0 AND credit >= 0)
```

> `ck_jel_one_side` forbids a 0/0 line. A genuinely zero-amount memo does not belong in the ledger; put it in `label` or an attachment.

### 4.3 Ledger invariants and their enforcement mechanism

This is the table an auditor is shown. Every invariant names **exactly one primary mechanism** and, where the primary cannot be absolute, a **backstop**.

| # | Invariant | Primary mechanism | Backstop |
|---|---|---|---|
| **L1** | Exactly one of `debit`/`credit` is non-zero on every line | `CHECK ((debit=0) <> (credit=0))` | — |
| **L2** | `Σ debit = Σ credit` for every entry | **In-Action**, computed in PHP via `Money`, asserted before commit under `SELECT … FOR UPDATE` on the `JournalEntry` row | (a) `CHECK (total_debit = total_credit)` on the denormalised totals, maintained by the same Action; (b) **nightly integrity job** reporting any entry where `Σ lines ≠ totals` or `Σdebit ≠ Σcredit`, raising a health-page alarm |
| **L3** | No line may be inserted, updated or deleted where the parent entry is `posted` or `reversed` | **BEFORE INSERT / BEFORE UPDATE / BEFORE DELETE triggers** on `JournalEntryLine` raising `SIGNAL SQLSTATE '45000'` | Model observer refuses; arch test asserts no Action writes lines outside `PostJournalEntry` / `DraftJournalEntry` |
| **L4** | A `posted` entry's `date`, `value_date`, `journal_id`, `piece_no`, `accounting_period_id`, `fiscal_year_id`, `academic_year_id`, `posting_rule_id`, `posting_rule_version` are immutable | **BEFORE UPDATE trigger** on `JournalEntry` | audit log hash chain (`00-core` §14) |
| **L5** | An entry may only be posted into an **open** `AccountingPeriod` | **In-Action**, under a shared lock on the period row (`00-core` §11) | `CHECK` cannot express it; **nightly job** asserts no `posted` entry references a period whose `closed_at` predates the entry's `posted_at` |
| **L6** | `accounting_period_id`, `fiscal_year_id`, `academic_year_id` are **derived from `date`**, never supplied | **In-Action** — the DTO has no such fields | `CHECK (date BETWEEN fy.starts_on AND fy.ends_on)` is not expressible cross-table in MySQL; a **BEFORE INSERT/UPDATE trigger** performs the three lookups and raises on mismatch (**C3**) |
| **L7** | `piece_no` is gapless per `(journal, fiscal_year)`, allocated only at posting | Sequence row locked `FOR UPDATE` **inside the posting transaction** (`00-core` §12) | Nightly assertion `COUNT(*) = MAX(piece_no)` per `(journal_id, fiscal_year_id)`; `SequenceGap` report |
| **L8** | A line on a **collective** account carries a partner; a line on a non-collective account carries none | **BEFORE INSERT/UPDATE trigger** on `JournalEntryLine` joining `ChartOfAccount` | Nightly job; blocked at `YearEndChecklist` |
| **L9** | Σ auxiliary balances per collective account = the collective account's GL balance | **Nightly reconciliation job** + **mandatory `YearEndChecklist` step** blocking period close | L8 makes violation structurally impossible; L9 proves it |
| **L10** | A `full` `Lettering` group satisfies `Σdebit = Σcredit` | **In-Action** under `FOR UPDATE` on the `Lettering` row | Nightly job downgrades a violating group to `partial` and alarms |
| **L11** | Analytic splits on a line sum to the line amount | **In-Action** | Nightly job; blocks close |
| **L12** | `reverses_entry_id` is UNIQUE; a reversal may not be reversed | `UNIQUE` + **in-Action** check `target.is_reversal = false` | — |
| **L13** | Only `draft` entries are excluded from statements. **`posted` and `reversed` both appear and net to zero** | **A single `PostedLedger` query scope** used by every statement, book and report. Arch test: no statement query filters on `status` directly | §9.3 |
| **L14** | No accounting record is hard-deleted for 10 years | **Global model observer** on an `Immutable10Year` trait; `deleting` throws | Nightly job compares row counts against an append-only tally table |
| **L15** | Every posted entry carries ≥1 attachment **or** an explicit `no_attachment_reason` | In-Action, configurable per journal (`Journal.requires_attachment`) | Missing-pièce report; `YearEndChecklist` step |

### 4.4 Supporting documents (pièces justificatives)

AUDCIF Art. 17 requires each entry to be referenced to a datable supporting document.

`JournalEntryAttachment` — `journal_entry_id` (RESTRICT), `document_type`, `file_path`, `sha256`, `original_filename`, `byte_size`, `uploaded_by` (RESTRICT), `uploaded_at`, `is_generated` (true when the system produced it, e.g. a receipt PDF). Attachments inherit the 10-year retention rule; the file is retained on disk alongside a hash so tampering is detectable.

Automated entries auto-attach: the receipt PDF for a payment, the invoice PDF for an invoice, the payslip batch for payroll, the depreciation working paper for a depreciation run.

---

## 5. `AccountingPeriod` and the clôture informatique

### 5.1 Entity

`AccountingPeriod` is a **calendar month** in the ledger (`00-core` §5 vocabulary — never "period" unqualified).

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `fiscal_year_id` | BIGINT | FK, RESTRICT |
| `period_month` | DATE | first day of the month |
| `starts_on`, `ends_on` | DATE | |
| `status` | ENUM | `open, soft_locked, hard_locked` |
| `soft_locked_at`, `soft_locked_by` | | RESTRICT |
| `hard_locked_at`, `hard_locked_by` | | RESTRICT |
| `unlock_reason` | VARCHAR(500) NULL | |
| `is_quarter_end` | BOOLEAN | drives the Art. 22 forced closure |
| `forced_closure_due_on` | DATE NULL | |

`UNIQUE (fiscal_year_id, period_month)`. Periods are generated for all 12 months when a `FiscalYear` is created, so no month is ever missing.

### 5.2 Two-stage lock (C8)

v1 locked the period and then posted adjusting entries into it, contradicting its own invariant. Resolved with two stages:

| Status | Operational modules (fees, payroll, procurement, inventory, assets) | Accounting module manual entries | Year-end Actions (depreciation, provisions, cut-off, closing) |
|---|---|---|---|
| `open` | ✅ | ✅ | ✅ |
| `soft_locked` | ❌ | ❌ *(except with `accounting.post_to_soft_locked` permission, audited, reason mandatory)* | ✅ |
| `hard_locked` | ❌ | ❌ | ❌ |

Locking sequence at year end is §17. **Soft lock happens first; the entire adjustment sequence runs against soft-locked periods; hard lock is the last step.**

**Unlocking.** `soft_locked → open` requires `accounting.unlock_period`, a mandatory reason, and is audited. `hard_locked → soft_locked` requires Super Admin, a mandatory reason, and is **refused outright** if the fiscal year has `dsf_filed_at` set (`03-tax-procurement.md`). Quarterly closures rendered definitive under Art. 22 are hard locks and are **never** reopened; a correction is a reversal in the current open period (§9).

**Locking concurrency** (`00-core` §11): posting takes a **shared** lock on the `AccountingPeriod` row; the locking Action takes it **exclusively**. This closes the check-then-act window where an entry posts between the status read and the lock write.

### 5.3 Forced quarterly closure (AUDCIF Art. 22)

A scheduled job `accounting:force-quarterly-closure` runs daily. For each `AccountingPeriod` where `is_quarter_end = true` and `forced_closure_due_on <= business_date()` and `status <> 'hard_locked'`:

1. Escalating warnings at D−30, D−15, D−7, D−1 on the health page and to the Accountant and Administrator roles.
2. On the due date, the job **soft-locks** the quarter's three periods and raises a blocking banner.
3. It does **not** silently hard-lock: a hard lock with an unbalanced entry outstanding would freeze a broken state. It runs the §17.9 trial-balance validation first, and hard-locks only if it passes; otherwise it alarms and holds at soft lock.

`forced_closure_due_on` defaults to the last day of the month following quarter end and is configurable in settings. *The precise deadline AUDCIF Art. 22 imposes for the clôture informatique beyond "at least quarterly" is `NEEDS VERIFICATION` — the product must not assert a date it cannot cite. The default is a conservative operational choice, labelled as such in the settings screen.*

### 5.4 Late documents — the forward-posting rule (C4)

**The rule, stated exactly.** An operation whose **value date** falls in a period that is already `hard_locked` is recorded on the **first day of the first open period**, retaining its original value date.

Implementation in `PostJournalEntry`:

```
requested_date := input.date
period := AccountingPeriod::containing(requested_date)

if period.status == 'hard_locked':
    target := AccountingPeriod::firstOpenOnOrAfter(requested_date)
    if target is null: FAIL — "no open accounting period exists"   // configuration error, not a data error
    entry.value_date       := requested_date       // preserved, always
    entry.date             := target.starts_on
    entry.is_forward_posted := true
    entry.label            := label + " (opération du " + format(requested_date) + ")"
    emit ForwardPostedEntryRecorded event
else:
    entry.value_date := input.value_date ?? requested_date
    entry.date       := requested_date
```

`soft_locked` is **not** forward-posted: the accountant with the elevated permission may still post there deliberately, and the year-end Actions must. Only `hard_locked` forces the shift.

A **Forward-posted entries report** lists, per period, every entry where `is_forward_posted = true`, with both dates and the gap in days. It is a standard audit request and a management KPI — a rising count means documents are reaching the bursar late.

> **Why this matters.** v1 rejected late documents. A supplier invoice dated 28 December arriving 12 January was refused, so it was either never recorded or recorded with a falsified date. Both are worse than the Art. 22 treatment, and the second is a fraud finding.

---

## 6. `FiscalYear`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(10) | e.g. `2026`. UNIQUE, `as_cs` |
| `starts_on`, `ends_on` | DATE | |
| `status` | ENUM | `planned, open, closing, closed` |
| `is_first_exercice` | BOOLEAN | permits the irregular first exercice |
| `opening_entry_id` | BIGINT NULL | the à-nouveaux entry, FK RESTRICT |
| `closing_entry_id` | BIGINT NULL | FK RESTRICT |
| `result_appropriation_id` | BIGINT NULL | §18.3 |
| `prorata_de_deduction_bp` | BIGINT NULL | owned by `03-tax-procurement`, stored here as it is per-year |
| `dsf_filed_at`, `dsf_reference` | | owned by `03`; **hard-blocks reopening** once set |
| `closed_at`, `closed_by` | | RESTRICT |

**Validation Action `ValidateFiscalYearDates`:**

| Rule | Behaviour |
|---|---|
| `starts_on = 1 January` and `ends_on = 31 December` of the same year | **Required** unless `is_first_exercice` |
| `is_first_exercice = true` | `ends_on` must still be 31 December; `starts_on` may be any date in that calendar year. *(Whether AUDCIF permits a first exercice exceeding 12 months, as some frameworks do, is `NEEDS VERIFICATION`. The product refuses > 12 months and tells the user to consult their accountant.)* |
| Overlap with any other `FiscalYear` | Rejected |
| Gap from the previous `FiscalYear` | Rejected — years are contiguous |

The setup wizard **warns hard, in red, with an explicit acknowledgement checkbox**, if the operator attempts a non-calendar year, explaining that the resulting accounts cannot be filed. It does not permit override.

---

## 7. Dual calendar (C3)

v1 promised dual-calendar provenance and delivered it on `JournalEntry` alone. Every financial entity carries **both** a `fiscal_year_id` and an `academic_year_id`, both **RESTRICT**:

| Entity | Owner doc | Both required |
|---|---|---|
| `JournalEntry` | 02 | ✅ |
| `Invoice`, `CreditNote`, `Payment`, `Refund`, `FeeAdjustment` | 04 | ✅ |
| `SupplierInvoice`, `SupplierPayment`, `PurchaseOrder` | 03 | ✅ |
| `PayrollRun`, `PayrollItem` | 05 | ✅ |
| `Asset`, `DepreciationRun`, `DepreciationSchedule`, `AssetDisposal` | 06 | ✅ |
| `Budget`, `BudgetLine` | 02 | ✅ |
| `StockMovement`, `StockTake` | 06 | ✅ |
| `TaxDeclaration`, `StatutoryDeclaration` | 03, 05 | ✅ |

**Enforcement.** A shared `HasDualCalendar` trait derives both from the entity's governing date at create, and a **BEFORE INSERT/UPDATE trigger per table** asserts the date falls inside both referenced years. Because `AcademicYear` is contiguous and gapless (`00-core` §8) and `FiscalYear` is contiguous, the derivation is total — there is no date with no home.

Reporting consequence: every financial report takes an **axis parameter** (`fiscal_year` | `academic_year`) and states it in the header. "Revenue for 2026" is ambiguous otherwise, and the two answers differ by a full term.

---

## 8. Auxiliary accounting — comptes de tiers (C2)

### 8.1 The problem and the resolution

The promised aged-receivables report was unbuildable. Two wrong designs were on the table:

- **One 4111 sub-account per student.** A 1 200-student school produces a 3 000-row chart within three years, an unreadable grand livre, and a balance générale nobody can print.
- **One shared 4111.** The balance is right and the identity of who owes it is gone.

**Verified fact:** SYSCOHADA compte **411** subdivides by **counterparty category** — **4111 Clients**, **4112 Clients — Groupe**, **4114 Clients — État** — **not by individual counterparty.** The individual belongs in an auxiliary layer beneath the account, which is standard practice in every SYSCOHADA-compliant system.

### 8.2 Schema

Three fields carry it:

- `ChartOfAccount.is_collective` — this account's balance is composed of per-partner sub-balances.
- `ChartOfAccount.requires_partner` — a line here **must** carry a partner. Implied by `is_collective`.
- `ChartOfAccount.allowed_partner_types` — e.g. 4111 allows `student, guardian, organisation`; 401 allows `supplier`; 42x allows `staff`.
- `JournalEntryLine.partner_type` + `partner_id`.

Collective by default in the seed: **411x**, **416x**, **4181**, **4191**, **4198**, **401**, **481x**, **42x** (personnel), **47x** where third-party funds are held per beneficiary.

### 8.3 Invariant L8, stated exactly

> A `JournalEntryLine` whose account has `is_collective = true` **MUST** carry a non-null `partner_type` and `partner_id`, and `partner_type` must be in the account's `allowed_partner_types`.
> A `JournalEntryLine` whose account has `is_collective = false` **MUST NOT** carry a partner.

Enforced by a BEFORE INSERT/UPDATE trigger on `JournalEntryLine` that joins `ChartOfAccount`:

```sql
-- pseudocode inside the trigger
IF coa.is_collective AND (NEW.partner_type IS NULL OR NEW.partner_id IS NULL) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: collective account requires a partner';
END IF;
IF NOT coa.is_collective AND NEW.partner_type IS NOT NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'L8: non-collective account must not carry a partner';
END IF;
```

The trigger is the primary mechanism precisely because posting rules, the opening-balance import and manual entry are three different write paths, and a check in one Action protects none of the others.

`partner_id` is **not** a foreign key — it is polymorphic across five tables. Integrity is maintained by (a) an Action-level existence check, and (b) a nightly **orphan-partner job** that reports any line whose partner no longer resolves. Since `00-core` §10.5 makes Student, Supplier and StaffMember RESTRICT-on-delete, orphans should be structurally impossible; the job proves it rather than assuming it.

### 8.4 Auxiliary reconciliation (L9)

`AuxiliaryReconciliation` — `accounting_period_id`, `account_id`, `gl_balance`, `auxiliary_sum`, `difference`, `status` (`balanced` | `out_of_balance`), `run_at`, `run_by`.

Query, per collective account, `as_of` a date:

```sql
-- collective balance
SELECT SUM(l.debit) - SUM(l.credit)
FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
WHERE l.account_id = :acct AND e.status IN ('posted','reversed') AND e.date <= :as_of;

-- auxiliary sum
SELECT l.partner_type, l.partner_id, SUM(l.debit) - SUM(l.credit) AS bal
FROM journal_entry_lines l JOIN journal_entries e ON e.id = l.journal_entry_id
WHERE l.account_id = :acct AND e.status IN ('posted','reversed') AND e.date <= :as_of
GROUP BY l.partner_type, l.partner_id;
```

Note both use `status IN ('posted','reversed')` — L13. Filtering `status = 'posted'` here would drop the original of every reversed pair and leave the reversal, flipping signs.

Runs **nightly** and as a **mandatory `YearEndChecklist` step** at every period close. A non-zero difference blocks close and alarms; by construction of L8 it can only arise from a data-integrity fault, which is exactly why it is worth proving.

### 8.5 Aged balances

The aged receivables and aged payables listings are **derived from unlettered items** (§10.5), aged on `JournalEntryLine.due_date`, not on entry date. `04-fees.md` owns the fee-side aging axis (instalment due date); `03-tax-procurement.md` owns payables. This document owns the ledger query and the `as_of` semantics: the report takes an explicit `as_of` date, includes entries with `date <= as_of`, and prints the axis it aged on in its header.

---

## 9. Reversal semantics (C9)

### 9.1 Both directions

v1 had only `reversed_by_entry_id`. **Auditors read in the other direction** — they hold a reversal and ask what it cancelled. Both columns exist, both UNIQUE, and the Action sets both in one transaction.

### 9.2 The Action

`ReverseJournalEntry(entry, reason, actor)`:

| Step | Rule |
|---|---|
| 1 | `SELECT … FOR UPDATE` on the target entry |
| 2 | Refuse if `status <> 'posted'` — a draft is edited or discarded, never reversed |
| 3 | Refuse if `reversed_by_entry_id IS NOT NULL` — **no double reversal** (also guaranteed by the UNIQUE on `reverses_entry_id`) |
| 4 | Refuse if `target.is_reversal = true` — **a reversal may not be reversed.** Reversing a reversal means the original was right after all; the correct operation is a fresh entry that restates it, with its own justification |
| 5 | `reason` is mandatory, minimum 10 characters, stored on the reversal |
| 6 | **Date the reversal in the earliest open `AccountingPeriod`** — never the original date. Inheriting the original date posts into a possibly hard-locked period and, even when open, silently rewrites a month whose trial balance was already circulated |
| 7 | `value_date` of the reversal = `business_date()` of the reversal, not the original's |
| 8 | Build mirrored lines: every `debit` becomes `credit` and vice versa, **preserving `partner_type`/`partner_id`, `tax_code_id`, analytic splits and `due_date`** — a reversal that drops the partner breaks L9 |
| 9 | Post it; allocate a fresh `piece_no` |
| 10 | Set `target.reversed_by_entry_id`, `target.status = 'reversed'` |
| 11 | If the target's lines were lettered, **unletter** the affected groups (§10.4) with reason `reversal` |
| 12 | Emit `JournalEntryReversed` after commit (`00-core` §6.2 rule 6) so the source module can react (re-open an invoice line, un-clear a payment) |

### 9.3 The statement rule, stated explicitly because it is a trap

> **Only `draft` is excluded from financial statements. Both `posted` and `reversed` remain in every statement, book and balance, and net to zero.**

A developer reading `status = 'reversed'` will naturally filter it out. Doing so removes the **original** while leaving the **reversal** in place, flipping the sign of the whole transaction — a 350 000 debit becomes a 350 000 credit and the trial balance still balances, so nothing catches it.

Enforcement:

- A single query scope `JournalEntry::posted()` ⇒ `whereIn('status', ['posted','reversed'])` is the **only** permitted entry point for any statement, book, balance, aged listing, budget-actual query or dashboard KPI.
- **Pest architecture test:** no file under `Modules/Accounting/{Reports,Books,Statements}` may contain the literal `'posted'` or `'reversed'` in a `where` on `status`. They must call the scope.
- **Golden test:** post an entry, reverse it, assert every statement line and the trial balance are byte-identical to the pre-post state, and assert the grand livre shows **both** movements.

### 9.4 Partial correction

There is no partial reversal. **A partial correction is a full reversal plus a fresh entry.** The audit trail then shows three entries — original, contrepassation, correction — which is what an auditor expects to find and what `piece_no` continuity requires.

---

## 10. Lettering (C10)

### 10.1 The problem

v1 stored lettering as a bare string on the line with no invariant. Nothing prevented code `AA` appearing on 350 000 of debits and 200 000 of credits, at which point the receivables reconciliation is fiction and the aged listing is noise.

### 10.2 `Lettering`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `account_id` | BIGINT | FK → CoA, RESTRICT. `is_lettrable` must be true |
| `partner_type`, `partner_id` | | must match every member line |
| `code` | VARCHAR(10) | `AA`, `AB`, … per (account, partner) |
| `status` | ENUM | `partial, full` |
| `total_debit`, `total_credit` | BIGINT SIGNED | denormalised, maintained in-Action |
| `lettered_by`, `lettered_at` | | RESTRICT |
| `unlettered_by`, `unlettered_at`, `unletter_reason` | | |
| `is_auto` | BOOLEAN | produced by automatic allocation matching |

```sql
UNIQUE KEY uq_lettering_code (account_id, partner_type, partner_id, code),
CONSTRAINT ck_lettering_full CHECK (status = 'partial' OR total_debit = total_credit)
```

### 10.3 Invariants

| # | Invariant | Enforcement |
|---|---|---|
| LT-1 | Every line in a group shares the same `account_id`, `partner_type`, `partner_id` | In-Action + nightly job |
| LT-2 | A `full` group satisfies `Σdebit = Σcredit` | In-Action under `FOR UPDATE` on the `Lettering` row; **plus** the CHECK on the denormalised totals |
| LT-3 | A `partial` group may be unbalanced — that is its purpose (a part payment) | — |
| LT-4 | A group with `Σdebit = Σcredit` and ≥2 lines is **automatically promoted** to `full` | In-Action, at every mutation |
| LT-5 | Only lines on `posted`/`reversed` entries may be lettered | In-Action |
| LT-6 | A line belongs to at most one group | `lettering_id` is a scalar FK |

### 10.4 Auto-lettering and unlettering

**Auto-lettering** fires from `PaymentAllocated` (`04-fees.md`) and `SupplierPaymentAllocated` (`03`). The Action locks the `Lettering` row (creating it if absent), attaches the invoice's receivable line and the payment's receivable line, recomputes totals and promotes to `full` when they match. Under `Money`, not SQL arithmetic.

**Unlettering** is an explicit Action requiring `accounting.unletter`, a mandatory reason, recording `unlettered_by/at`. It sets `lettering_id = NULL` on member lines (`ON DELETE SET NULL` covers the delete path) and retains the `Lettering` row as a historical record with `status` and its unlettering metadata — the row is never hard-deleted (§15).

Automatic unlettering is triggered by: payment void, cheque bounce, credit note, refund, and journal-entry reversal (§9.2 step 11).

### 10.5 The unlettered-items report

This is **the actual source of the aged-receivables listing** and of the aged payables. It lists, per collective account and partner, every line not in a `full` group, with `due_date`, age bucket, and the net position. `04-fees.md` consumes it for dunning; `03-tax-procurement.md` consumes it for the supplier statement.

---

## 11. Posting rules and worked double-entry examples

### 11.1 `PostingRule` and `PostingRuleLine` (C1)

v1's `PostingRule` was a single `(debit_account, credit_account)` pair. That **cannot express** payroll (8–12 lines), an asset disposal (4 legs), or a multi-item invoice (N credit lines). It is replaced by a header plus ordered lines.

**`PostingRule`** (header)

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `code` | VARCHAR(60) | `as_cs`, part of the version key |
| `version` | INT | starts at 1 |
| `event` | VARCHAR(80) | see §11.2 |
| `journal_id` | BIGINT | FK RESTRICT |
| `label_expression` | VARCHAR(255) | templated from the payload |
| `condition_expression` | TEXT NULL | **whitelisted grammar only** — see below |
| `priority` | INT | higher wins |
| `is_active` | BOOLEAN | |
| `is_locked` | BOOLEAN | set true the first time the rule posts an entry |
| `effective_from`, `effective_to` | DATE / DATE NULL | `effective_to` exclusive |
| `created_by`, `approved_by` | | RESTRICT, §20 |

```sql
UNIQUE KEY uq_rule_version (code, version),
KEY ix_rule_event (event, is_active, priority)
```

**`PostingRuleLine`**

| Field | Type | Notes |
|---|---|---|
| `posting_rule_id` | BIGINT | FK RESTRICT |
| `sequence` | SMALLINT | ordered; `UNIQUE(posting_rule_id, sequence)` |
| `account_source` | ENUM | `literal` \| `payload_path` \| `setting` |
| `account_code` | VARCHAR(20) NULL | when `literal` |
| `account_path` | VARCHAR(200) NULL | when `payload_path`, e.g. `payment.method.treasury_account_id` |
| `sign` | ENUM | `debit` \| `credit` \| `signed` (sign taken from the amount) |
| `amount_expression` | TEXT | whitelisted grammar over payload variables |
| `is_balancing` | BOOLEAN | at most one per rule; its amount is `total − Σ(others)` (`00-core` §7.3) |
| `partner_source` | VARCHAR(200) NULL | payload path yielding `(type, id)` |
| `analytic_source` | VARCHAR(200) NULL | payload path yielding analytic splits |
| `tax_code_source` | VARCHAR(200) NULL | |
| `due_date_source` | VARCHAR(200) NULL | |
| `iterates_over` | VARCHAR(200) NULL | a payload collection path — **this is what makes N credit lines possible**. One physical line per element |
| `label_expression` | VARCHAR(255) | |
| `skip_if_zero` | BOOLEAN | default true — a zero line violates L1 |

**Expression grammar.** `condition_expression` and `amount_expression` use the **same whitelisted grammar specified in `05-hr-payroll.md`** for payroll formulas: named variables resolved from the payload, integer arithmetic (`+ - * /` with `/` as integer division through `Money`), `min`, `max`, `abs`, parentheses, and comparison/boolean operators in conditions. **No function calls, no property chains outside the declared payload schema, no dynamic evaluation, never `eval()`.** Parsed and validated at save against the event's declared payload schema; unknown variables are a save-time error. A dry-run preview renders the resulting entry against a sample payload before the rule may be activated.

> Posting-rule expressions and payroll formulas together are the product's largest injection surface. One grammar, one parser, one test suite.

**Rule selection.** For an event, candidates are rules where `is_active`, the event matches, `date` falls in `[effective_from, effective_to)`, and `condition_expression` evaluates true. **The highest-priority single match wins.** If two matching rules share the top priority, posting **fails loudly** — it does not pick one.

`ValidatePostingRules` is a standalone Action, run at rule save and nightly, that detects **ambiguous overlaps**: for each event, it enumerates rules sharing a priority with overlapping effective ranges and reports them. A configuration that can be ambiguous is rejected at save, not discovered at posting time in front of a parent at the cash desk.

**Immutable versioning.** Once `is_locked = true`, editing produces a **new version row** (`version + 1`, previous row's `effective_to` closed) rather than mutating. Every `JournalEntry` stamps `posting_rule_id` **and** `posting_rule_version`, so an auditor can reconstruct exactly which rule produced an entry three years ago.

### 11.2 The event catalogue

v1's list was missing about a third of what the domain needs. Complete list, grouped:

**Fees / receivables** — `fee.invoice.issued` · `fee.invoice.cancelled` · `fee.credit_note.issued` · `fee.payment.received` · `fee.payment.voided` · `fee.refund.issued` · `fee.adjustment.granted` · `receivable.written_off` · `receivable.provision.recognized` · `receivable.provision.reversed` · `revenue.deferral.recognized` · `revenue.deferral.reversed` · `thirdparty.fund.collected` · `thirdparty.fund.remitted`

**Treasury** — `cheque.received` · `cheque.cleared` · `cheque.bounced` · `cashdesk.closed_with_variance` · `bank.charge.recorded` · `bank.interest.received` · `treasury.transfer` · `mobile_money.commission.charged`

**Procurement / payables** (`03`) — `supplier.invoice.received` · `supplier.credit_note.received` · `supplier.paid` · `withholding.retained` · `goods.received_not_invoiced`

**Inventory** (`06`) — `inventory.purchased` · `inventory.received_into_stock` · `inventory.issued` · `inventory.sold` · `inventory.transfer` · `inventory.stocktake.variance` · `inventory.written_off`

**Assets** (`06`) — `asset.acquired` · `asset.commissioned` · `asset.depreciated` · `asset.impaired` · `asset.revalued` · `asset.disposed` · `asset.subsidy.received` · `asset.subsidy.released`

**Payroll** (`05`) — `payroll.approved` · `payroll.paid` · `payroll.reversed` · `payroll.leave_provision` · `payroll.leave_provision.reversed` · `payroll.settlement.final`

**Tax** (`03`) — `tax.vat.declared` · `tax.remitted` · `tax.provision.recognized`

**Library** (`06`) — `library.fine.levied` · `library.fine.collected` · `library.book.lost`

> `library.fine.levied` is new. v1 had only `.collected`, so the **receivable was never recognised** — a levied fine existed nowhere in the ledger until money arrived, and the report card's fee-balance block understated what the student owed.

**Year end** — `year_end.closing` · `year_end.appropriation` · `year_end.opening_balances` · `fx.revaluation` *(only if §19 option B is chosen)*

Each event declares a **payload schema** (a typed DTO). Posting rules validate their expressions against it. Adding an event without a schema fails the arch test.

### 11.3 Worked example — fee payment by mobile money

*The v1 version of this example is the one that could never reconcile against the MoMo statement.*

**Facts.** Parent pays 350 000 FCFA of Term 3 tuition for Ncham Andre Bela via MTN MoMo. The operator deducts a 1.5% commission of 5 250 FCFA; the school bears the fee (`PaymentMethod.fee_bearer = school`, `04-fees.md`). 355 250 is debited from the parent; 350 000 settles the invoice; the school's MoMo float receives 344 750.

*(The commission model — whether the operator deducts from the transfer or bills separately, and at what rate — is per-school and per-operator. It is configuration on `PaymentMethod`, never a seeded constant.)*

Event `fee.payment.received`, journal `MM`:

| Seq | Account | Label | Partner | Debit | Credit |
|---:|---|---|---|---:|---:|
| 1 | **552** Téléphone Portable | Encaissement MoMo réf. MM-88421 | — | 344 750 | |
| 2 | **6317** Commissions | Commission opérateur MoMo 1,5% | — | 5 250 | |
| 3 | **4111** Clients | Ncham Andre Bela — Facture INV-2026-1188 | student:4412 | | 350 000 |
| | | **Totals** | | **350 000** | **350 000** |

Checks: L1 ✅ each line one-sided · L2 ✅ 350 000 = 350 000 · L8 ✅ line 3 on collective 4111 carries a partner, lines 1–2 on non-collective accounts carry none · line 3 is auto-lettered against the invoice's 4111 debit (§10.4) · the 552 balance now equals the MoMo float, so §13 reconciliation against the operator statement will tie.

**What v1 did:** `Dr 5210 (bank) 350 000 / Cr 7061 350 000`. Four defects in one entry — wrong treasury class (bank, not e-money), commission ignored so the float can never reconcile, revenue recognised directly instead of clearing the receivable so the invoice stays open forever, and 7061 is the geographic code.

### 11.4 Worked example — inventory purchase, entry into stock, issue (C6)

**The rule.** 603x *Variations des stocks* is **credited on inflow** and **debited on outflow**. Purchases always pass through **601/602/604**. Posting the stock movement straight to 31/32/33 against the supplier bypasses both, leaving the Compte de résultat with **zero on the Achats line and zero on the Variation-de-stocks line** — the DSF is then wrong on two lines and the gross margin is unpresentable.

**Facts.** The school buys 200 exercise books for the bookshop at 500 FCFA each = 100 000 FCFA on credit from Librairie Centrale. All 200 enter stock. Later, 50 are issued to the Primary department as consumables *(handled as a separate item category; the example uses one item for clarity)*.

*Ignoring TVA, which is owned by `03-tax-procurement.md`. Exercise books for resale are a taxable commercial activity under the 2022 Finance Law and carry TVA in the real entry.*

**(a) Purchase — event `inventory.purchased`, journal `AC`**

| Seq | Account | Partner | Debit | Credit |
|---:|---|---|---:|---:|
| 1 | **601** Achats de marchandises | — | 100 000 | |
| 2 | **401** Fournisseurs | supplier:17 | | 100 000 |

**(b) Entry into stock — event `inventory.received_into_stock`, journal `OD`**

| Seq | Account | Debit | Credit |
|---:|---|---:|---:|
| 1 | **31** Marchandises | 100 000 | |
| 2 | **6031** Variations des stocks de marchandises | | 100 000 |

> 6031 **credited** on inflow. The P&L now carries a 100 000 purchase and a 100 000 negative variation — net zero charge, because nothing has been consumed.

**(c) Issue of 50 units at weighted-average cost 500 — event `inventory.issued`, journal `OD`**

| Seq | Account | Debit | Credit |
|---:|---|---:|---:|
| 1 | **6031** Variations des stocks de marchandises | 25 000 | |
| 2 | **31** Marchandises | | 25 000 |

> 6031 **debited** on outflow. Net P&L charge is now 25 000 — exactly the cost of what was consumed. Stock stands at 75 000. Both statutory lines carry the right numbers.

**(d) Stock-take variance.** A count finding 148 units against a book 150 posts the shortfall to **603x** in the same direction as an issue: `Dr 6031 / Cr 31` for 2 × unit cost. An overage reverses it. `06-assets-stores.md` owns the `StockTake` entity and the weighted-average-cost mechanics; this document owns the posting scheme and the requirement that `ItemCategory` carries `purchase_account_id`, `stock_account_id` and `variation_account_id` so the rule can resolve all three.

### 11.5 Worked example — cash-desk close with a variance

**Facts.** Bursar's till: opening float 50 000, receipts 1 240 000, expected close 1 290 000, counted 1 285 000. Shortage 5 000.

Event `cashdesk.closed_with_variance`, journal `CA`. Two policies, chosen in settings:

**Policy A — shortage is an expense of the school**

| Account | Debit | Credit |
|---|---:|---:|
| **65x** Autres charges — manquant de caisse ⚠️ | 5 000 | |
| **57** Caisse | | 5 000 |

**Policy B — shortage is recoverable from the custodian** *(the more common control posture)*

| Account | Partner | Debit | Credit |
|---|---|---:|---:|
| **42x** Personnel — avances et acomptes / débiteurs ⚠️ | staff:88 | 5 000 | |
| **57** Caisse | | | 5 000 |

An overage credits **75x** *Autres produits* ⚠️ against 57.

> ⚠️ **NEEDS VERIFICATION.** The brief proposes **658** for shortage and **758** for overage and explicitly flags both codes as unverified. Class 65 / 75 *Autres charges / Autres produits* is the correct neighbourhood; the exact subdivision is not confirmed and **is not seeded**. The cash-desk variance feature **refuses to run** until an accountant assigns the accounts in settings (blocking gate 6). The same applies to the 42x subdivision under Policy B.

Both policies require a **mandatory reason** on `CashDeskSession.variance_reason`, emit a distinct `CashDeskClosedWithVariance` event, and appear on a variance report by cashier and by month. A variance above a configurable threshold requires supervisor approval before the session may close.

### 11.6 Worked example — asset disposal, posted gross (C7)

**The rule.** SYSCOHADA requires the disposal to appear **gross** in the Compte de résultat, as two distinct HAO lines: **81 *Valeurs comptables des cessions d'immobilisations*** (811/812/816) and **82 *Produits des cessions d'immobilisations*** (821/822/826). Posting a **net** gain or loss — which v1 did — collapses two statutory lines into one, understates both, and makes the HAO section of the DSF wrong.

**Facts.** School minibus. Cost 18 000 000. Accumulated depreciation at disposal 13 500 000. NBV 4 500 000. Sold for 5 200 000, receivable from the buyer.

**Leg 1 — remove the asset at net book value**

| Account | Debit | Credit |
|---|---:|---:|
| **2841** Amortissements du matériel de transport *(exact code from the seeded 28x tree)* | 13 500 000 | |
| **812** Valeurs comptables des cessions d'immobilisations corporelles | 4 500 000 | |
| **2451** Matériel de transport *(exact code from the seeded 24x tree)* | | 18 000 000 |

**Leg 2 — recognise the proceeds**

| Account | Partner | Debit | Credit |
|---|---|---:|---:|
| **485** Créances sur cessions d'immobilisations | organisation:31 | 5 200 000 | |
| **822** Produits des cessions d'immobilisations corporelles | — | | 5 200 000 |

**Leg 3 — on collection**

| Account | Partner | Debit | Credit |
|---|---|---:|---:|
| **521** Banque | — | 5 200 000 | |
| **485** Créances sur cessions | organisation:31 | | 5 200 000 |

The P&L shows **4 500 000 on line 81** and **5 200 000 on line 82**. The 700 000 gain is the *difference between two printed lines*, exactly as SYSCOHADA intends.

`Asset.gain_or_loss` is a **derived reporting field only, computed on read, never stored and never posted.** An independently stored gain drifts from the two lines that constitute it, and then two reports disagree.

*(Where the disposal is subject to TVA on the sale, `03-tax-procurement.md` owns the tax leg. 811/816 and 821/826 apply to incorporeal and financial immobilisations respectively; the rule resolves the subdivision from `AssetCategory`.)*

### 11.7 Worked example — payroll, multi-line (C1's motivating case)

**Facts.** One month, aggregate, small school. Illustrative amounts only — **the computation is owned entirely by `05-hr-payroll.md` and no rate is asserted here.** The point of the example is the **shape**: a single event producing 9 lines, which v1's `(debit, credit)` pair could not express at all.

Event `payroll.approved`, journal `PA`:

| Seq | Account | Nature | Partner | Debit | Credit |
|---:|---|---|---|---:|---:|
| 1 | **661** Rémunérations directes | gross salaries | — | 12 400 000 | |
| 2 | **664** Charges sociales patronales | employer CNPS (PVID + PF + RP) | — | 1 630 000 | |
| 3 | **66x** Autres charges de personnel | employer CFC + FNE | — | 310 000 | |
| 4 | **422** Personnel, rémunérations dues | net payable | *per staff* | | 9 980 000 |
| 5 | **431** Sécurité sociale (CNPS) | employee PVID + employer branches | — | | 2 150 000 |
| 6 | **447** État, impôts retenus à la source | IRPP + CAC | — | | 1 620 000 |
| 7 | **44x** État — CFC | employee + employer CFC | — | | 380 000 |
| 8 | **44x** État — FNE | employer FNE | — | | 124 000 |
| 9 | **44x** Commune — TDL | TDL | — | | 86 000 |
| | | **Totals** | | **14 340 000** | **14 340 000** |

Notes that are ledger obligations, not payroll obligations:

- Line 4 **iterates** over the payroll items (`PostingRuleLine.iterates_over = 'items'`), producing one 422 line **per staff member with a partner**, because 422 is collective (L8). The aggregate shown is the sum. This is what makes a per-employee payables listing possible.
- One line carries `is_balancing = true` so the entry cannot fail to balance on a rounding residual (`00-core` §7.3).
- The 43x/44x subdivisions above are **indicative shapes, not seeded codes.** The precise subdivision per statutory branch is assigned with the accountant at setup and is `NEEDS VERIFICATION` here.
- Payment of net salaries is a **separate** event `payroll.paid`: `Dr 422 (per staff) / Cr 521|552|57`, which is what clears the payable and lets the payroll disbursement file reconcile.

### 11.8 Worked example — tuition invoice with an agent-collected item

**Facts.** Invoice INV-2026-1188 to Ncham Andre Bela: tuition 350 000 (own revenue), APEE contribution 15 000 (collected as agent), GCE registration 12 000 (collected as agent). Total 377 000.

Event `fee.invoice.issued`, journal `VE`, line 2 iterating over invoice lines:

| Seq | Account | Partner | Debit | Credit |
|---:|---|---|---:|---:|
| 1 | **4111** Clients | student:4412 | 377 000 | |
| 2 | **706xx** Scolarité *(5-digit extension)* | — | | 350 000 |
| 3 | **47x** APEE — fonds détenus pour tiers | organisation:APEE | | 15 000 |
| 4 | **47x** Frais d'examen GCE — fonds détenus pour tiers | organisation:GCE | | 12 000 |

**Turnover recognised: 350 000, not 377 000.** Lines 3 and 4 are liabilities, not revenue. `FeeItem.collection_basis = agent_for_third_party` drives the account selection; the posting rule resolves the credit account from `line.fee_item.third_party_fund.liability_account_id`. See `04-fees.md` C5 — putting the 27 000 in class 7 overstates turnover, which drives the minimum tax and the acompte d'IS, so **the school pays tax on money that is not its own**, and the school cannot prove to the APEE what it holds on its behalf.

Remittance to the APEE is `thirdparty.fund.remitted`: `Dr 47x (organisation:APEE) / Cr 521`, which clears the liability. A **"third-party funds held and remitted" statement** reconciles opening balance + collected − remitted = closing balance per fund per period.

---

## 12. Analytic accounting

### 12.1 The v1 defect

`AnalyticAxis` conflated the **dimension** with the **member** and was a single FK on the line. A canteen expense could therefore be tagged Section=Primary **or** Activity=Canteen, never both — which is the only question anyone actually asks of analytic accounting in a school.

### 12.2 Three entities

**`AnalyticAxis`** (the dimension) — `code`, `name`, `name_fr`, `is_mandatory`, `applies_to_classes` (JSON, e.g. `[6,7]`), `requires_full_allocation`, `display_order`, `is_active`, `is_archived`.

Seeded axes: `SECTION` (nursery/primary/secondary…), `ACTIVITY` (teaching, boarding, transport, canteen, library, administration), `SITE` (campus), `PROJECT` (optional).

**`AnalyticValue`** (the member) — `analytic_axis_id` (RESTRICT), `code`, `name`, `name_fr`, `parent_id` (self, RESTRICT — hierarchical members), `linked_type`/`linked_id` (optional link to a `SchoolSection`, `Route`, `Hostel`), `is_active`, `is_archived`. `UNIQUE(analytic_axis_id, code)`.

**`JournalEntryLineAnalytic`** (the pivot) — `journal_entry_line_id` (RESTRICT), `analytic_axis_id` (RESTRICT), `analytic_value_id` (RESTRICT), `amount` BIGINT SIGNED, `share_bp` BIGINT (basis points, `00-core` §7.2).

```sql
UNIQUE KEY uq_jela (journal_entry_line_id, analytic_axis_id, analytic_value_id),
KEY ix_jela_value (analytic_axis_id, analytic_value_id)
```

### 12.3 Invariants

| # | Invariant | Enforcement |
|---|---|---|
| AN-1 | For each `(line, axis)` present, `Σ amount = line.debit + line.credit` (the line's magnitude) | **In-Action**, via `Money::allocate` largest-remainder from `share_bp` so the sum is conserved by construction; **nightly job** re-asserts |
| AN-2 | For each `(line, axis)`, `Σ share_bp = 1 000 000` (100%) | In-Action |
| AN-3 | A line on an account with `requires_analytic = true` must carry splits on every axis where `is_mandatory = true` **and** the account's class is in `applies_to_classes` | **BEFORE INSERT/UPDATE trigger** on `JournalEntryLine` is not able to see child rows, so this is enforced **in-Action** with a **nightly job** and a **blocking `YearEndChecklist` step** |
| AN-4 | Analytic values may not be archived while referenced by an unclosed fiscal year | In-Action |

`requires_analytic` defaults true for classes **6** and **7** — the P&L — and false elsewhere. A balance-sheet line does not need analytic tagging and forcing it produces noise.

### 12.4 Class 9 — the decision, made explicitly

> **Decision: the product does NOT implement class 9.** The dimensional model above is used instead, and no claim of "class 9 comptabilité analytique" appears in marketing, UI or documentation.

Rationale: class 9 in SYSCOHADA is a **self-balancing mirror system of comptes réfléchis** (90x), where analytic accounts mirror the general accounts and the analytic system balances independently. It is not a tagging scheme. v1 claimed class 9 while implementing tags, which is a claim an auditor will test and the product will fail.

**Instead**, an `AnalyticGeneralReconciliation` report proves the dimensional model ties to the GL, per period:

```
For each axis A, for each account class in {6, 7}:
    GL total  = Σ(debit − credit) over posted+reversed lines on that class
    ANA total = Σ(amount signed) over JournalEntryLineAnalytic for axis A on those lines
    ASSERT GL total = ANA total
```

Any difference is, by AN-1 and AN-3, either an untagged mandatory line or a data fault. The report is a `YearEndChecklist` step and appears on the health page. This is a weaker claim than class 9 and a **true** one.

`05-hr-payroll.md`'s `StaffCostAllocation` (a teacher split across nursery and primary) feeds `analytic_source` on the payroll posting rule, so the per-section analytic P&L is correct without anyone re-keying anything.

---

## 13. Bank and treasury reconciliation

Missing entirely from v1. The **état de rapprochement bancaire** is a standard audit request and a school cannot close a period without one.

### 13.1 Entities

**`BankAccount`** — `chart_of_account_id` (RESTRICT, must be `is_reconcilable`), `journal_id` (RESTRICT), `bank_name`, `agency`, `account_number` *(encrypted, `00-core` §9.5)*, `account_number_blind_index`, `rib`/`iban`, `swift`, `currency` (`XAF`), `opened_on`, `signatories` (JSON, informational), `is_active`, `is_archived`. `UNIQUE(chart_of_account_id)`.

Mobile-money floats are `BankAccount` rows too, pointing at a **552** sub-account with `type = mobile_money` on the journal — the reconciliation mechanics are identical and the operator statement is reconciled exactly as a bank statement is. This is the operational payoff of §1.3.

**`BankStatement`** — `bank_account_id` (RESTRICT), `statement_reference`, `period_start`, `period_end`, `opening_balance`, `closing_balance`, `imported_by`/`imported_at` (RESTRICT), `source` (`manual` | `csv` | `ofx`), `file_sha256`. `UNIQUE(bank_account_id, statement_reference)`.

**`BankStatementLine`** — `bank_statement_id` (RESTRICT), `line_no`, `operation_date`, `value_date`, `label`, `reference`, `debit`, `credit` (both ≥ 0, `CHECK ((debit=0) <> (credit=0))`), `status` (`unmatched` | `matched` | `ignored`), `ignore_reason`. `UNIQUE(bank_statement_id, line_no)`.

**`ReconciliationSession`** — `bank_account_id`, `accounting_period_id` (both RESTRICT), `status` (`draft` | `completed`), `book_balance`, `statement_balance`, `computed_difference`, `completed_by`/`completed_at` (RESTRICT), `report_document_id`. `UNIQUE(bank_account_id, accounting_period_id)` — one session per account per month.

**`ReconciliationMatch`** — `reconciliation_session_id` (RESTRICT), `match_type` (`one_to_one` | `one_to_many` | `many_to_one` | `many_to_many`), `statement_line_ids` (JSON), `journal_entry_line_ids` (JSON), `amount`, `matched_by`, `matched_at`, `is_auto`, `confidence_bp`.

`JournalEntryLine.reconciliation_match_id` is `ON DELETE SET NULL` — unmatching deletes the match, never the ledger line.

### 13.2 Invariants

| # | Invariant | Enforcement |
|---|---|---|
| BR-1 | Within a match, `Σ statement amounts = Σ ledger amounts` (same sign convention) | In-Action |
| BR-2 | A statement line and a ledger line each belong to at most one match | In-Action + `UNIQUE` on the join tables backing the JSON |
| BR-3 | A session may only be `completed` when the état de rapprochement reconciles to zero | In-Action; blocks period close |
| BR-4 | Matched ledger lines may not be reversed without first unmatching | `ReverseJournalEntry` unmatches and records it, mirroring §9.2 step 11 |

### 13.3 The persisted état de rapprochement

Generated per session, **persisted as an immutable PDF** with its own document hash (mirroring §14), showing:

```
Solde du relevé bancaire au 31/07/2026                       4 812 400
  + Encaissements comptabilisés non encore au relevé           350 000
  − Décaissements comptabilisés non encore au relevé          (128 500)
  ± Opérations au relevé non encore comptabilisées              (12 000)   → must be zero at completion
  = Solde comptable au 31/07/2026                            5 021 900
```

The fourth line must be **zero** at completion: anything the bank recorded and the books did not is a real transaction (bank charges, interest, a direct debit) that must be **posted**, not reconciled away. The session offers a one-click "post this statement line" using the `bank.charge.recorded` / `bank.interest.received` posting rules.

**Auto-matching** proposes matches on `(amount, date ± n days, reference substring)` with a confidence score. It **proposes**; a human accepts. Auto-accept above a threshold is a setting, off by default.

---

## 14. Statutory books (AUDCIF Art. 19) — C5

v1 omitted the **livre d'inventaire** entirely and treated the other three as reports. They are not reports. They are legal registers.

### 14.1 `StatutoryBook`

| Field | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `book_type` | ENUM | `livre_journal, grand_livre, balance_generale, livre_inventaire` |
| `fiscal_year_id` | BIGINT | RESTRICT |
| `period_start`, `period_end` | DATE | |
| `generated_at`, `generated_by` | | RESTRICT |
| `page_count` | INT | |
| `first_piece_no`, `last_piece_no` | VARCHAR(40) NULL | |
| `total_debit`, `total_credit` | BIGINT SIGNED | |
| `entry_count`, `line_count` | INT | |
| `file_path` | VARCHAR(500) | |
| `sha256` | CHAR(64) | of the produced PDF |
| `signature` | TEXT | detached signature over the hash, using the instance key (`00-core` §13.5 mechanism) |
| `supersedes_book_id` | BIGINT NULL | self, RESTRICT — a regenerated book **supersedes**, never replaces |
| `is_definitive` | BOOLEAN | true once the covered periods are `hard_locked` |

`UNIQUE(book_type, fiscal_year_id, period_start, period_end, generated_at)`.

**Properties.** Signed, paginated (page N of M on every page, continuous pagination across the period), immutable once written, never regenerated in place. Every generation is logged in `DocumentPrintLog` (`00-core` §14). A regeneration after a correction produces a **new row pointing at the old one**, so the sequence of versions is itself auditable.

### 14.2 What each book contains

| Book | Contents |
|---|---|
| **Livre-journal** | Every entry in chronological order by `date` then `piece_no`, with journal, piece number, date, value date, label, account, partner, debit, credit. **Includes `posted` and `reversed` (L13).** Excludes `draft`. Running totals per page carried forward |
| **Grand livre** | Per account, in code order: opening balance, every movement, running balance, closing balance. Collective accounts additionally print an **auxiliary breakdown per partner** |
| **Balance générale** | Per account: opening debit/credit, period movement debit/credit, closing debit/credit. Totals at every level (class, 2-digit, 3-digit) and a grand total where Σdebit = Σcredit |
| **Livre d'inventaire** | The book v1 omitted. Onto it are **transcribed**: the **Bilan**, the **Compte de résultat**, the **Tableau des flux de trésorerie**, and the **summary of the physical inventory** (stock counts with quantities and valuations, and the fixed-asset inventory). Generated once per fiscal year at close, after the §17 sequence completes |

### 14.3 Cote et paraphe

`SchoolProfile` gains: `books_cote_paraphe_reference`, `paraphe_authority`, `paraphe_date`, `paraphe_document_path`.

*The identity of the authority that cotes and paraphes accounting books in Cameroon (commonly the greffe of the competent tribunal) and whether it is mandatory for a private school under the Système Normal is `NEEDS VERIFICATION`. The fields are captured and printed on the book cover page; the product asserts no legal requirement it cannot cite.*

### 14.4 Documentation du système comptable

AUDCIF requires an entity using computerised accounting to hold a description of its accounting system and procedures. Because a hand-written document drifts from the software within one release, the product **generates it from live configuration**:

`GenerateAccountingSystemDocumentation` produces a dated PDF containing: the chart of accounts as configured (with school extensions marked), the journals and their default accounts, **every active posting rule with its version, condition, lines and effective dates**, the analytic axes and values, the sequence formats, the period-locking configuration, the year-end checklist template, the user roles with accounting permissions, and the software version and database schema version.

Regenerated on demand and automatically at each year-end close, stored with a hash. It cannot drift because nobody writes it.

---

## 15. Retention — AUDCIF Art. 24 (10 years)

> **No accounting record is hard-deleted for 10 years from the end of the fiscal year it belongs to.**

**Enforcement: a global model observer**, registered on an `Immutable10Year` trait, which throws on `deleting` and on `forceDeleting`. Applied to: `JournalEntry`, `JournalEntryLine`, `JournalEntryAttachment`, `Lettering`, `AccountingPeriod`, `FiscalYear`, `ChartOfAccount`, `Journal`, `PostingRule`, `PostingRuleLine`, `StatutoryBook`, `BankStatement`, `BankStatementLine`, `ReconciliationSession`, `ReconciliationMatch`, `Invoice`, `Payment`, `CreditNote`, `Refund`, `SupplierInvoice`, `SupplierPayment`, `PayrollRun`, `PayrollItem`, `DepreciationRun`, `DepreciationSchedule`, `StockMovement`, `Asset`, `AuditLog`, `DocumentPrintLog`.

Additional rules:

- **None of these models use `SoftDeletes`.** A soft-deleted row is an invisible row, and invisibility is what the rule exists to prevent. Where a record must be withdrawn, it is **reversed** (§9) or **archived** (`is_archived`), both of which leave it in the books.
- Backup retention in `08-operations.md` must satisfy the same horizon — a 10-year record on a disk with a 90-day backup policy is not retained.
- A **purge Action** exists for records beyond the horizon. It requires Super Admin, refuses any fiscal year whose `ends_on` is within 10 years of `business_date()`, refuses any year with `dsf_filed_at` set within the horizon, produces a signed archive export before deleting, and is audited.
- The **nightly integrity job** compares live row counts per table per fiscal year against an append-only `LedgerTally` table written at period close. A decrease is a P1 alarm on the health page.

---

## 16. Budget

| Entity | Fields |
|---|---|
| **`Budget`** | `fiscal_year_id`, `academic_year_id` (both RESTRICT), `code`, `name`, `status` (`draft, approved, closed`), `version`, `approved_by`/`approved_at` (RESTRICT), `notes`. `UNIQUE(fiscal_year_id, code, version)` |
| **`BudgetLine`** | `budget_id` (RESTRICT), `account_id` (RESTRICT, must be postable, class 6/7 or class 2 for capex), `analytic_value_id` NULL (RESTRICT), `annual_amount` BIGINT SIGNED, `notes`. `UNIQUE(budget_id, account_id, analytic_value_id)` — with a sentinel `0` for the NULL analytic case, since MySQL UNIQUE ignores NULLs (`04-fees.md` documents the same trap on `FeeStructure`) |
| **`BudgetPhasing`** | `budget_line_id` (RESTRICT), `period_month` DATE, `amount` BIGINT SIGNED. `UNIQUE(budget_line_id, period_month)` |

**Invariants**

| # | Invariant | Enforcement |
|---|---|---|
| B-1 | `Σ BudgetPhasing.amount = BudgetLine.annual_amount` per line | In-Action, using `Money::allocate` when the operator phases by ratio; nightly job |
| B-2 | An `approved` budget is immutable; changes produce `version + 1` | Conditional UPDATE on status (`00-core` §10.4) |
| B-3 | Only one `approved` budget per fiscal year is `is_current` | Generated-column UNIQUE (`00-core` §10.1) |

**Phasing.** A school's spending is violently seasonal — September and January are not one-twelfth each. Default phasing offers `equal`, `academic_calendar` (weighted to term starts), and `manual`.

**Over-budget control.** `ChartOfAccount.budget_control`:

- `none` — no check.
- `warn` — the posting Action computes YTD actual + this entry against YTD phased budget and returns a **warning** in the response; the UI shows it and the operator may proceed. Recorded on the entry as `budget_warning_shown`.
- `block` — posting is refused unless the actor holds `accounting.override_budget`, in which case an override reason is mandatory and audited.

Budget-actual comparison uses the **`posted()` scope** (L13) and takes the same `fiscal_year | academic_year` axis parameter as every other report (§7).

---

## 17. Year-end close (C8)

### 17.1 The ordering defect

v1 §5.7 locked the period and then posted adjusting entries into it — a direct contradiction of its own invariant. The two-stage lock (§5.2) resolves it, and the sequence below is the resolution written out. **Each step is a `YearEndChecklistItem` with its own sign-off.**

### 17.2 The sequence

| # | Step | Lock state | Notes |
|---:|---|---|---|
| 1 | **Soft-lock** all periods of the fiscal year | `open → soft_locked` | Operational modules stop. Accounting continues |
| 2 | **Physical inventory** | soft | Stock count; variances post to 603x (§11.4d). Fixed-asset physical verification. `06-assets-stores.md` |
| 3 | **Cut-off entries** | soft | See §17.4 |
| 4 | **Doubtful-debt review** | soft | Reclassify 4111 → 4161/4162; recognise provisions per `DoubtfulDebtPolicy` (`04-fees.md` C8) |
| 5 | **Depreciation** | soft | Final run for the year. `06-assets-stores.md`. Investment-subsidy release runs in step with it |
| 6 | **Provisions** | soft | Leave provision (`05`), litigation, other |
| 7 | **Trial balance** | soft | §17.9 validation — must pass before proceeding |
| 8 | **Tax provision** | soft | §17.6 |
| 9 | **Closing entry** | soft | §18.1 |
| 10 | **Result appropriation** | soft | §18.3 |
| 11 | **À-nouveaux** into the next year | soft (this year), open (next) | §18.2 |
| 12 | **Livre d'inventaire** and the other three books generated | soft | §14 |
| 13 | **Hard-lock** the fiscal year | `soft_locked → hard_locked` | Irreversible except by Super Admin, and impossible once `dsf_filed_at` is set |

### 17.3 `YearEndChecklist`

**`YearEndChecklist`** — `fiscal_year_id` (RESTRICT, UNIQUE), `status` (`not_started, in_progress, completed`), `started_at`, `completed_at`, `completed_by`.

**`YearEndChecklistItem`** — `year_end_checklist_id` (RESTRICT), `sequence`, `code`, `title`, `title_fr`, `is_mandatory`, `status` (`pending, completed, waived`), `completed_by`/`completed_at` (RESTRICT), `waiver_reason` VARCHAR(500) NULL, `evidence_type`/`evidence_id` (e.g. the `JournalEntry` or report that satisfied it), `validation_result` JSON.

**Invariants**

| # | Invariant | Enforcement |
|---|---|---|
| YE-1 | `FiscalYear` may not move to `closed` while any mandatory item is `pending` | In-Action, under `FOR UPDATE` on the checklist |
| YE-2 | An item may only be `waived` with a reason ≥ 20 characters, by a user holding `accounting.waive_year_end_step` | `CHECK (status <> 'waived' OR waiver_reason IS NOT NULL)` + in-Action |
| YE-3 | Items complete in `sequence` order; a later item cannot complete before an earlier mandatory one | In-Action |
| YE-4 | Automated items (auxiliary reconciliation, analytic reconciliation, trial balance, bank reconciliation, unbalanced-entry check, missing-`dsf_line_code` check, unlettered-item review) **complete themselves only when their validation returns clean** | In-Action; the validation result is stored on the item as evidence |

The waiver list is printed on the closing report. An auditor asked "what did you skip?" gets one page.

### 17.4 Cut-off entries and revenue deferral

The OHADA **principe d'indépendance des exercices** requires that each exercice bears its own charges and products. For a school this is not marginal:

> A September invoice for the full academic year recognises 100% of tuition revenue in exercice N, while roughly **60% of the teaching is delivered in N+1**. The 31 December result, the IS base and the minimum-tax base are all materially overstated.

`04-fees.md` owns `InvoiceLine.service_period_start/end`, `FeeItem.recognition_method` and `RevenueRecognitionSchedule`. This document owns the posting:

**Deferral at 31 December** — event `revenue.deferral.recognized`, journal `OD`:

| Account | Debit | Credit |
|---|---:|---:|
| **706xx** Scolarité | 62 400 000 | |
| **477** Produits constatés d'avance | | 62 400 000 |

**Reversal on 1 January** — event `revenue.deferral.reversed`, dated the first day of the new exercice, posted by the à-nouveaux step:

| Account | Debit | Credit |
|---|---:|---:|
| **477** Produits constatés d'avance | 62 400 000 | |
| **706xx** Scolarité | | 62 400 000 |

Symmetrically, prepaid charges (insurance, rent, licences paid in advance) defer to **476** *Charges constatées d'avance*, and services received but not invoiced accrue to **408 / 4818** (`03-tax-procurement.md`), while services delivered but not invoiced accrue to **4181** *Clients, factures à établir*.

**Worked deferral calculation.** Annual tuition invoiced 1 September 2026, 350 000 FCFA, service period 1 Sep 2026 – 31 Jul 2027 = 334 days. Days in exercice 2026: 1 Sep – 31 Dec = 122 days. Days in 2027: 212.

```
Recognised in 2026 = 350 000 × 122 / 334 = 127 844.31…  → 127 844 FCFA
Deferred to 2027   = 350 000 − 127 844   = 222 156 FCFA
```

The deferred figure is computed as `total − recognised`, **never independently rounded** (`00-core` §7.3), so the two always sum to the invoice exactly. Across 1 200 students the schedule is generated per invoice line and aggregated into one deferral entry per revenue account, with the per-line detail retained on `RevenueRecognitionSchedule` for the working paper.

### 17.5 Doubtful debts

Reclassification, per partner, driven by the aging of unlettered items (§10.5):

| Account | Partner | Debit | Credit |
|---|---|---:|---:|
| **4162** Créances douteuses | student:4412 | 350 000 | |
| **4111** Clients | student:4412 | | 350 000 |

Provision, at the policy percentage for the bucket:

| Account | Debit | Credit |
|---|---:|---:|
| **6x** Dotations aux dépréciations des créances ⚠️ | 175 000 | |
| **49x** Dépréciations des comptes de tiers ⚠️ | | 175 000 |

> ⚠️ **491** is flagged `NEEDS VERIFICATION` in `04-fees.md` C8, and the matching 65x/659 dotation account with it. **Not seeded.** The provision run refuses to execute until both accounts are configured. `DoubtfulDebtPolicy` (aging bucket → provision %) is owned by `04-fees.md`; the provision is reversed at the opening of the following exercice and re-assessed, so it never compounds.

### 17.6 Income tax — compte 89

**89** *Impôts sur le résultat*, with **891**, **892**, **895**, **899**.

| Account | Debit | Credit |
|---|---:|---:|
| **891** Impôt sur les bénéfices *(subdivision per the seeded 89 tree)* | X | |
| **441** État, impôt sur les bénéfices ⚠️ | | X |

Monthly **acompte d'IS based on turnover** is owned by `03-tax-procurement.md` for its rate and computation; the ledger treatment is an advance recorded as a receivable/prepayment against the eventual liability, cleared at the annual liquidation.

> ⚠️ The exact 44x subdivision for the corporate-income-tax liability, the acompte rate, and the minimum-tax mechanism are **`NEEDS VERIFICATION`** and owned by `03`. **Nothing here is seeded.** The turnover base for the acompte is why §11.8 and `04-fees.md` C5 matter: agent-collected APEE and exam fees must never enter class 7, or the school computes and pays an acompte on money that is not its revenue.

### 17.7 Notes annexes

The **Notes annexes** are approximately **37 standardized notes** under the Système Normal, feeding the DSF. v1 listed "notes annexes" as one word among five reports and gave it no phase.

`Note` — `note_number`, `title`, `title_fr`, `statement_section`, `data_source` (`derived` | `manual` | `hybrid`), `template`, `is_mandatory`.
`NoteInstance` — `fiscal_year_id` (RESTRICT), `note_id` (RESTRICT), `status` (`pending, drafted, validated`), `content` JSON, `prepared_by`, `validated_by`. `UNIQUE(fiscal_year_id, note_id)`.

Derived notes (fixed-asset movements, depreciation movements, provisions, receivables aging, payables aging, personnel numbers, treasury) are **generated from the ledger** and are not re-keyed. Manual notes are drafted by the accountant. Completing all mandatory notes is a `YearEndChecklistItem`.

> *The exact count and numbering of the notes annexes under the current AUDCIF Système Normal is `NEEDS VERIFICATION` against the official DSF form. The `Note` reference table ships **empty** and is populated once with the accountant (blocking gate 6). "~37" is the working figure from the brief and is not seeded as a count.*

### 17.8 Segregation of duties at close

Period closure, depreciation runs, provision runs and the closing entry all require **maker-checker** (§20). The user who runs a step may not be the user who signs it off on the checklist.

### 17.9 The trial-balance validation

Step 7 is not "print the balance". It is a validation Action returning a pass/fail set:

| Check | Fail condition |
|---|---|
| Global balance | `Σdebit ≠ Σcredit` across all posted+reversed entries |
| Per-entry balance | any entry where `Σ lines ≠ total_debit`/`total_credit` |
| L8 partner integrity | any collective line with no partner, or non-collective line with one |
| L9 auxiliary reconciliation | any collective account where Σ auxiliary ≠ GL |
| L10 lettering | any `full` group where Σdebit ≠ Σcredit |
| AN-3 analytic | any mandatory-axis line with no split |
| L7 sequence | any `(journal, fiscal_year)` where `COUNT(*) ≠ MAX(piece_no)` |
| Bank reconciliation | any `is_reconcilable` account with an incomplete session for any period |
| DSF mapping | any postable class 1–8 account with a null `dsf_line_code` |
| Suspense | any non-zero balance on a suspense/waiting account |
| Draft entries | any `draft` entry dated inside the year — must be posted or discarded, never left |

Every failure is actionable and links to the offending rows. The step cannot self-complete until all pass.

---

## 18. Closing, appropriation, à-nouveaux, and migration

### 18.1 Closing entry

Event `year_end.closing`, journal `CL`, dated `fiscal_year.ends_on`. All class **6** and **7** accounts are closed against **13** *Résultat en instance d'affectation*:

| Account | Debit | Credit |
|---|---:|---:|
| Every class 7 account with a credit balance | Σ credit balances | |
| Every class 6 account with a debit balance | | Σ debit balances |
| **13** Résultat en instance d'affectation | *balancing* | *balancing* |

Class **8** (HAO — including the 81/82 disposal lines of §11.6) closes to 13 in the same entry. After it, **classes 6, 7 and 8 are zero**, which is the precondition for §18.2.

### 18.2 À-nouveaux

Event `year_end.opening_balances`, journal `AN`, dated `next_fiscal_year.starts_on` (1 January).

> **The à-nouveaux carries forward classes 1–5 only.** Classes 6, 7 and 8 are zero after closing and are not carried. The result sits in **13** and is routed to **11** *Report à nouveau* / **12** *Résultat net* on appropriation.

Rules:

- One AN entry per fiscal year, per journal `AN`. `UNIQUE(fiscal_year_id)` on a `fiscal_year.opening_entry_id` back-reference.
- **Partner detail is preserved.** Every collective-account balance is carried as **one line per partner**, not as a single collective total. Carrying a lump 4111 balance destroys the auxiliary ledger on 1 January of every year and makes L9 unprovable for the new exercice. For a 1 200-student school this is a several-thousand-line entry; that is correct and expected.
- Lettering is **not** carried across the year boundary as groups, but each carried partner line retains its `due_date`, so aging survives the boundary.
- The AN entry is generated by an Action and is **not** editable. A correction is a reversal plus a fresh entry, like anything else.
- The AN is posted into the new year **before** it is opened for operational posting, so no user-entered entry ever precedes it in `piece_no` order.

### 18.3 `ResultAppropriation`

| Field | Type |
|---|---|
| `fiscal_year_id` | BIGINT, RESTRICT, **UNIQUE** |
| `decision_body` | VARCHAR(120) — AGM, board, sole proprietor |
| `decision_date` | DATE |
| `resolution_reference` | VARCHAR(120) |
| `result_amount` | BIGINT SIGNED — from compte 13 |
| `status` | ENUM `draft, approved` |
| `approved_by`, `approved_at` | RESTRICT |
| `journal_entry_id` | RESTRICT |
| `document_path`, `document_sha256` | the minutes |

**`ResultAppropriationLine`** — `result_appropriation_id` (RESTRICT), `account_id` (RESTRICT), `amount` BIGINT SIGNED, `label`. Targets are legal reserve, other reserves, **11** report à nouveau, distributions, and any statutory allocation.

**Invariant AP-1:** `Σ ResultAppropriationLine.amount = ResultAppropriation.result_amount`, enforced in-Action; the posting empties **13** exactly.

Event `year_end.appropriation`, journal `OD`:

| Account | Debit | Credit |
|---|---:|---:|
| **13** Résultat en instance d'affectation | X | |
| **11** Report à nouveau / reserves / distributions | | X (split per lines) |

*The legal-reserve percentage and any statutory allocation applicable to the school's legal form (SARL, association, établissement privé) are `NEEDS VERIFICATION` and not seeded. The appropriation is entered by the accountant from the actual resolution.*

### 18.4 Opening balances and historical migration

A school does not adopt the product on 1 January. It adopts in March, mid-exercice, with a live trial balance and 1 200 open student balances. v1 left this entirely undesigned, which means the first customer could not be onboarded.

**`OpeningBalanceImport`**

| Field | Type |
|---|---|
| `id` | BIGINT PK |
| `fiscal_year_id`, `academic_year_id` | RESTRICT |
| `as_of_date` | DATE — the cut-over date |
| `status` | ENUM `draft, validated, committed, rejected` |
| `source_file_path`, `source_sha256` | |
| `declared_total_debit`, `declared_total_credit` | BIGINT SIGNED — **from the trial balance the school's accountant supplies** |
| `computed_total_debit`, `computed_total_credit` | BIGINT SIGNED |
| `journal_entry_id` | NULL until committed, RESTRICT |
| `imported_by`, `validated_by`, `committed_by` | RESTRICT |

**`OpeningBalanceLine`** — `opening_balance_import_id` (RESTRICT), `row_no`, `account_code`, `resolved_account_id` NULL, `partner_type`, `partner_external_ref`, `resolved_partner_id` NULL, `debit`, `credit`, `due_date`, `validation_status`, `validation_message`.

**The validation gate.** The import may not commit until, in one pass:

| # | Check |
|---|---|
| OB-1 | Every `account_code` resolves to a postable, non-archived `ChartOfAccount` |
| OB-2 | `Σ debit = Σ credit` across all lines |
| OB-3 | `computed_total_debit = declared_total_debit` **and** `computed_total_credit = declared_total_credit` — **the import is validated against the trial balance the accountant supplied**, not merely against itself. An internally-balanced import of the wrong numbers balances perfectly |
| OB-4 | Every line on a collective account carries a resolvable partner (L8), and every partner reference resolves to an existing Student / Supplier / StaffMember / Organisation |
| OB-5 | Per collective account, Σ per-partner balances equals the declared collective balance (L9, proven before commit rather than discovered at the first close) |
| OB-6 | Classes 6, 7, 8 may carry balances **only** for a mid-exercice cut-over, and then only where the accountant explicitly confirms the year-to-date figures; a 1-January cut-over rejects any 6/7/8 line |

On commit, **one balanced journal entry** is produced in journal `AN`, dated `as_of_date`, with `is_migration = true`, carrying full partner detail.

**`JournalEntry.is_migration`** does three things: it **suppresses posting-rule evaluation** (migrated invoices must not re-trigger `fee.invoice.issued` and post a second time), it **suppresses downstream domain events** (no dunning SMS for a two-year-old migrated balance), and it **marks the entry on every report** so an auditor can distinguish opening position from in-system activity. Migrated source documents (`Invoice`, `SupplierInvoice`) carry a matching `is_migration` flag for the same reason.

`08-operations.md` owns the import suite mechanics (Phase 2); this document owns the accounting validation above.

---

## 19. Currency — the decision (`currency_amount`)

v1 had a `currency_amount` column appearing once, with no currency code, no rate, no FX gain/loss accounts and no revaluation policy. That middle state must not ship. Two options were on the table; **the decision is made here.**

> ### Decision: the ledger is XAF-only.
>
> Every `JournalEntryLine.debit` and `.credit` is whole FCFA (`00-core` §7.1). `ChartOfAccount.currency` is `CHECK (currency = 'XAF')`. **The `currency_amount` column is deleted.** No FX gain/loss accounts are seeded, no revaluation job exists, and no report offers a currency filter.

**Where foreign currency genuinely occurs** — a foreign teacher's salary agreed in EUR, imported laboratory equipment invoiced in USD — the **source module** retains the original currency and the rate:

| Entity | Fields (owned by the source module) |
|---|---|
| `SupplierInvoice` (`03`) | `original_currency`, `original_amount`, `exchange_rate_bp`, `rate_source`, `rate_date` |
| `StaffContract` (`05`) | `agreed_currency`, `agreed_amount`, `conversion_policy` |
| `Asset` (`06`) | `original_currency`, `original_amount`, `exchange_rate_bp` |

Conversion to XAF happens **once**, in the source module's Action, at a rate captured with its source and date, and only the XAF result reaches the ledger. The original figures are printed on the source document and available for query; they are not ledger data.

**Rationale.** The XAF is pegged to the euro at a fixed parity, and the volume of genuinely foreign-currency transactions in a Cameroonian school is a handful per year. Implementing a full multi-currency ledger — daily rates, monthly revaluation of monetary items, realised and unrealised FX gain/loss accounts, and a translation policy — would add substantial machinery, several new invariants and a whole class of reconciliation failure, to serve a case a manual conversion handles correctly.

**Consequence, stated so it is not discovered later.** If a school later needs a multi-currency ledger, that is a schema change and a migration, not a configuration flag. The decision is recorded here with its reasoning so a future reader can reverse it deliberately.

*The exact XAF/EUR parity is not seeded as a constant anywhere in the accounting module; where a conversion is needed the operator supplies the rate and its source.*

---

## 20. Segregation of duties

v1 had none. The specific gaps: no maker-checker on journal entries, none on depreciation runs, none on period closure, and the person who recorded a payment could void it.

### 20.1 The matrix

| Operation | Maker | Checker | Rule |
|---|---|---|---|
| Manual journal entry (journals with `requires_maker_checker`) | Accountant | Accountant or Administrator | `created_by ≠ approved_by`, enforced in-Action and by `CHECK (approved_by IS NULL OR approved_by <> created_by)` |
| Posting a draft entry | Accountant | — | Requires `accounting.post` |
| Reversal | Accountant | Administrator where the target exceeds a configurable threshold | reason mandatory |
| Depreciation run | Accountant | Administrator | `06-assets-stores.md` owns the run; approval is here |
| Provision run | Accountant | Administrator | |
| Period soft-lock | Accountant | — | |
| Period hard-lock | Accountant | Administrator or Principal | |
| Period unlock | — | Administrator (soft) / Super Admin (hard) | reason mandatory, audited |
| Fiscal-year closure | Accountant | Principal | checklist must be complete |
| Posting-rule create/activate | Accountant | Administrator | dry-run preview mandatory before activation |
| Chart-of-account extension (5+ digits) | Accountant | — | |
| Opening-balance import commit | Accountant | Administrator | OB-1…OB-6 must pass |
| **Payment void** | — | **A user other than the one who recorded it** (`04-fees.md`) | after cash-desk close, requires elevated permission |
| Budget approval | Accountant | Principal or Administrator | |
| Result appropriation | Accountant | Principal | resolution document required |

### 20.2 Enforcement

The check is **in the Action**, against `00-core` §9.1 roles and `spatie/laravel-permission`, and it is **also** a database CHECK wherever both actor columns live on the same row. Every refusal is audited with the attempted operation, so an attempt to self-approve is itself a finding.

A single-user school will hit this immediately. The setup wizard therefore requires **at least two users with accounting permissions** before the ledger opens, and explains why. A "solo mode" that disables maker-checker is **not offered** — it would be the default within a week at every site.

---

## 21. Reports, screens, and tests

### 21.1 Statutory outputs

Livre-journal · Grand livre (with auxiliary detail) · Balance générale · **Livre d'inventaire** · Bilan · Compte de résultat · Tableau des flux de trésorerie · **Notes annexes (~37)** · État de rapprochement bancaire per account per period · Balance auxiliaire clients · Balance auxiliaire fournisseurs · Documentation du système comptable · **DSF** (owned by `03-tax-procurement.md`).

### 21.2 Management outputs

Trial balance (any date, any axis) · Aged receivables and aged payables from unlettered items · Unlettered items · Third-party funds held and remitted · Budget vs actual with variance and phasing · Analytic P&L per axis and per value · Analytic-to-GL reconciliation · Cash and treasury position · Cash-desk variance by cashier · Forward-posted entries (§5.4) · Sequence-gap report · Reversal register · Journal-entry search · Posting-rule catalogue with versions · Year-end checklist status and waiver list · Auxiliary reconciliation history · Missing-pièce report.

Every report takes an explicit **axis** (`fiscal_year` | `academic_year`), an explicit **`as_of`** where it is a balance, prints both in its header, and queries through the `posted()` scope (L13).

### 21.3 Screens

**Finance Dashboard** (`finance dashboard.png`). KPI row — Total Revenue, Total Invoices, Total Payments, Outstanding Amount, Collection Rate, each with a period-over-period delta. Income & Expenses overview chart with tabs (Income by Category, Expense by Category, Monthly Collection Trend). Fee Collection Summary donut (Collected / Outstanding / Overdue). Income by Category bars. Recent Transactions table (Date, Receipt No., Description, Student/Client, Amount, Type, Payment Method, Status). Top Outstanding Invoices. Quick Actions. Notifications.

Two rules the mockup does not show but the ledger requires:

1. Every KPI states its **axis** — the mockup's "This Academic Session" is an academic-year axis, and the same screen must be switchable to fiscal year, because the two answers differ by a full term and a bursar reading the wrong one will misreport to the proprietor.
2. Every KPI is computed through the `posted()` scope. The dashboard is the single most likely place for someone to write `where('status','posted')` by hand; the arch test in §21.4 covers it.

**Expense capture** — the screen v1 never described, having routed all money through journals and never explained how an operator records that the school paid 125 000 FCFA cash for library books.

`Expense` is a light document over the ledger: `expense_no` (own series), `date`, `payee_type`/`payee_id` (supplier, staff, other) or free-text `payee_name`, `description`, lines each carrying `account_id` (class 6 or class 2 for capex), `analytic_value_id`, `amount`, `tax_code_id`; `payment_method_id` and its treasury account; `attachment` (mandatory — a receipt photo satisfies AUDCIF Art. 17 and L15); `status` (`draft, submitted, approved, posted`); `submitted_by`, `approved_by` (**maker-checker above a configurable threshold**); `journal_entry_id`.

Posting event `expense.recorded`:

| Account | Debit | Credit |
|---|---:|---:|
| **6xx** per line | 125 000 | |
| **57** Caisse / **521** Banque / **552** MoMo | | 125 000 |

Where the expense is a capex purchase, the line targets class 2 and emits `asset.acquired`, creating the `Asset` and the ledger entry **in one Action** (`06-assets-stores.md`). Where the payee is a registered supplier, the Procurement flow (`03`) is used instead and the expense screen redirects — the expense screen exists for the petty, unregistered, cash-and-receipt case, which is most of a school's day.

Also specified: **Journal Entry screen** (keyboard-first line grid, running debit/credit/difference indicator that must read zero before Post is enabled, account autocomplete on code and label, partner picker appearing only for collective accounts, analytic split panel, attachment drop zone, Save Draft / Post) · **Chart of Accounts explorer** (tree, balances, `is_system` lock badge, extension dialog constrained to 5+ digits under the selected parent) · **Period and year-end console** (period grid with lock states, checklist with sign-offs and waivers, validation results) · **Bank reconciliation workspace** (two-pane statement/ledger, drag-to-match, auto-match proposals with confidence, running difference, Post-this-line action) · **Posting rule editor** (line builder, expression editor with variable autocomplete from the event schema, dry-run preview, version history).

### 21.4 The test suite that keeps this document true

| Test | Asserts |
|---|---|
| **Wrong-code regression** | The seeder contains no ICT category pointing at 2441; no account labelled or aliased "tuition"/"scolarité" at code `7061`; no treasury account for mobile money in the 52 range; no seeded account `865` |
| **System-account immutability** | Attempting to update `code`, `name` or `name_fr` on any `is_system` account raises |
| **L2 property test** | For 10 000 randomly generated multi-line entries, `Σdebit = Σcredit` after `Money::allocate` and the balancing line |
| **L3 trigger test** | Direct SQL insert/update/delete of a line under a posted entry raises SQLSTATE 45000 |
| **L8 trigger test** | Both directions — collective without partner, non-collective with partner |
| **L13 reversal test** | Post → reverse → every statement, book, balance and dashboard KPI is byte-identical to pre-post, and the grand livre shows both movements |
| **Double-reversal test** | Reversing a reversed entry raises; reversing a reversal raises |
| **L7 gapless test** | A rolled-back posting consumes no `piece_no`; 500 concurrent postings produce 1…500 with no gap and no duplicate |
| **Forward-posting test** | An entry dated into a hard-locked period lands on the first day of the first open period with its original `value_date` preserved and `is_forward_posted = true` |
| **Fiscal-year validation test** | A Sept–Aug fiscal year is rejected; an irregular first exercice ending 31 December is accepted |
| **Dual-calendar test** | Every entity in §7 rejects a date outside either referenced year |
| **Auxiliary reconciliation test** | 1 200 student balances; Σ auxiliary = collective, before and after à-nouveaux |
| **Lettering test** | A group cannot be marked `full` unbalanced; a payment void unletters |
| **Analytic test** | Splits sum to the line for 10 000 random ratio vectors (largest-remainder conservation) |
| **Inventory scheme test** | The three-entry sequence of §11.4 leaves 601 at 100 000, 6031 at +25 000 net debit, and 31 at 75 000 |
| **Disposal test** | §11.6 produces non-zero balances on **both** 81 and 82, and `gain_or_loss` is not a stored column anywhere |
| **Retention test** | `delete()` and `forceDelete()` throw on every model in §15 |
| **Arch test** | No statement/report/book file filters on `journal_entries.status` directly; no `FLOAT`/`DECIMAL` money or rate column; no posting expression reaches `eval` |
| **Documentation drift test** | `GenerateAccountingSystemDocumentation` output contains every active posting rule and every school-extended account |

---

## 22. Open items requiring verification before Phase 4 ships

Consolidated. Each is `NEEDS VERIFICATION`, ships **empty**, and its dependent feature **refuses to run until configured** (`00-core` §16, blocking gate 6).

| # | Item | Blocks |
|---:|---|---|
| 1 | 707x subdivision for boarding / transport / canteen / misc | Fee item → revenue account mapping |
| 2 | 5-digit tuition extensions under 706 | Same |
| 3 | 631x subdivision for mobile-money commission | Mobile-money payment method |
| 4 | Cash shortage / overage accounts (brief proposes 658 / 758, unverified) | Cash-desk variance posting |
| 5 | 491 provision account + its 65x dotation counterpart | Doubtful-debt provisioning |
| 6 | 845 subsidy-release account (**865 is wrong — never seed it**) | Donated-asset subsidy release |
| 7 | 151 amortissements dérogatoires | Fiscal-vs-accounting divergence |
| 8 | 106 écart de réévaluation | Asset revaluation |
| 9 | 428x leave provision | Payroll leave provision |
| 10 | 43x / 44x subdivisions per statutory branch (CNPS, IRPP, CAC, CFC, FNE, TDL) | Payroll posting |
| 11 | 44x corporate income-tax liability subdivision; acompte rate; minimum-tax mechanism | Tax provision |
| 12 | Full DSF line mapping for every postable account | Fiscal-year closure (CoA-7) |
| 13 | Notes annexes — exact count, numbering, content per note | Year-end close |
| 14 | Whether AUDCIF Art. 22 fixes a deadline for the clôture informatique beyond "at least quarterly" | Forced-closure default (operational default shipped, labelled) |
| 15 | Whether AUDCIF permits a first exercice exceeding 12 months | First-exercice validation (product refuses > 12 months) |
| 16 | Cote-et-paraphe authority and whether it is mandatory for a private school | Statutory book cover page |
| 17 | Système Normal / SMT turnover thresholds | Setup wizard regime check |
| 18 | Legal-reserve percentage by legal form | Result appropriation |
| 19 | Whether Cameroon's LPF imposes an FEC-style dematerialised accounting-file submission during audit | A potential additional statutory export (`03-tax-procurement.md`) |

**None of the above is guessed. A wrong value that looks authoritative is worse than an empty field** — `00-core` §16.
