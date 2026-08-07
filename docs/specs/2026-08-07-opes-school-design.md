# OPES SCHOOL — Platform Design Specification

**Date:** 2026-08-07
**Status:** Draft for review
**Supersedes:** nothing (new build)
**Reference implementation:** `C:\laragon\www\school ERP` (.NET 9 / WPF / SQLite) — mined for domain rules only, never modified.

---

## 0. Purpose & scope

A school management platform for Cameroon, built in Laravel + MySQL, **API-first** and **domain-driven**, with a responsive Livewire web frontend in the same codebase.

**Deployment model:** single school, single deployment. Each customer school runs its own instance with its own database. The codebase is one product; it is not a shared multi-tenant SaaS.

**Market:** any Cameroonian school — nursery only, primary only, secondary only, technical, or any combination; Anglophone, Francophone, or bilingual. The product configures to the school; the school does not adapt to the product.

**Non-goals (v1):**
- Live payment-gateway integration (payments are recorded, not collected online)
- Multi-tenant SaaS hosting
- Any national credential document (see §17)

---

## 1. Fixed technical decisions

| Concern | Decision |
|---|---|
| Runtime | PHP 8.3+, Laravel 12 |
| Database | MySQL 8, InnoDB, `utf8mb4_0900_ai_ci`, strict mode |
| Frontend | Livewire 3 + Alpine.js + Tailwind, Blade |
| API auth | Laravel Sanctum — session cookie (web), personal access tokens (mobile/3rd-party) |
| Authorization | `spatie/laravel-permission`, fixed roles + granular permissions |
| Queue | Redis (database driver acceptable for small deployments) |
| Cache | Redis |
| PDF | Server-side PDF rendering for all documents |
| Money | `BIGINT UNSIGNED`, whole FCFA. **Never float, never decimal.** |
| i18n | Laravel localization, `lang/en` + `lang/fr` |
| Tests | Pest |
| Static analysis | PHPStan level 8 |
| Arch enforcement | Pest architecture tests |

### 1.1 The money rule

FCFA (XAF) has no subunit in practice. Every monetary column is `BIGINT UNSIGNED` storing whole francs. A `Money` value object wraps all arithmetic. Floats and `DECIMAL` are forbidden in monetary contexts and this is enforced by an architecture test. The .NET reference implementation stores money as `long` FCFA for the same reason.

---

## 2. Architecture

### 2.1 Layering — how "API-first" is honoured

Business logic lives in framework-agnostic **Action** classes. The REST API and the Livewire UI are both thin adapters over the same Actions. The UI does **not** call the HTTP API over the network (that would add a round-trip per interaction, which is unacceptable on Cameroonian bandwidth); it calls the same Action classes in-process.

```
app/Modules/<Module>/
├─ Domain/      pure business rules, value objects. No Laravel imports.
├─ Actions/     use cases. The ONLY way business state changes.
├─ Models/      Eloquent. Persistence only.
├─ Http/        Controllers, FormRequests, API Resources
├─ Livewire/    UI components
├─ Policies/
├─ Events/ Listeners/
└─ Database/    migrations, factories, seeders
```

**Enforced rules (architecture tests):**
1. `Domain/` may not import Laravel or Eloquent.
2. `Http/` and `Livewire/` may not import another module's `Models/`.
3. All cross-module access goes through the owning module's Actions or published Events.
4. Every Action authorizes before it mutates.
5. Every state-mutating Action runs inside a DB transaction.

### 2.2 Modules

| Module | Owns |
|---|---|
| Identity | users, roles, permissions, sessions, audit log, guardian portal accounts |
| SchoolProfile | school identity, sections, branding, settings, document config, localization |
| Academics | academic years, terms/sequences, class levels, sections, subjects, coefficients, streams, houses, timetable, calendar |
| Students | students, matricules, enrollment, promotion, transfer, documents, guardians |
| Admissions | applications, admission wizard, admission numbers |
| Attendance | student and staff attendance |
| Assessment | marks, competencies, grading scales, report cards, ranking, exams, scheduling |
| Fees | fee structures, invoices, installments, payments, receipts, adjustments |
| Accounting | SYSCOHADA chart, journals, ledger, fiscal years, statements, budget |
| Assets | fixed asset register, depreciation, disposals, custody, maintenance |
| HR | staff dossiers, teaching assignments, leave, appraisals, contracts |
| Payroll | payroll runs, payslips, CNPS/IRPP/CAC/CFC/RAV/TDL, declarations |
| Library | books, copies, members, issues, returns, fines |
| Inventory | consumable stock, movements, stores, reorder |
| Welfare | transport, hostel, medical, discipline, visitors, student insurance |
| Communication | messages, announcements, SMS/email/WhatsApp, notification preferences |
| Reporting | report catalogue, scheduling, exports, analytics |

