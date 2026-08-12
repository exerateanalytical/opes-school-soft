# Finance & Accounting — Layered Architecture and Surface Completion

Date: 2026-08-12
Status: proposed
Relationship to existing specs: **additive**. `docs/specs/02-accounting.md`
remains the sole authority for the ledger engine, the SYSCOHADA chart of
accounts, posting rules, period control, lettering, reversal semantics and
year-end close. Nothing in that document is superseded, reworded or reopened
here. This spec covers the layers built **on top of** that engine, and the
surfaces that do not yet exist.

Companion authorities: `03-tax-procurement.md` (tax, DSF, procurement),
`04-fees.md` (fee billing), `05-hr-payroll.md`, `06-assets-stores.md`,
`09-ui.md` (shell and navigation), `00-core.md` §16 (blocking configuration
gates).

---

## 0. Why this document exists

A review proposed restructuring Finance around an ERP-industry-standard menu
(Sage/QuickBooks-shaped): a generic chart of accounts, an "Income Statement /
Balance Sheet / Statement of Changes in Equity" statement set, and ~160 screens
across 20 sections.

Two findings came out of auditing that proposal against the codebase.

**First, the engine it asked for already exists.** `02-accounting.md` is
SYSCOHADA-native from the ground up, and it independently arrived at the same
governing principle the review proposed formalising (§22: *"None of the above is
guessed. A wrong value that looks authoritative is worse than an empty field."*).
Rebuilding it would risk losing three verified account-code corrections (§1) and
19 catalogued verification gates (§22). It is extended here, not rebuilt.

**Second, the proposal's illustrative artifacts were Anglo-American and would
have corrupted the books had they been implemented.** They are recorded in §1.2
as rejected, so that a future session does not reintroduce them.

What is genuinely absent is the upper layers: the review surfaces, the control
centre, the traceability UI, the grouped navigation, and an operator-facing
documentation page. That is the build described here.

---

## 1. Governing principles

### 1.1 The configuration-integrity rule (binding)

> **No invented statutory account codes, tax mappings, DSF mappings,
> financial-statement mappings or regulatory fields. Every authoritative
> accounting configuration must be sourced, versioned and validated before it
> becomes system data.**

A field may legitimately read **Not configured** indefinitely. Where a mapping is
absent, the dependent feature **refuses to run** and says which gate blocks it
(`00-core.md` §16). It never falls back to a plausible default, and it never
renders a partial statement as though it were complete.

This rule already governs the 19 open items in `02-accounting.md` §22. This spec
extends it to every mapping surface it introduces, and adds the enforcement test
in §9.2.

### 1.2 Rejected artifacts — do not reintroduce

The following appeared in the architecture review and are **wrong for this
system**. They are recorded so the error is not repeated.

| Rejected | Why | Correct under SYSCOHADA révisé |
|---|---|---|
| `101100 Main Bank` (Asset) | Class 1 is capital and long-term resources, not treasury | Bank is `521`; see `02-accounting.md` §1.3 — mobile money is `55`, not a bank account |
| `401100 Student Receivables` (Asset) | Class 40 is *fournisseurs* — payables, not receivables | Student receivables are class 41 (*clients*), via the collective/auxiliary mechanism of `02-accounting.md` §8 |
| `401200 Supplier Payables` | Collides with the row above; two different natures on one root | Suppliers are class 40 |
| `411100 School Fees` (Revenue) | Class 41 is a receivable, never revenue | Revenue is class 7 — `706`/`707`, and the 4th digit of `706x` encodes geography, not service type (`02-accounting.md` §1.2) |
| "Statement of Changes in Equity" as a core statement | Not part of the SYSCOHADA système normal presentation | See §1.3 |

The deeper lesson: the chart of accounts feeds **Journal → Grand livre → Balance
→ états financiers → Notes → DSF**. A wrong classification at the root is not a
cosmetic problem; it is wrong statutory reporting.

### 1.3 The statutory statement set

The SYSCOHADA révisé système normal presentation is exactly:

```
Bilan
Compte de résultat
Tableau des flux de trésorerie
Notes annexes
```

No IFRS-style statement is presented as statutory. An IFRS or management
reporting layer may be built **on top of** the ledger (§8), never mixed into it.

**The number of Notes annexes is not hard-coded.** Published references vary
(implementations describing 36 notes, and OHADA reference material describing 46
tables). The count, numbering and per-note content therefore remain
`02-accounting.md` §22 gate 13 — a **versioned mapping loaded from the
authoritative specification in force**, not a constant in code. Where the mapping
is unloaded, the Notes annexes output refuses to render and names the gate.

---

## 2. The four layers

```
                        OPES ACCOUNTING
                              │
                ┌─────────────┴─────────────┐
                │                           │
          SUBLEDGERS                  GENERAL LEDGER
                │                           │
        ┌───────┼────────┐                  │
        │       │        │                  │
       AR      AP     Payroll         SYSCOHADA CoA
        │       │        │                  │
        └───────┼────────┘                  │
                │                           │
                └───────────┬───────────────┘
                            │
                     TRIAL BALANCE
                            │
             ┌──────────────┼───────────────┐
             │              │               │
          BILAN     COMPTE DE RÉSULTAT   FLUX
             │              │               │
             └──────────────┼───────────────┘
                            │
                     NOTES ANNEXES
                            │
                     STATUTORY / DSF
```

### 2.1 Existence audit

Assessed against the working tree on 2026-08-12. This is the honest baseline —
the build in §10 targets only the `MISSING` and `THIN` rows.

**Layer 1 — SYSCOHADA accounting engine**

| Capability | State | Evidence |
|---|---|---|
| SYSCOHADA chart of accounts, classes, hierarchy | **BUILT** | `ChartOfAccount`; invariants enforced by DB triggers, `account_class`/`depth` are stored generated columns |
| Journals, journal types | **BUILT** | `Journal`, `Domain\JournalType` |
| General ledger, double entry | **BUILT** | `JournalEntry`, `JournalEntryLine`; Σdebit = Σcredit enforced by trigger, not app code |
| Auxiliary ledgers (comptes de tiers) | **BUILT** | `02-accounting.md` §8, invariant L8 enforced both directions |
| Trial balance | **BUILT** | `Livewire\Reports\TrialBalance` |
| Accounting periods, two-stage lock | **BUILT** | `AccountingPeriod`, `Domain\AccountingPeriodStatus` |
| Fiscal years, dual calendar | **BUILT** | `FiscalYear`, `02-accounting.md` §7 |
| Opening balances, à-nouveaux | **BUILT** | `Actions\YearEnd\PostOpeningBalances`, `ImportOpeningAuxiliaryBalances` |
| Closing entries, result appropriation | **BUILT** | `ResultAppropriation`, `Actions\YearEnd\PostYearEndClosingEntry` |
| Reversals and corrections | **BUILT** | `02-accounting.md` §9; reversal-only, double-reversal refused |
| Lettering / allocation | **BUILT** | `Lettering`, auto-lettering, unlettering on void |
| Posting rules (operational → ledger) | **BUILT** | `PostingRule`, `PostingRuleLine`, versioned; event catalogue §11.2 |
| Analytic accounting (cost centres, dimensions) | **BUILT** | `AnalyticAxis`, `AnalyticValue`, `JournalEntryLineAnalytic`; largest-remainder conservation |
| Suspense / clearing accounts | **BUILT (engine)** | Used by `ImportOpeningAuxiliaryBalances`; **no review surface** — see §4 |
| Retention, immutability | **BUILT** | `Support\Retention\Immutable10Year`, AUDCIF Art. 24 |
| **Recurring journals** | **MISSING** | No model, no action, no screen |
| **Accruals / prepayments as first-class documents** | **THIN** | Cut-off entries specified (`02-accounting.md` §17.4); no operator surface |

**Layer 2 — School subledgers**

| Cycle | State | Evidence |
|---|---|---|
| AR posting chain (Fee structure → invoice → receipt → allocation → treasury → GL) | **BUILT** | Fees module + posting rules; worked examples §11.3, §11.8 |
| AR **operator surfaces** (aging, statements, collections, credit/debit notes, refunds, write-offs) | **MISSING (screens only)** | The computation exists — `Fees\Actions\AgedBalances`. `Fees\Livewire` has only Invoices, Cashier, CashDesk, Statement, Reports, so nothing surfaces it. Slice 4 **reuses that Action** and must not reimplement the buckets |
| AP posting chain (PR → PO → GRN → bill → payment → bank → GL) | **BUILT** | Procurement module, 15 screens incl. `PayablesDashboard` |
| AP surfaces (supplier statements, aging, payment requests) | **THIN** | `PayablesDashboard` exists; no aging buckets, no supplier statement document |
| Payroll → liabilities → payment → GL | **BUILT** | `05-hr-payroll.md`; worked example §11.7 |
| Fixed assets → depreciation → GL | **BUILT** | `RunDepreciation`, `ApproveDepreciationRun`, `PostDepreciationRun`, `RevalueAssets`, `ImpairAsset`, `DisposeAsset`, `ClawBackSubsidy` |
| Inventory → GL | **BUILT** | Worked example §11.4 |

**Layer 3 — SYSCOHADA reporting engine**

| Output | State |
|---|---|
| Bilan, Compte de résultat, Tableau des flux | **BUILT** (`Livewire\Statements\Index`, `FinancialStatementBalances`) |
| Notes annexes | **BLOCKED** by §22 gate 13 — correct behaviour per §1.3 |
| Statutory books (AUDCIF Art. 19) | **BUILT** (`StatutoryBook`, `Livewire\Books\Index`) |
| Documentation du système comptable | **BUILT** (`SystemDocumentationSnapshot`) — statutory artifact, **not** the operator guide of §7 |
| **Statement → line → account → journal → source drill-down** | **MISSING (UI only)** | see §6 |

**Layer 4 — Tax / DSF / statutory**

| Capability | State |
|---|---|
| Tax configuration, declarations, withholding, filing history | **BUILT** (Tax module, 6 screens) |
| DSF line mapping per postable account | **BLOCKED** by §22 gate 12 — correct behaviour |
| Separation of tax engine from ledger | **BUILT** — `Tax` is its own module; `ChartOfAccount.dsf_line_code`/`dsf_statement` are mapping columns, not ledger logic |

### 2.2 What the audit means

The engine is not the gap. Of the review's 20 proposed sections, the substantial
majority are already implemented, and several (depreciation runs, analytic
conservation, posting-rule versioning, trigger-enforced balance) are **more
rigorous** than the proposal assumed.

The gap is concentrated in five places, and that is the whole of this build:

1. **Accounting Review** — no integrity/anomaly subsystem exists (§4).
2. **Finance Control Centre** — the dashboard answers the wrong questions (§5).
3. **Traceability UI** — the data supports drill-down; nothing walks it (§6).
4. **AR/AP operator surfaces** — reports specified, screens absent (§10 slice 4).
5. **Navigation and documentation** — flat nav, no operator guide (§7, §8).

---

## 3. Non-goals

- **No new account codes, tax mappings or DSF mappings are seeded.** The 19 gates
  of `02-accounting.md` §22 stand untouched. This build makes them *visible and
  actionable* (§4.4), which is the opposite of filling them in.
- **No change to posting rules, invariants, triggers or retention.**
- **No IFRS statutory presentation.** An IFRS layer is out of scope for this
  cycle; §8 records only the architectural seam that keeps it possible.
- **No online payment gateway** — owned by `04-fees.md`, unchanged here.
- **No rewrite of `02-accounting.md`.**

---

## 4. Accounting Review (new subsystem)

The single largest genuine gap. An accountant currently has no screen that
answers *"do I trust these books right now?"*.

Module placement: `App\Modules\Accounting\Livewire\Review\*`, with supporting
read-model Actions under `App\Modules\Accounting\Actions\Review\*`. It **decides
nothing and posts nothing** — it is a read-only assurance surface over the
existing ledger, in the same way `ChildDirectory` is a read-model over the
guardian matrix.

### 4.1 Control-account reconciliation

For each control pair, the check is a single arithmetic identity computed through
`JournalEntry::scopePostedLedger()` at an explicit `as_of` date and axis. That
scope is the **only** permitted read path: per `02-accounting.md` §9.3 a
statement includes both `posted` **and** `reversed` entries so that a reversal
nets its original to zero. Filtering on `status = 'posted'` alone silently drops
the original of every reversed pair and overstates the books:

| Control | Identity |
|---|---|
| Student receivables ↔ GL | Σ auxiliary client balances = collective 41 balance |
| Supplier payables ↔ GL | Σ auxiliary supplier balances = collective 40 balance |
| Bank ↔ GL | Per account: reconciled statement balance ± timing differences = ledger 521 balance |
| Cash ↔ GL | Cash-desk close totals = 57 balance |
| Electronic money ↔ GL | Settlement records = 55 balance (`02-accounting.md` §1.3) |
| Payroll ↔ GL | Payroll liability schedule = 42x/43x/44x balances |
| Fixed assets ↔ GL | Asset register NBV = class 2 net of amortisation |
| Inventory ↔ GL | Stock valuation = class 3 balance |
| Tax ↔ GL | Declared liabilities = 44x balances |

Each row renders one of: **`✓ Reconciled`** (difference exactly zero),
**`⚠ Difference`** (with the signed amount and a link to the driver detail), or
**`— Not configured`** (a §22 gate blocks the check; the gate is named).

The auxiliary identity (rows 1–2) is already proven by an existing test
(`02-accounting.md` §21.4, "Auxiliary reconciliation test": 1 200 student
balances, Σ auxiliary = collective, before and after à-nouveaux). This subsystem
surfaces that guarantee rather than re-implementing it — the existing Action is
the single implementation.

### 4.2 Analytical review

Detects postings that are *legal but suspicious*. Every rule is advisory: it
flags for human review and never blocks or auto-corrects.

Unusual movements (period-over-period deviation beyond a configurable band) ·
large transactions above a configurable threshold · **negative balances on
accounts whose `normal_balance` forbids them** · dormant accounts with sudden
activity · candidate duplicate postings (same account, amount, partner, and date
within a window) · backdated entries · forward-posted entries
(`02-accounting.md` §5.4) · manual journals in a period dominated by automated
posting.

Thresholds are configuration with shipped defaults that are **labelled as
operational, not regulatory** — they are heuristics for review, so §1.1 does not
apply to them. That distinction is stated on the screen.

### 4.3 Journal review

A worklist over `journal_entries`: unposted · draft · reversed and their
reversals paired · manual (no `posting_rule_id`) · adjusting · forward-posted ·
**sequence-gap report** (invariant L7) · entries missing a pièce justificative
(L15, AUDCIF Art. 17).

### 4.4 Suspense and gate register

Two panels:

- **Suspense/clearing balances** — every account flagged as suspense or clearing
  with a non-zero balance, aged, with the entries that put it there. A suspense
  account should be zero outside a migration window; a non-zero balance is a
  standing exception with an owner.
- **Configuration gate register** — the live status of the `02-accounting.md`
  §22 items: which are configured, which remain `Not configured`, and precisely
  which feature each one blocks. This is how §1.1 becomes visible work rather
  than silent absence.

---

## 5. Finance Control Centre (dashboard)

`Livewire\FinanceDashboard` today is a collection-oriented view built from the
`finance dashboard.png` mockup — revenue, invoices, payments, collection rate.
That is a legitimate **bursar** view and is **retained**, not deleted (repo
constraint: additive only). It becomes one of two tabs.

The new default tab is the accountant's control centre. Every figure carries its
**axis** (`fiscal_year` | `academic_year`) and its **`as_of`**, both printed, per
`02-accounting.md` §21.3 rule 1 — because the two axes differ by a full term and
a bursar reading the wrong one misreports to the proprietor.

**Band 1 — Accounting status.** Current period and lock state · trial balance
Σdebit, Σcredit, difference, with `✓ BALANCED` only when the difference is
exactly zero.

**Band 2 — Financial position.** Total liquidity split cash / bank / electronic
money · accounts receivable · accounts payable · period revenue · period expenses
· net surplus or deficit · budget utilisation.

**Band 3 — Control status.** The §4.1 matrix, condensed to status chips.

**Band 4 — What requires attention.** Unposted journals · pending approvals ·
unmatched bank transactions · overdue supplier bills · overdue student accounts ·
unresolved suspense items · unloaded configuration gates. Every count links to
the filtered worklist that resolves it.

**Band 5 — Aging.** Receivables reuse `Fees\Actions\AgedBalances` verbatim and
therefore inherit its six buckets — current, 1–30, 31–60, 61–90, 91–180, 180+ —
and its axis: **instalment due date, never invoice date**, so a September invoice
with a March tranche does not sit in the 180+ bucket in March. A student in
credit shows a negative net, never clamped to zero. Note the permission split:
that Action gates on `fee.view` while the rest of the control centre gates on
`ledger.view`, so the band renders only for a principal holding both, and is
absent — not zero — otherwise. Payables aging is computed from unlettered class-40
items (`02-accounting.md` §21.2).

**Band 6 — What is coming.** Upcoming supplier payments · expected fee
collections · payroll · tax obligations · budget commitments.

Every figure obeys §6.

---

## 6. Traceability contract

The drill-down chain the review asked for, stated as a testable contract:

> **Every monetary figure rendered anywhere in Finance resolves, in a bounded
> number of steps, to the posted journal lines that compose it, and from a line
> to its source document.**

```
Statement line  →  account(s)  →  journal entries  →  journal line
                                                          │
                                        ┌─────────────────┴─────────────────┐
                                        │                                   │
                                  source document                  posting rule + version
                            (invoice, receipt, payslip,          (why these accounts,
                             expense, asset, bill…)               under which rule version)
```

**This requires no migration.** `JournalEntry` already carries `source_type`,
`source_id`, `posting_rule_id`, `posting_rule_version`, `piece_no`, `created_by`,
`posted_by`, `approved_by`, `posted_at`, `approved_at`, `reverses_entry_id` and
`reversed_by_entry_id`. The work is a resolver plus presentation.

Implementation: one `Actions\Review\ResolveSourceDocument` mapping
`source_type` → a route and a human label, with an explicit registry per module.
An unmapped `source_type` renders as an inert, labelled reference — never a
broken link, never a raw class name.

Aggregate figures (a statement line, a KPI) expose their **account composition**
first, then per account the entries. Depth is bounded so the chain terminates.

---

## 7. Navigation reorganisation

`Identity\Support\Navigation` is a **flat list** of
`['key','route','permission','enabled','built']`. The proposed hierarchy needs a
grouping concept that does not exist.

Change: add an optional `group` key. Items without one keep today's behaviour
exactly, so no other module's navigation is touched — the change is additive and
every existing item renders unchanged until it is explicitly assigned a group.

Two contracts from `09-ui.md` and `routes/web.php` are preserved verbatim:

1. **Nav and route agree by construction.** An item is listed only if its
   permission genuinely opens its route.
2. **Hiding is presentation, never a control.** Every route continues to refuse
   on its own. Regrouping the sidebar changes no authorization.

Proposed grouping of **existing** items, plus the new ones from this spec:

```
Finance & Accounting
├── Dashboard                    ← finance_dashboard (rebuilt, §5)
├── Accounting                   ← ledger (CoA, journals, GL, trial balance),
│                                  opening balances, periods, year-end
├── Receivables                  ← fees invoices, cashier, statements
│                                  + NEW aging, collections, credit notes
├── Payables                     ← procurement suppliers, bills, payments
│                                  + NEW aging, supplier statements
├── Cash & Treasury              ← cash desk, reconciliation
│                                  + NEW cashbook
├── Expenses                     ← expenses
├── Procurement                  ← procurement
├── Budget & Management          ← budgets + analytic accounting
├── Fixed Assets                 ← assets
├── Payroll Accounting           ← payroll
├── Tax & Statutory              ← tax
├── Financial Statements         ← statements (Bilan, Résultat, Flux, Notes)
├── Statutory Books              ← books
├── Accounting Review            ← NEW (§4)
├── Reports                      ← reports
├── Audit Trail                  ← existing audit surface
└── Configuration                ← system documentation, gates register, tax config
```

No route is renamed or removed. Grouping is a presentation change over the
routes that already exist.

---

## 8. Documentation page

Two distinct artifacts, and conflating them would be a compliance error:

| | Statutory | Operator guide |
|---|---|---|
| What | *Documentation du système comptable*, AUDCIF Art. 19 / `02-accounting.md` §14.4 | "Learn the accounting module" |
| Audience | Auditor, tax administration | School accountant, bursar |
| State | **BUILT** — `SystemDocumentationSnapshot`, generated from live configuration | **MISSING** — this build |
| Property | Must be reproducible and version-pinned; drift is a test failure (§21.4) | May be edited freely; is not evidence |

The new page (`/accounting/documentation`, `ledger.view`) is the operator guide.
Scope: **the accounting module only**, as requested.

Structure: the accounting cycle end to end · what each screen is for and who uses
it · the SYSCOHADA account classes and how this school's chart is organised · how
operational events become journal entries (the posting-rule catalogue, rendered
from live data) · period control and what closing means · the year-end sequence ·
the control-account reconciliations and how to resolve a difference · reading
each statutory statement · the configuration gates and what each one blocks ·
glossary, French and English (the ledger is bilingual — `name_fr`/`name_en`).

Two rules:

1. Sections rendered from live data — the posting-rule catalogue, the account
   tree, the gate register — are **generated, not transcribed**, so the guide
   cannot drift from the system it documents.
2. It links to the statutory documentation; it never substitutes for it, and it
   says so on the page.

An IFRS/management reporting layer, should it ever be built, attaches at the
statement-composition seam (§6) and never inside the ledger. Recorded here only
to keep that seam intentional.

---

## 9. Definition of done

### 9.1 Per screen

1. Reachable route, permission agreeing with `Navigation` by construction.
2. Every query through `JournalEntry::scopePostedLedger()` — never a direct
   `where('status','posted')`, which drops reversed pairs (arch test,
   `02-accounting.md` §21.4).
3. Every monetary figure in minor units; no `FLOAT`/`DECIMAL` money column.
4. Every balance states its axis and `as_of`.
5. Every figure satisfies §6 or is explicitly marked non-drillable with a reason.
6. `ModuleBoundaryTest` still passes.

### 9.2 New tests this build owes

| Test | Asserts |
|---|---|
| **Control identity test** | For a seeded book, each §4.1 pair reconciles to exactly zero; introducing a deliberate imbalance flips precisely that row and no other |
| **Gate visibility test** | Every `02-accounting.md` §22 item appears in the §4.4 register with its blocked feature named; adding a gate to the spec without registering it fails |
| **No-invented-configuration test** | §1.1 as code — no seeder, factory or migration introduced by this build creates an account code, tax mapping or DSF mapping. Guards the rule against a future session |
| **Rejected-artifact regression** | None of the §1.2 codes exists with the rejected classification |
| **Traceability test** | Every `source_type` present in the ledger resolves through `ResolveSourceDocument`; an unmapped type renders inert, never a raw class name or a broken link |
| **Drill-down termination test** | The chain from any statement line terminates within the bounded depth |
| **Dashboard axis test** | Every control-centre figure is computed through `scopePostedLedger()` and carries an axis; the fiscal and academic answers genuinely differ on seeded data |
| **Documentation generation test** | The §8 generated sections match live posting rules and accounts — the guide cannot drift |

---

## 10. Delivery slices

Ordered by value and by dependency. Each is independently shippable.

| Slice | Contents | Depends on |
|---|---|---|
| **1 — Traceability spine** | `ResolveSourceDocument` + the drill-down component (§6). Built first because slices 2, 3 and 5 all render figures that must drill | — |
| **2 — Accounting Review** | §4.1 control reconciliations, §4.3 journal review, §4.4 suspense + gate register | 1 |
| **3 — Finance Control Centre** | §5 bands 1–6; existing bursar dashboard retained as the second tab | 1, 2 |
| **4 — AR/AP surfaces** | Aging from unlettered items, statements, collections, credit/debit notes, refunds, write-offs; AP aging and supplier statements | 1 |
| **5 — Analytical review** | §4.2 anomaly detection | 2 |
| **6 — Navigation + documentation** | §7 grouping, §8 operator guide | 2, 3 |
| **7 — Recurring journals** | The one genuine Layer-1 gap (§2.1) | — |

**Not scheduled, deliberately:** every item requiring a §22 gate. Slice 2 makes
those gates visible and names their owner; it does not close them. Closing them
is a sourcing task for a qualified accountant, not an engineering task.

---

## 11. Risks

1. **Concurrent sessions.** Other agent sessions work in git worktrees under
   `.claude/worktrees/agent-*`, not in this working tree, so the collision risk
   is lower than a shared tree would imply. It is not zero: `Navigation`, the UI
   shell and chart components are edited by whoever touches the sidebar, and
   §7 modifies `Navigation`. That file is the one genuine contention point and
   its change is deliberately additive (an optional `group` key) so a concurrent
   edit merges rather than conflicts.
2. **Verify state before trusting a prior audit.** §2.1 was first drafted
   against a repo state that could not later be reproduced, and was re-verified
   against `main` @ `d4e74f9` on 2026-08-12. Guardian-mobile slices A–F are
   committed (`f9cd767`, `92eaa7e`) and are **not** pending work. Any session
   resuming this spec should re-run the §2.1 checks before planning, rather than
   trusting the table.
3. **Test database contention.** `02-accounting.md`'s suite is large and
   `opeschool_test` is contended (see the guardian-mobile plan §2 incident). This
   build needs its own database name from the first command.
4. **Scope pressure toward the gates.** The most likely failure mode is a future
   session "completing" the module by populating DSF or tax mappings to remove
   `Not configured` from the screen. §9.2's no-invented-configuration test exists
   specifically to make that fail loudly.
