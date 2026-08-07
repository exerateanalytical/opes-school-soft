# OPES SCHOOL — Specification Suite

**Version 2.0 · 2026-08-07**

A school management platform for Cameroon. Laravel 12 + MySQL 8, API-first, domain-driven, Livewire frontend, one deployment per school, LAN-default with a VPS option.

---

## Read in this order

| # | Document | Owns |
|---|---|---|
| **00** | [00-core.md](00-core.md) | **Binding parent.** Foundations, architecture, naming, numeric policy, academic structure, identity, constraints, concurrency, sequences, audit, document-integrity policy, deployment, build order, blocking gates |
| 01 | [01-assessment.md](01-assessment.md) | Frameworks, periods, exams, marks, competencies, grading pipeline, report cards, publication |
| 02 | [02-accounting.md](02-accounting.md) | SYSCOHADA chart, ledger, journals, fiscal years, statements, budget, treasury, reconciliation |
| 03 | [03-tax-procurement.md](03-tax-procurement.md) | TVA, withholding, declarations, DSF, suppliers, purchase orders, payables |
| 04 | [04-fees.md](04-fees.md) | Fee structures, invoicing, payments, adjustments, refunds, receivables |
| 05 | [05-hr-payroll.md](05-hr-payroll.md) | Staff, contracts, leave, payroll, CNPS/IRPP, declarations |
| 06 | [06-assets-stores.md](06-assets-stores.md) | Fixed assets, depreciation, inventory, library |
| 07 | [07-students.md](07-students.md) | Students, guardians, enrollment, admissions, promotion, attendance |
| 08 | [08-operations.md](08-operations.md) | Deployment, backup/DR, licensing, updates, import, rollover, observability, performance, privacy, testing |
| 09 | [09-ui.md](09-ui.md) | Navigation, dashboards, screen contracts, settings taxonomy, responsive rules |
| 10 | [10-documents.md](10-documents.md) | The printable-document suite |

**Where a domain document and `00-core` disagree, `00-core` wins.**

---

## Provenance

**v1** — [`2026-08-07-opes-school-design.md`](2026-08-07-opes-school-design.md) — is **superseded and retained for history only**. Do not implement from it. Six independent audits found ~75 critical and ~140 high defects, including:

- Three wrong SYSCOHADA account codes (2441→2442; 7061 relabelled; mobile money in the bank class)
- A wrong CNPS rate (7% régime général where private education is 3.70%) and a per-branch ceiling that does not exist
- An IRPP formula that overtaxed every employee every month, and a dependants relief Cameroon does not have
- A grading pipeline that composed before normalising — flagged independently by three auditors
- A money type that threw `ERROR 1690` on the first overpayment
- One `UNIQUE` constraint, zero `ON DELETE` behaviours, zero locking strategy in the entire document
- ~40 entities referenced but never defined, so Phase 1 could not start
- No purchases ledger at all — half of every school's books
- A document-integrity carve-out so wide it forbade printing a normal Cameroonian report card

Each of those is fixed in the document that owns it, and named there so the fix cannot be silently reverted.

---

## Standing rules

**Nothing unverified is seeded.** A wrong value that looks authoritative is worse than an empty field. Every Cameroon-specific rate, account code, band or threshold either carries a citation or is flagged `NEEDS VERIFICATION`, ships **empty**, and **blocks the feature that needs it** until configured. One source consulted during the audits returned demonstrably wrong Cameroonian figures; that is why the rule exists.

**Document integrity** (`00-core` §13). The bilingual state letterhead is permitted — every real Cameroonian bulletin carries it. Ministry seals, Minister and GCE Board signatures, certification of national credentials the school did not award, national serial numbers, and security-feature legends are permanently forbidden. Three mockups for documents this product *does* deliver currently violate this; the deviation clause is `10-documents` §3.

**Money** is `BIGINT SIGNED`, whole FCFA, everywhere. Rates are integer basis points. No float, no decimal, enforced by architecture test.

---

## Blocking gates

Twelve inputs gate their phases (`00-core` §16). The load-bearing ones:

| Needed | Blocks |
|---|---|
| MINESEC Anglophone + Francophone and MINEDUB report card specimens; current APC framework | Phase 3 — Assessment |
| Accountant on TVA, withholding, revenue recognition, Système Normal vs SMT, DSF mapping | Phase 4 — Ledger |
| School's CNPS notification letter (regime + risk class); current CNPS/IRPP/CAC/CFC/RAV/TDL/FNE tables | Phase 11 — Payroll |
| Cameroonian data-protection legal review | Phase 12 |
| PDF engine decision benchmarked against the batch budget | Phase 3 |

Phases 0–2 (foundation, academic core, people & import) are **not** gated and can start immediately.

---

## Source material

- **Mockups** — `frontend images/` — 44 working, 22 archived. `complete product overview.png` and `flow wizards.png` are **normative scope sources**; between them they define 68 deliverables and 12 workflows.
- **Domain reference** — `C:\laragon\www\school ERP` — .NET 9 / WPF / SQLite, 46 migrations, 59 views, 138 use cases, 37 document renderers. Mined for entities, rules and workflows. **Never modified.**