Shared: `app/Support/` (Money, audit trait, sequence allocator, common value objects).

---

## 3. School configuration

### 3.1 Sections are opt-in

```
SchoolSection
├─ code          nursery | primary | secondary_1 | secondary_2 | technical
├─ name, name_fr
├─ sub_system    anglophone | francophone
├─ framework_id  → AssessmentFramework
├─ matricule_format
├─ is_active, display_order
```

A bilingual school creates two rows per section (one per sub-system). A nursery-only school creates one. Every class level belongs to exactly one section, so a student's report-card family, grading rules, and available modules derive automatically from enrollment.

Navigation, permissions and seeded reference data key off active sections, so the product presents only what the school actually runs.

### 3.2 First-run setup wizard

School identity → sections & sub-systems → academic year and period structure → class levels & sections → subjects & coefficients → grading scales → fee structure → chart of accounts → users & roles. Every step seeds from a preset and remains editable afterwards.

---

## 4. The assessment & report card engine

### 4.1 Families supported

| Family | Ministry | Levels | Model |
|---|---|---|---|
| A | MINESEC | Anglophone secondary (Form 1–5, L6/U6) | numeric, coefficients, sequences |
| B | MINESEC | Francophone secondary (6ᵉ–Tle) | notes /20, coefficients, séquences, groupes |
| C | MINEDUB | Anglophone basic education | APC competency, learning domains |
| D | MINEDUB | Francophone basic education | APC competency, champs disciplinaires |
| E | MINESEC | Technical/vocational | as A/B with practical weighting |

These are configurations of one engine, not separate code paths.

### 4.2 Core entities

```
AssessmentFramework
├─ school_section_id, ministry, sub_system
├─ scale_type            numeric | competency | hybrid
├─ max_score             20 | 10 | 100 | null
├─ pass_mark
├─ uses_coefficients, uses_subject_groups, uses_rank
├─ rank_scope            section | level
├─ rank_tie_rule         competition | dense | average
├─ term_composition      weighted_mean_of_periods | reaverage_raw_marks
├─ absent_policy         exclude | zero  (default per framework, overridable per mark)
└─ report_config_id      → ReportCardConfig

AssessmentPeriod              recursive
├─ academic_year_id, parent_id
├─ type        year | semester | term | trimestre | sequence | evaluation
├─ name, name_fr, sequence_no
├─ starts_on, ends_on, weight
├─ marks_entry_opens_at, marks_entry_closes_at
└─ is_published, published_at, published_by

SubjectGroup        code, name, name_fr, subtotal_label, display_order,
                    contributes_to_general_average

Subject             code, name, name_fr, subject_group_id,
                    category (core|elective|practical), is_examinable

SubjectAllocation   subject_id × class_level_id
├─ coefficient, max_score_override
├─ component_weights   e.g. {ca: 30, exam: 70}
└─ is_active

Mark
├─ enrollment_id, subject_id, assessment_period_id
├─ component     ca | exam | practical | oral | written
├─ score, max_score
├─ state         scored | absent_justified | absent_unjustified | exempt | pending
├─ entered_by, entered_at, locked_at
└─ UNIQUE(enrollment_id, subject_id, assessment_period_id, component)

LearningDomain      framework_id, code, name, name_fr, display_order
Competency          learning_domain_id, class_level_id, statement, statement_fr,
                    competency_type, display_order
CompetencyLevel     code, label, label_fr, numeric_equivalent, display_order, colour
CompetencyAssessment enrollment_id, competency_id, assessment_period_id, level_id,
                     evidence_note
```

`Mark` is keyed on `enrollment_id`, not `student_id` — a student who repeats a year, or transfers class mid-year, must not have their marks collide across enrollments.

### 4.3 Computation pipeline

Six pure, independently tested stages:

1. **Collect** — marks for (enrollment × period), all components
2. **Compose** — merge components using `component_weights`
3. **Normalize** — `(score / max_score) × framework.max_score`
4. **Weight** — `normalized × coefficient`
5. **Aggregate** — group subtotals, then `Σweighted / Σcoefficient` over assessed subjects
6. **Rank & band** — class rank, subject rank, grade letter, mention

**Mark state semantics (the rule that silently corrupts averages if wrong):**

| State | Numerator | Denominator |
|---|---|---|
| `scored` | includes score | includes coefficient |
| `absent_unjustified` | includes 0 | includes coefficient |
| `absent_justified` | per framework `absent_policy` | per framework |
| `exempt` | excluded | excluded |
| `pending` | excluded, and blocks publication | excluded |

