# HANDOVER — Accounting Review & the 29-Module Audit

Written: 2026-08-13, on account handover.
Branch `feat/accounting-review`, merged to `main`.

Read in this order:

1. **This file** — state, what to do next, and the traps.
2. `docs/specs/2026-08-12-module-gap-analysis.md` — **the backlog.** Twenty-one
   genuine gaps found by auditing 29 proposed module designs against the code.
3. `docs/specs/2026-08-12-accounting-finance-architecture.md` — the finance
   architecture and the binding configuration-integrity rule.
4. `docs/superpowers/plans/2026-08-12-accounting-review-and-traceability.md` —
   the executable plan. **Tasks 7–10 remain.**

---

## 1. The single most important thing on this page

A proposed finance design supplied an illustrative chart of accounts —
`101100 Main Bank`, `401100 Student Receivables`, `401200 Supplier Payables`,
`411100 School Fees` — drawn from Anglo-American practice.

**This system is SYSCOHADA révisé (OHADA).** Class 1 is capital, class 40 is
*fournisseurs*, class 41 is *clients*, revenue is class 7. Those codes feed
Journal → Grand livre → Balance → états financiers → DSF. Implementing them
would have produced **wrong statutory reporting**, not a cosmetic problem.

The rule adopted as a result, now binding and enforced by test:

> **No invented statutory account codes, tax mappings, DSF mappings,
> financial-statement mappings or regulatory fields. Every authoritative
> accounting configuration must be sourced, versioned and validated before it
> becomes system data.**

A field may read **Not configured** indefinitely. That is correct behaviour.
`tests/Architecture/AccountingReviewTest.php` (Task 9, not yet written) is
specified to fail if any seeder, factory or migration introduces one.

---

## 2. What was built

Branch: 14 commits, all green. Everything is additive; nothing was deleted or
rewritten.