**Term composition:** weighted mean of child period averages, weights from `AssessmentPeriod.weight`. Configurable to re-averaging raw marks, because a minority of schools do it that way and the two produce different numbers.

**Ranking:** computed within `rank_scope`. Default competition ranking: `rank = 1 + count(strictly greater average)`. Ported from the .NET `ReportCardCalculator`, with tie rule configurable.

### 4.4 Class statistics

Per (class section × period), computed on publication and cached: class average, highest/lowest average, effectif enrolled vs assessed, pass count and rate, per-subject mean/min/max/pass%/teacher, grade-band distribution, standard deviation.

### 4.5 Grade bands

`GradeBand` scoped to framework: `min`, `max`, `name`, `name_fr`, `remark`, `remark_fr`, `is_pass`, `colour`, `display_order`. Seeded defaults:

**Francophone /20:** Excellent 18–20 · Très Bien 16–18 · Bien 14–16 · Assez Bien 12–14 · Passable 10–12 · Insuffisant 8–10 · Médiocre 6–8 · Faible 0–6

**Anglophone %:** A 80–100 · B 60–80 · C 40–60 · D 30–40 · E 0–30

Bands are data. Ranges are half-open `[min, max)` with the top band closed, so a boundary score lands in exactly one band.

### 4.6 Competency assessment (APC)

Default levels, editable: **CAM** Competency Mastered / Compétence Maîtrisée (4) · **CA** Acquired / Acquise (3) · **ECA** Being Acquired / En Cours d'Acquisition (2) · **NA** Not Acquired / Non Acquise (1)

`numeric_equivalent` enables hybrid mode, where a numeric average can be derived without teachers entering numbers. Basic-education frameworks default `uses_rank = false` (APC is non-comparative) but this is configurable.

### 4.7 Report card configurator

```
ReportCardConfig                one per AssessmentFramework
├─ blocks[]        ordered, each {key, enabled, options}
├─ marks_columns[] ordered, each {key, enabled, label, label_fr}
├─ labels{}        every user-visible string, EN + FR
├─ branding        crest, colours, header, footer, watermark
├─ signatures[]    {title, title_fr, show_image, order}
├─ paper           A4|A5, portrait|landscape, per_page
├─ averages_shown  sequence | term | annual | cumulative
└─ template        which base template renders it
```

**Available blocks:** school header · student identity · photo · marks table · group subtotals · general average · rank · mention · class statistics · attendance · conduct · competency table · domain summary · teacher remarks · class master remark · principal remark · conseil de classe decision · fee balance notice · resumption date · signatures · QR verification.

**Available marks columns:** coefficient · raw mark · normalized mark · weighted mark · subject rank · class mean · class min · class max · grade letter · appreciation · teacher name.

Configuration is **per framework**, so a school's nursery, primary and secondary cards are configured independently.

**Not in scope for v1:** a drag-and-drop WYSIWYG layout canvas.

### 4.8 Templates

One base template per family under `resources/views/reports/`, all fed by a single immutable `ReportCardData` DTO. Templates never compute. Adding a family is a view file, not a migration.

### 4.9 Publication & integrity

- Marks entry open until the period is published, and only within the period's entry window.
- Publication **snapshots** computed averages, ranks and statistics into `ReportCardSnapshot`, so a reprint years later reproduces what was issued.
- Publication is blocked while any `pending` mark exists for an enrolled, non-exempt student.
- Post-publication mark changes require an elevated permission, are audit-logged with before/after and a reason, and produce an amended report card explicitly marked as such.
- Every print is written to `DocumentPrintLog`.

---

## 5. Accounting (SYSCOHADA)

### 5.1 The dual-calendar requirement

The OHADA fiscal year (*exercice comptable*) runs **1 January – 31 December**. The Cameroonian academic year runs roughly **September – July**. One academic year straddles two exercices.

Every financial figure must therefore be reportable on **both** axes. Every ledger entry carries both `fiscal_year_id` and `academic_year_id`. This is designed in from the first migration; retrofitting it is a rewrite.

### 5.2 Chart of accounts

Hierarchical, seeded to the SYSCOHADA révisé structure, extensible.

| Class | Content |
|---|---|
| 1 | Ressources durables — capital, réserves, emprunts, provisions |
| 2 | Actif immobilisé — buildings, vehicles, furniture, IT, **accumulated depreciation** |
| 3 | Stocks |
| 4 | Tiers — student receivables, supplier payables, staff, State, CNPS |
| 5 | Trésorerie — caisse, banque, mobile money |
| 6 | Charges des activités ordinaires — including **dotations aux amortissements** |
| 7 | Produits des activités ordinaires — tuition, registration, exam, levies, boarding |
| 8 | Autres charges et produits (HAO) |
| 9 | Engagements hors bilan et **comptabilité analytique** (per-section costing) |

```
ChartOfAccount
├─ code, parent_id, class
├─ name, name_fr
├─ type            asset|liability|equity|revenue|expense|off_balance
├─ normal_balance  debit|credit
├─ is_postable     only leaves accept entries
├─ is_reconcilable
├─ is_system       renameable, not deletable
└─ analytic_required
```

### 5.3 Double-entry core

```
FiscalYear         starts_on, ends_on, status (open|closing|closed),
                   opening_entry_id, closing_entry_id
AccountingPeriod   monthly, individually lockable
Journal            AC achats · VE ventes · BQ banque · CA caisse ·
                   OD opérations diverses · PA paie · AN à-nouveaux
JournalEntry       date, journal_id, piece_no, reference, description,
                   fiscal_year_id, academic_year_id,
                   source_module, source_type, source_id,
                   status (draft|posted|reversed), posted_by, posted_at,
                   reversed_by_entry_id
JournalEntryLine   account_id, debit, credit, analytic_axis_id,
                   lettering_code, currency_amount, description
AnalyticAxis       cost centre: section, department, activity, vehicle, hostel
```

**Invariants, enforced in transaction and covered by tests:**
1. `Σdebit = Σcredit` per entry. Unbalanced entries cannot persist.
2. Posted entries are **immutable**. Corrections are reversal entries (*contrepassation*), never edits or deletes.
3. No posting into a locked period or closed exercice.
4. Only `is_postable` accounts accept lines.
5. Every line has exactly one non-zero side.
6. Every entry carries provenance to its source record.
7. `piece_no` is allocated atomically per journal per fiscal year.

### 5.4 Automatic posting

Operational staff never touch the ledger.

```
PostingRule
├─ event            fee.invoice.issued · fee.payment.recorded · fee.payment.voided
│                   fee.adjustment.granted · payroll.run.approved · payroll.paid
│                   expense.recorded · supplier.invoice.received · supplier.paid
│                   stock.received · stock.issued · asset.acquired
│                   asset.depreciated · asset.disposed · library.fine.collected
├─ journal_id, debit_account_id, credit_account_id
├─ conditions       JSON predicate (e.g. payment_method = mobile_money)
├─ analytic_axis_source
└─ is_active, effective_from, effective_to
```

Rules are editable in the UI. Every school's accountant remaps accounts; that must never require a developer.

Worked example — 350,000 FCFA tuition invoice then payment by MTN Mobile Money:

*Issue:* Dr 4111 Élèves 350 000 / Cr 7061 Frais de scolarité 350 000
*Collect:* Dr 5210 Mobile Money MTN 350 000 / Cr 4111 Élèves 350 000

### 5.5 Cash & bank management

- Multiple cash desks (`caisse`), each with a custodian and its own account.
- Cash desk open/close with counted-vs-expected reconciliation and a variance record.
- Bank accounts with statement import (CSV/OFX), matching, and reconciliation report.
- Mobile money treated as a bank-like account with its own reconciliation.
- Inter-account transfers (`virement`) with an in-transit account.

### 5.6 Budget

Budget per fiscal year × account × analytic axis, with monthly phasing; budget-vs-actual reporting; optional soft warning on over-budget expense entry.

### 5.7 Year-end

Period lock → trial balance review → adjusting entries → depreciation run → provisions → closing entries → result appropriation → generate à-nouveaux for the next exercice → lock. Every step audit-logged and reversible only by an explicit re-opening action with a reason.

### 5.8 Statements & reports

**Statutory:** Balance générale · Grand livre · Journaux · Bilan · Compte de résultat · Tableau des flux de trésorerie · Notes annexes

**Management:** cash book · daily collections · fee collection and outstanding by class/section · defaulters · aged receivables · aged payables · budget vs actual · analytic P&L per section/activity · bank and mobile-money reconciliation

All exportable to PDF and Excel, in EN or FR.

### 5.9 Open questions for the school's accountant

1. TVA treatment — tuition is generally exempt; canteen, uniforms, transport, bookshop may not be.
2. Withholding tax on suppliers (*précompte / retenue à la source*) — applicable rates.
3. Revenue recognition — collection basis or deferred across the academic year.
4. Système Normal vs Système Minimal de Trésorerie.
5. DSF (Déclaration Statistique et Fiscale) — required, and does the chart need mapping to its line items.