| Task | What | Tests |
|---|---|---|
| 1 | Investigation — enumerated real ledger source types | — |
| 2 | `App\Support\Ledger\` contract + registry + 4 module resolvers + `ResolveSourceDocument` | 5 |
| 3 | `x-accounting.source-link` drill-down component | 3 |
| 4 | `AuxiliaryControlChecks` (+ tautology fix) | 4, 12 assertions |
| 5 | `ConfigurationGates` register | 8, 77 assertions |
| 6 | `Livewire\Review\ControlCentre` at `/accounting/review` | 6 |

**Remaining: Tasks 7–10** — journal exceptions worklist, full control matrix +
suspense balances, architecture guard tests, full verification. All are fully
specified in the plan, with code.

---

## 3. Three defects the build caught — and what they teach

These matter more than the code. Each was found by running against real data,
not by reading specs.

### 3.1 A control account check that could never fail

`AuxiliaryControlChecks` was first written to sum a collective account's lines,
then sum *the same lines* filtered to `partner_id IS NOT NULL`. Trigger L8
guarantees every line on a collective account carries a partner — so both sides
read **identical rows** and the difference was always zero. It would have shown
a green "✓ Reconciled" on a genuinely broken ledger.

Fixed by delegating to `ReconcileAuxiliaryBalances`, which carries the spec's
§8.4 queries verbatim and groups by partner first. **One implementation of the
rule**, as `02-accounting.md` requires.

**Lesson: an assurance control that cannot fail is worse than no control.**

### 3.2 Tests that proved nothing

The same task shipped four tests with **one assertion between them** — every
check ran inside a `foreach` over an empty collection. A vacuous pass is
indistinguishable from a real one in the summary line.

**Lesson: check the assertion count, not just the pass count.**

### 3.3 Specs that overstate themselves

Two cases:

- `02-accounting.md` §21.4 claims an auxiliary-reconciliation test exists with
  1 200 student balances. **It does not.** `ReconcileAuxiliaryBalances` had no
  test at all.
- §22 lists gates 1 and 3 as open. **Both are already closed** — `7073`, `7077`,
  `7078` and `6317` are seeded.

**Lesson: treat specs as authoritative on *intent*, the code as authoritative
on *state*. `ConfigurationGates` reads the chart rather than trusting §22, which
is how the discrepancy surfaced.**

---

## 4. Traps that cost real time here

1. **Grep migrations, not model files.** A grep of models for `journal_entry_id`
   produced a false positive on `PurchaseOrder` — the only match was a comment
   saying it deliberately has *no* such column (a PO is a commitment and posts
   nothing, `03-tax-procurement.md` §4.2 invariant 6).
2. **A column existing ≠ a column populated.** `journal_entries.source_type` is
   always the literal `'posting_event'`; `source_id` is **never set**. The
   traceability design was rebuilt around the reverse link — 36 document models
   carry `journal_entry_id`.
3. **Module boundaries are absolute.** `ModuleBoundaryTest` forbids any module
   importing another's `\Models`, with no exceptions. Do **not** evade it with
   raw `DB::table('assets')`. The codebase's own precedent is a shared-kernel
   value object (`App\Support\Audit\Actor`); `App\Support\Ledger\` follows it.
4. **Never run two test processes at once.** Parallel runs corrupted the test
   database twice, requiring a full rebuild. Use one foreground run.
5. **PHP casts numeric string array keys to int.** `$a['707']` becomes
   `$a[707]`, and `str_starts_with()` then raises.

---

## 5. How to resume

```powershell
$env:Path="C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
$env:DB_DATABASE="opeschool_test_ar2"
cd C:\laragon\www\opeschool-cloud
php vendor\bin\pest tests\Feature\Accounting\Review
```

Create the database once if missing, then `php artisan migrate --force` with
that variable set. **Never use `opeschool_test`** — other sessions own it.

Then open the plan and take Task 7.

### Pre-existing failures — NOT caused by this build

Three, all documented in the earlier guardian-mobile baseline. Do not "fix" them
silently, and do not let them mask a new break:

- `Ui\Phase8WiringTest` — welfare discipline case (`SanctionType` to string)
- `Ui\ShellTest` — placeholder/coming-soon nav
- `Architecture\ModuleBoundaryTest` — `Communication → Identity\Models`,
  from commit `31c0840`

Any **other** failure is yours.

---

## 6. What to build next

From the backlog in `2026-08-12-module-gap-analysis.md`:

1. **Finish Tasks 7–10** — half-built, fully specified, cheapest remaining value.
2. **Admissions** — thinnest genuinely real module (3 models, 2 screens).
   Missing: interview, entrance exam, decision workflow, admission letter, and
   the **applicant → student conversion**, which is unenforced today. That last
   one is a correctness gap, not a missing feature.
3. Then Activities (largest greenfield), curriculum framework, Alumni.

### The decision that outranks the backlog

**There is no `campus_id` anywhere in the migrations.** The schema is
single-school throughout.

Every other gap is additive. Multi-campus is *retroactive*: it adds a dimension
that every table, query, index and authorisation scope must respect, in a
codebase where authorisation is already enforced per-route and per-record.
Retrofitting it later is harder than the other twenty gaps combined, and doing
it carelessly produces exactly the cross-tenant leak the design warns about.

**If a school network is a real near-term goal, decide it before building more
single-school surface area.** This decision was raised twice and never made.

---

## 7. Context for the audit

Twenty-nine module designs were proposed, totalling roughly **2 900 features**.
Audited against the code, **twenty-one are genuine gaps**. The rest already
exist — frequently built more rigorously than proposed: trigger-enforced
double-entry, versioned posting rules, hash-chained immutable audit, a 32-row
per-link guardian scope matrix, full asset depreciation, payment allocation and
idempotency.

Two principles the proposals stressed are **already honoured**: `Student` carries
no permanent class foreign key (history lives in `Enrollment`/`EnrollmentSegment`),
and the MINSEC matricule is distinct from the internal ID and admission number.

Before building from any proposal, check the gap analysis. The pattern held
across all twenty-nine.