Defaults are seeded and every item is configurable, but these must be confirmed before go-live.

---

## 6. Asset management

Distinct from Inventory: Inventory holds **consumables** (class 3, expensed on issue); Assets holds **capitalised fixed assets** (class 2, depreciated).

```
AssetCategory
├─ code, name, name_fr
├─ asset_account_id            class 2 (e.g. 2441 Matériel informatique)
├─ depreciation_account_id     class 28 accumulated depreciation
├─ expense_account_id          class 681 dotations aux amortissements
├─ default_method              straight_line | declining_balance
├─ default_useful_life_years, default_residual_rate
└─ capitalisation_threshold

Asset
├─ tag_number (barcode/QR), name, description, category_id
├─ acquisition_date, acquisition_cost, supplier_id, invoice_ref
├─ funding_source              purchase | donation | grant | transfer
├─ useful_life_years, residual_value, depreciation_method
├─ depreciation_start_date
├─ location_id, custodian_staff_id, analytic_axis_id
├─ serial_number, warranty_expires_on
├─ insurance_policy_id, insured_value
├─ condition                   new | good | fair | poor | unserviceable
├─ status                      in_use | in_store | under_maintenance |
│                              impaired | disposed | lost | written_off
└─ parent_asset_id             for components

DepreciationSchedule   asset_id, fiscal_year_id, period, opening_nbv,
                       charge, accumulated, closing_nbv, posted_entry_id
AssetMovement          transfer of location or custodian, with acknowledgement
AssetMaintenance       scheduled and corrective, cost, provider, downtime
AssetRevaluation       date, old_value, new_value, reason, posted_entry_id
AssetDisposal          date, method (sale|scrap|donation|loss), proceeds,
                       nbv_at_disposal, gain_or_loss, posted_entry_id
AssetVerification      physical count campaigns, found/missing/reconciled
```

**Depreciation run:** monthly or annual, per category method, computed on a schedule, posted through `PostingRule` as Dr 681x / Cr 28xx. Runs are idempotent — a period can only be depreciated once, and re-running is a no-op unless explicitly reversed.

**Disposal posting:** derecognise cost (Cr class 2), derecognise accumulated depreciation (Dr class 28), record proceeds (Dr treasury), and post gain or loss to the appropriate class 8 HAO account.

**Integration:** school buses in Transport, hostel furniture in Welfare, and lab/ICT equipment all carry an `asset_id`, so the operational record and the financial record are the same object. Asset acquisition from a supplier invoice creates the asset and the ledger entry in one Action.

---

## 7. Fees & billing

```
FeeStructure       academic_year × section × class_level [× stream]
FeeItem            name, name_fr, amount, is_mandatory, revenue_account_id,
                   applies_to (all|new|returning|boarder|day|transport_user|
                               scholarship_excluded), analytic_axis_id
InstallmentPlan    ordered instalments with due dates and amounts or percentages
Invoice            student, enrollment, academic_year, term, status, issued_at
InvoiceLine        SNAPSHOT of fee item at issue: name, amount, account
Payment            IMMUTABLE — amount, method, reference, received_by,
                   receipt_no, cash_desk_id, received_at,
                   is_voided, void_reason, voided_by, voided_at
PaymentAllocation  payment × invoice_line, amount
FeeAdjustment      signed amount, reason type (scholarship|sibling|staff_child|
                   hardship|bursary|correction), granted_by, approved_by
```

**Rules:**
- Receipt and invoice numbers allocated atomically via a dedicated sequence table with row locking. Never `max()+1`.
- Payments are never edited or deleted. Voids create a reversal and a fresh payment; both post to the ledger.
- Invoice lines snapshot the fee structure at issue. Editing next year's fees cannot alter what a parent was billed.
- Balance is computed (`Σinvoiced − Σadjustments − Σallocated`), never stored mutably.
- Overpayment creates a credit balance carried to the next invoice, not a silent negative.
- Payment allocation strategy configurable: oldest-invoice-first, or explicit line selection by the cashier.

**Payment methods:** cash · MTN Mobile Money · Orange Money · other MFI · bank transfer · cheque (pending→cleared→bounced) · bank deposit slip · scholarship · waiver · in-kind. Each maps to its own treasury account.

---

## 8. Students & admissions

```
Student            matricule, names, sex, DOB, place of birth, nationality,
                   region of origin, religion, blood group, genotype, photo,
                   languages, status, is_repeater
Guardian           own entity; StudentGuardian pivot (relationship, is_primary,
                   has_custody, receives_reports, receives_invoices)
Enrollment         student × class_section × academic_year × school_section
                   status (active|completed|repeated|transferred_out|
                           withdrawn|graduated|expelled)
AdmissionApplication  wizard state, decision, converted_student_id
StudentDocument    type, file, uploaded_by, verified_by
```

**Matricule:** configurable format per section, atomic sequence, immutable once issued, never reused.

**Promotion wizard** (ported from `EvaluatePromotionUseCase`): evaluate a class against configurable criteria (minimum average, minimum attendance %, no subject failures, no open discipline case), present eligible/ineligible split with reasons, allow per-student override with a mandatory reason, apply atomically in one transaction.

**Transfers and graduation** close enrollments; history is never deleted.

---

## 9. Attendance

Daily register per class section, optimised for clerical entry: whole class defaults present, mark exceptions only. States: present · absent · late · excused. Optional per-period attendance for secondary. Staff attendance separately.

Feeds: report card attendance block, promotion eligibility, guardian absence alerts.

---

## 10. HR

```
StaffMember
├─ staff_no, names, sex, DOB, place of birth, nationality
├─ national_id_type, national_id_number
├─ CNPS_number, taxpayer_number (NIU)
├─ qualification, specialisation, experience_years
├─ department_id, position, employment_type
│    permanent | contract | temporary | part_time | volunteer
├─ contract_start, contract_end, probation_end
├─ salary_grade_id, basic_salary
├─ bank_name, bank_account, mobile_money_number
├─ next_of_kin_name, relationship, phone
├─ marital_status, dependants_count       ← drives IRPP relief and family allowance
├─ risk_class                             ← drives CNPS occupational-risk rate
└─ status (active|on_leave|suspended|terminated|retired)

TeacherAssignment  staff × class_section × subject × academic_year
                   ← gates marks entry; enforced in the Action layer
StaffLeave         type, days, balance, approver, status
StaffContract      versioned, with document
StaffAppraisal     period, criteria, score, reviewer
StaffDiscipline    case, sanction
```

**Departments, positions and salary grades** are configurable reference data, not enums.

---

## 11. Payroll & CNPS compliance

```
SalaryGrade         code, name, basic_salary, step, category
PayrollComponent    code, name, name_fr, type (earning|deduction|employer_charge),
                    calculation (fixed|percentage|formula|table),
                    base (basic|gross|taxable|capped_gross),
                    is_taxable, is_cnps_liable, account_id,
                    effective_from, effective_to
PayrollRun          period, section, status (draft|calculated|approved|paid|closed),
                    locked_at, approved_by, journal_entry_id
PayrollItem         staff, gross, taxable_base, cnps_base, net,
                    snapshot of all inputs
PayrollLine         one row per component, with the computed amount and the
                    rate/bracket actually applied
StatutoryRate       code, description, rate, ceiling, floor, bracket_from,
                    bracket_to, employer_share, employee_share,
                    effective_from, effective_to
```

### 11.1 CNPS compatibility

Cameroon's CNPS operates three branches. The system models each as a separate `StatutoryRate` set with independent employer/employee shares and its own ceiling:

| Branch | Code | Borne by |
|---|---|---|
| Pension — Prestations de Vieillesse, Invalidité et Décès | `PVID` | employee + employer |
| Prestations Familiales (family allowances) | `PF` | employer only |
| Risques Professionnels (occupational accident) | `RP` | employer only, rate varies by risk class |

**Design requirements:**
- Each branch has its own **contribution ceiling** (*plafond*), independently effective-dated.
- The occupational-risk rate depends on the employer's or the post's **risk class**, held on `StaffMember.risk_class` with a school-level default.
- Employer charges are computed and posted, not just employee deductions — employer CNPS is an expense (class 6) and a liability (class 4), and omitting it understates payroll cost materially.
- Contributions are computed on **capped gross**, and the cap applies per branch, per month, per employee.
- Employees exempt from a branch (e.g. certain contract types) are flagged and excluded.

**Declarations:** the system produces the **DIPE** (Déclaration d'Informations sur le Personnel Employé) return data and a CNPS contribution schedule per period, exportable, listing each employee, their CNPS number, capped base, and each branch's employee and employer amounts.

### 11.2 Other statutory deductions

**IRPP** — progressive brackets on taxable income after the statutory abatement and CNPS pension deduction; family situation and dependants affect relief.
**CAC** — additional council tax computed as a percentage of IRPP.
**CFC** — Crédit Foncier du Cameroun, employee and employer shares.
**RAV** — Redevance Audio-Visuelle, from a salary band table.
**TDL** — Taxe de Développement Local, from a salary band table.

### 11.3 The rates rule

**Every rate, bracket, ceiling and band is `effective_from`/`effective_to` dated data, never a constant in code.** Cameroon's Finance Law changes these; a rate change must be a data edit with full history, so that recomputing an old period reproduces the amounts originally paid. Payroll runs snapshot the rates they used.

**⚠️ All seeded rates are ported from the .NET reference implementation and must be verified against the current Finance Law and CNPS circulars before go-live.** They are starting values, not authority.

### 11.4 Payroll posting

On approval: gross to class 6 expense (split by department via analytic axis), net to staff payable (class 42), each employee deduction to its class 4 State/CNPS liability, each employer charge to class 6 expense with the matching class 4 liability. On payment: clear the payables against treasury.

Payslips render bilingually and are distributable to staff portal accounts.

---

## 12. Library

Books, individually barcoded copies, members (students and staff), issues, returns, renewals, reservations, holds, fines (posted to the ledger), lost/damaged handling with replacement cost, shelf locations, categories, acquisitions, and circulation reports.

## 13. Inventory (consumables)

Items, categories, units, stores/locations, **signed-delta stock movements** (never a mutable quantity column), reorder levels, low-stock alerts, transfers between stores, requisitions, issue to department, stock-take with variance and reason. Receipts post to class 3; issues post to class 6 expense with the analytic axis of the consuming department.

## 14. Welfare

**Transport** — routes, stops, vehicles (each linked to an `Asset`), drivers, allocations (one active per student), trip log, maintenance, fuel log.
**Hostel** — hostels, rooms, beds, allocations (one active per student), inspections, occupancy, visitors.
**Medical** — records, consultations, severity, diagnosis, treatment, referrals; chronic conditions and allergies surfaced on the student profile and to class teachers.
**Discipline** — cases, offence catalogue, investigation, sanctions, resolution; the ported `SanctionLadder` suggests escalation from prior-case count within a lookback window — **advisory only, never automatic**. Feeds promotion eligibility and the report card conduct block.
**Visitors** — check in/out, purpose, host, badge, gate pass.

**Student insurance:**
```
InsurancePolicy      provider, policy_no, cover_type, premium_per_student,
                     coverage_start, coverage_end, academic_year, asset_id (nullable)
StudentInsurance     enrollment × policy, enrolled_on, certificate_no, status
InsuranceClaim       incident_date, description, amount_claimed, amount_settled,
                     status, documents
```
The premium is a `FeeItem`, so it bills and posts like any other fee. The policy record proves cover and drives the "uninsured students" report. The same `InsurancePolicy` table also covers asset insurance.

## 15. Communication

Messages, announcements, recipient groups (all guardians / a class / a stream / staff / debtors / a custom list), templates with merge fields, scheduling, delivery tracking and retry.

Channels behind a `NotificationChannel` driver interface: in-app, email, SMS, WhatsApp. Provider swap is configuration.

Per-recipient opt-in preferences, honoured on every send. Automated triggers: fee reminders, absence alerts, results published, exam timetable, PTA notices.

---

## 16. Parent portal, staff portal & mobile API

**Guardian accounts** link through `StudentGuardian`. One login, all their children, across sections.

Scope, read-only: results, report cards and attendance · fees owed, payment history and receipts · discipline records and school-issued documents · messages and announcements.

**Publication gating is absolute** — a guardian sees a report card only after its period is published.

**Staff portal:** own payslips, leave balance and requests, timetable, assigned classes, marks entry.

**API as a product:**
- Versioned `/api/v1`, OpenAPI 3.1 generated from code
- Sanctum tokens with per-token scopes
- Cursor pagination, sparse fieldsets, `include` for relations
- `ETag` / `If-None-Match` conditional requests
- RFC 7807 problem-details errors
- Idempotency keys required on all write endpoints
- Rate limiting per token
- Webhooks for key events
- Generated developer docs site

The mobile app consumes this same API. No separate backend.

---

## 17. Security, audit & integrity

- Argon2id password hashing, optional TOTP 2FA, configurable session timeout.
- Roles and granular permissions enforced **in Actions**, not by hiding menus.
- Guardian scoping to their own children is the highest-risk authorization boundary and gets a dedicated test suite.
- **Audit log** on every state change: actor, action, module, before/after, IP, user agent, timestamp. Append-only, immutable.
- Field-level encryption for medical notes, national ID numbers, bank details.
- `DocumentPrintLog` on every certificate, report card, receipt, payslip and ID card.
- Automated backups with integrity verification and a tested restore path.
- Rate limiting and lockout on authentication.

### 17.1 Document integrity carve-out (firm, permanent)

The platform will **not** produce any national credential or state-issued document. Specifically excluded: MINESEC/GCE Board Official Transcripts, Baccalauréat transcripts, Minister-signed diplomas, and any document bearing the Republic of Cameroon coat of arms, a Ministry seal, a national serial number, or a security-features legend.

Building these would make the product a credential-forgery tool and expose the vendor and its customer schools to criminal liability. The .NET reference implementation reached and documented the same conclusion.

**Delivered instead — school-issued documents under the school's own authority and signatures:** Statement of Results · Certificate of Completion · Transfer Certificate · Testimonial / Character Certificate · Attestation of Attendance · Report Cards · Fee Receipts · Student ID Cards (school branding only, no state emblems).

---

## 18. Responsive & bilingual

Mobile-first Tailwind. Data-dense tables degrade to card layouts below `md`; the sidebar becomes a drawer; marks entry becomes a thumb-friendly stepper on phones rather than a shrunken grid. Verified at 360 / 768 / 1024 / 1440.

Full EN/FR across UI, documents, emails and SMS. Two independent settings: the **operator's** UI language and the **school's** (or section's) document language.

---

## 19. Error handling

- Expected failures return `Result` objects from Actions and render as friendly bilingual messages.
- Unexpected exceptions: full detail to logs, calm user-facing message, no stack traces in production.
- Validation is defined once in FormRequests and shared by the API and Livewire.
- Domain invariant violations throw and are never caught silently.

---

## 20. Testing strategy

- **Domain/Actions:** dense unit tests. Grading, ranking, fee balances, payroll tax, depreciation and double-entry balance are the highest cost-of-error code and get exhaustive coverage — including every mark state, every tie case, every boundary of every band and bracket.
- **Integration:** real MySQL, real transactions, no mocked persistence.
- **API:** contract tests against the OpenAPI spec.
- **Authorization:** a suite proving each role sees exactly what it should; the guardian boundary especially.
- **Architecture:** module boundary and money-type rules enforced automatically.
- **Report cards:** golden-file rendering tests per family.
- **Accounting:** property test that every posting rule produces a balanced entry, for every event type.

---

## 21. Build order

| Phase | Contents |
|---|---|
| 0 | Foundation: skeleton, modules, Money, auth, roles, audit, settings, i18n, arch tests, CI |
| 1 | Academic core: sections, frameworks, periods, class levels, subjects, coefficients |
| 2 | People: students, guardians, enrollment, matricules, admissions, staff |
| 3 | Assessment: marks, grading engine, competency engine, ranking, statistics, report card engine + configurator, publication |
| 4 | Money I: chart of accounts, fiscal years, journals, double-entry core, posting rules |
| 5 | Money II: fee structures, invoices, payments, receipts, adjustments, statements |
| 6 | Daily ops: attendance, timetable, discipline, promotion |
| 7 | Assets & stores: fixed assets, depreciation, inventory, library |
| 8 | Welfare: transport, hostel, medical, visitors, insurance |
| 9 | HR & payroll: dossiers, leave, payroll runs, CNPS/IRPP, declarations |
| 10 | Reach: communication, parent/staff portals, API hardening, OpenAPI, webhooks |
| 11 | Polish: reports & analytics, document suite, responsive pass, performance, backup/restore |

Each phase gets its own implementation plan and ships something usable.

---

## 22. Source material

- **Mockups:** `frontend images/` — 47 working images. 19 archived to `_archive/`: 4 forgery-risk national credentials, 10 superseded unlocalized "Gen A" screens, 3 roadmap/meta sheets, 1 duplicate render, 1 desktop icon.
- **Domain reference:** `C:\laragon\www\school ERP` — .NET 9 / WPF / SQLite, 46 migrations, feature-complete. Mined for entities, business rules and workflows. **Never modified.**
- **Design lineage note:** the mockups contain two generations. "Gen B" (V 2.6.0, FCFA, Form 1–5, Cameroonian data, PHP 8.1 shown in settings) is the product direction. "Gen A" (v1.0.0.0, USD, Grade 6-A, US/Indian template leftovers including an "RTE" field) is superseded and archived.

---

## 23. Open items requiring input

1. Real MINESEC Anglophone secondary report card specimen
2. Real MINESEC Francophone *bulletin de notes* specimen
3. Real MINEDUB basic education report card specimen (both sub-systems if applicable)
4. Current APC competency framework / learning-domain list
5. Accountant's answers to §5.9 (TVA, withholding, revenue recognition, filing system, DSF)
6. Current CNPS rates, ceilings and risk classes; current IRPP/CAC/CFC/RAV/TDL tables
7. Confirmation of which sections and sub-systems the first customer school runs
