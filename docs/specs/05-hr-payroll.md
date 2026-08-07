# 05 — HR & Payroll

**Version:** 2.0
**Date:** 2026-08-07
**Status:** Draft for review
**Owns:** staff identity, contracts, assignments, compensation, leave, timesheets, payroll runs, statutory deductions (CNPS / IRPP / CAC / CFC / RAV / TDL / FNE), statutory declarations, payroll documents.
**Binding parent:** `00-core.md`. Where this document and `00-core.md` disagree, `00-core.md` wins.
**Build phase:** 11 (`00-core` §15). Blocked by `00-core` §16 gates **7**, **8** and **9**.

Cross-references: `02-accounting.md` (chart, posting rules, analytic axes, period locks), `03-tax-procurement.md` (SchoolProfile fiscal identity, withholding, DGI filing), `04-fees.md` (payment void pattern, reused here for run reversal), `06-assets-stores.md` (library fines against staff → payroll deduction), `07-students.md` (staff attendance shares the register model), `09-ui.md` (screens), `10-documents.md` (payslip, certificat de travail, registre d'employeur).

---

## 0. The standing rule for this module

> **No statutory rate, bracket, ceiling, band, barème or schedule is seeded with a value.**
> Every `StatutoryRate` row ships with the *code, basis, shape and metadata* populated and the **amount columns NULL**. `PayrollRun::calculate` runs a **preflight** (§9.1) that refuses to execute — not warns, not defaults — while any rate required by an enabled component is unresolved for the payroll month.

A wrong rate looks authoritative on a payslip, on a DIPE and on a DGI return, and the school — not the vendor — pays the reassessment. An empty field stops a bursar for an afternoon. The asymmetry is not close.

The figures in §2.3 are **reference values carried from CLEISS (effective 2024)**. They are documented so an implementer can build and test against real arithmetic, and so a bursar has something to check their CNPS notification letter against. **They are not seed data.** Fixtures that use them are test fixtures, tagged `@statutory-reference`, and are never loaded by `db:seed`.

---

## 1. Defects this document exists to fix

### 1.1 The four wrong numbers in v1

| # | v1 said | Truth | Consequence of shipping v1 |
|---|---|---|---|
| **N1** | "Each branch has its own **contribution ceiling**… the cap applies per branch" | **One** ceiling (750,000 FCFA/month) applying to **PVID and PF only**. **Risques Professionnels is uncapped.** | Every employee paid above 750,000 has RP computed on 750,000 instead of full gross. Direct CNPS reassessment with surcharges, compounding monthly, discovered at audit. |
| **N2** | PF modelled as a single régime-général rate (7%) with no employer-regime concept | **3.70%** for *personnel de l'enseignement privé*; 7% régime général; 5.65% agricole. Employer's regime is stated on its CNPS notification letter. | 3.3 points of capped payroll overcharged every month, forever, silently. On a 40-teacher school this is real money and it is never noticed because it is a *payment*, not a shortfall. |
| **N3** | `StaffMember.dependants_count` "← drives IRPP relief" | **Cameroonian salary IRPP has no quotient familial and no dependants relief.** The only abatements are the 30% professional abatement (capped 4,800,000/yr) and the 500,000/yr fixed abatement. | Under-withholding. The school is the withholding agent and is **personally liable** for the shortfall plus penalties. |
| **N4** | "progressive brackets on taxable income after the statutory abatement **and** CNPS pension deduction" — read as `0.70 × (SBT − PVID)` | The 30% abatement applies to **SBT**. PVID is deducted **separately, at 100%**. | Over-withholding on **every employee every month** (worked counterexample: §6.4). Employees are silently short-paid and the school owes them the difference. |

### 1.2 Structural defects (each individually blocking)

| # | Defect | Fix | §|
|---|---|---|---|
| C2 | No employer-regime concept — the 3.70% rate had nowhere to live | `EmployerProfile`, effective-dated, with the CNPS notification document reference; blocking first-run wizard step | §3 |
| C5 | Only DIPE specified — the DGI monthly salary return, the TDL commune remittance and annual returns were absent | `StatutoryDeclaration` + compliance calendar + unfiled-prior-period warning | §11 |
| C6 | No hourly/vacataire payroll — the product could not pay most teaching staff at a typical Cameroonian private school | `HourlyRate`, `TeachingHoursLog`, `Timesheet`, `calculation = hourly` | §5.5, §8.4 |
| C7 | The reproducibility guarantee was false — every payroll input was a mutable scalar | **`PayrollItemSnapshot` is authoritative.** Re-render and re-export never recompute | §10 |
| H1 | `StatutoryRate` shape ambiguous; **no `flat_amount`**, so RAV and TDL could not be represented at all | `employee_rate_bp` / `employer_rate_bp` / `flat_amount` / `basis` / `risk_class`, XOR-checked | §4 |
| H2 | `risk_class` on `StaffMember` — CNPS classifies the **employer's establishment** | Primary home `EmployerProfile.rp_risk_class`; permission-gated per-staff override only | §3.2 |
| H3 | `PayrollRun` scoped **per section** — ceiling, annualisation and bands are per employee per employer per month | Statutory computation is **employer-wide, once per month**; section allocation is downstream | §8.1 |
| H4 | `calculation = formula` as a UI-editable field = `eval()` (RCE) | Whitelisted expression grammar, parse-at-save, no function calls | §5.4 |
| H5 | No `calculation_order`, no dependency graph, no `irpp_amount` base for CAC | Explicit topological order with cycle detection | §7 |
| H6 | `StaffContract` and `StaffMember` were competing sources of truth | All employment attributes move to effective-dated, possibly-concurrent `StaffContract` | §3.3 |
| H7 | Retroactive rate edits silently rewrite history | Rates **append-only once referenced by an approved run**; overlap check; `effective_to` exclusive | §4.4 |
| H8 | No leave allowance engine — `StaffLeave` was a mutable balance | `LeaveAccrual` **signed-delta ledger**, `LeaveType` reference data, monthly provision posting | §12 |
| H9 | No termination settlement, no run reversal path | `TerminationSettlement`, `final_settlement` run type, `cancelled` + contrepassation | §13, §8.7 |
| H10 | No annual regularisation — this-month × 12 is correct only for perfectly flat pay | **YTD-cumulative IRPP is the default**, December regularisation run | §6.5 |

---

## 2. Domain background

### 2.1 The three CNPS branches

| Branch | Code | Borne by | Base | Ceiling |
|---|---|---|---|---|
| Prestations de Vieillesse, d'Invalidité et de Décès | `PVID` | employee **and** employer | `cnps_capped_base` | **750,000/month** |
| Prestations Familiales | `PF` | **employer only** | `cnps_capped_base` | **750,000/month** |
| Risques Professionnels | `RP` | **employer only** | `cnps_uncapped_base` | **NONE** |

This table is the whole of defect **N1**. It is why `PayrollItem` carries two CNPS bases and why `StatutoryRate.ceiling_amount` is **nullable**.

### 2.2 The non-CNPS statutory deductions

| Code | Name | Borne by | Shape | Basis |
|---|---|---|---|---|
| `IRPP` | Impôt sur le Revenu des Personnes Physiques | employee | annual progressive brackets | `net_categoriel` (§6) |
| `CAC` | Centimes Additionnels Communaux | employee | percentage | **`irpp_amount`** |
| `CFC` | Crédit Foncier du Cameroun | employee **and** employer | percentage | gross |
| `FNE` | Fonds National de l'Emploi | **employer only** | percentage | gross |
| `RAV` | Redevance Audio-Visuelle | employee | **flat amount per band** | band keyed on **gross** |
| `TDL` | Taxe de Développement Local | employee | **flat amount per band** | band keyed on **base salary** |

> RAV bands key on **gross**; TDL bands key on **base salary**. Using one basis for both shifts most of the staff a band. `StatutoryRate.basis` exists to make this impossible to get wrong by accident.

### 2.3 Reference values — NOT SEED DATA

Source: CLEISS, effective 2024. Subject to `00-core` §16 gate 8. Reproduced for implementation and test purposes only.

| Item | Reference value |
|---|---|
| CNPS PVID | 8.4% total = **4.2% employee + 4.2% employer** |
| CNPS ceiling | **750,000 FCFA/month** — **PVID and PF only** |
| CNPS PF (employer only) | 7% régime général · 5.65% agricole · **3.70% personnel de l'enseignement privé** |
| CNPS RP (employer only) | 1.75% / 2.50% / 5.00% by risk class · **no ceiling** |
| IRPP annual brackets | 10% ≤ 2,000,000 · 15% 2,000,001–3,000,000 · 25% 3,000,001–5,000,000 · 35% > 5,000,000 |
| Monthly net catégoriel | `0.70 × SBT − PVID_salariale − (500,000 / 12)` |
| 30% abatement cap | 4,800,000/year (400,000/month), since LF 2024 |
| CAC | **10% of the computed IRPP**, employee-borne |
| CFC | 1% employee / 1.5% employer, on gross |
| FNE | **1% employer** |
| Filing deadline | **15th of the following month**, both CNPS and DGI |
| CNPS worker registration | within **8 days** of hire |
| Annual leave accrual | **1.5 jours ouvrables** per month of effective service |
| Allocation de congé | ≥ **1/16** of remuneration over the preceding 12 months |
| CDD | max **2 years, renewable once** |
| Maternity | 14 weeks, indemnity via CNPS |

### 2.4 NEEDS VERIFICATION — ships EMPTY, never seeded

Each row below ships as a rate/table row with **NULL amounts**, or as an unpopulated reference table. Each is listed in the preflight (§9.1) and, where an enabled component depends on it, **blocks the run**.

| Item | Why it is empty | Blocks |
|---|---|---|
| **SMIG** | Sources conflict materially: 36,270 vs 60,000 FCFA | Minimum-wage floor validation on `StaffCompensation` |
| **Severance schedule** | Arrêté 016/MTPS/SG/CJ of 26/05/1993 — sources conflict materially | `TerminationSettlement` indemnité de licenciement |
| **Overtime premium tranches** | 15/20% vs 20/30/40% | `OVERTIME` component |
| **RAV band table** | No verified band boundaries or amounts | `RAV` component → **blocks payroll** |
| **TDL band table** | No verified band boundaries or amounts | `TDL` component → **blocks payroll** |
| **IRPP withholding floor** | Existence and threshold unverified | Applied as **no floor** until configured; flagged on every payslip preview |
| **Probation limits by category** | Unverified | `StaffContract.probation_end` validation |
| **Quotité cessible / saisissable table** | Unverified | `deduction_cap` (§5.7) → **blocks any non-statutory deduction** |
| **Benefits-in-kind barème** | Statutory valuation scale unverified | `BENEFIT_*` components |
| **DIPE fixed-layout field positions** | `cnps.cm/images/pdf/dipe.pdf` layout not verified field-by-field | Magnetic `.txt` export (§11.4) |
| **DIPE base rounding** | Whether bases round to nearest 100 | DIPE export only; payslip unaffected |
| **Applicable convention collective** | Which national convention covers the customer school | `CollectiveAgreement` / `SalaryScale` seeding |
| **Vacataire CNPS liability** | Requires labour-lawyer confirmation | Default is **CNPS-liable**; override is explicit, documented, audited (§5.6) |
| **Proration convention** | Calendar days vs 30-day month vs working days give three different answers; and whether the ceiling prorates | `proration_basis` on `EmployerProfile` — **no default, blocking** (§8.5) |
| **Leave provision account** | `Dr 66x / Cr 428x` — codes unverified | Leave provision posting only; leave accrual itself is unaffected |

---

## 3. Employer and staff identity

### 3.1 `EmployerProfile`

The school as an **employer**, distinct from `SchoolProfile` (identity/branding) and from the **fiscal identity** owned by `03-tax-procurement.md`. Effective-dated because a regime reclassification or a risk-class change from CNPS applies from a date and must not rewrite prior payslips.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `cnps_employer_number` | VARCHAR(32), `utf8mb4_0900_as_cs` | NOT NULL |
| `dipe_number` | VARCHAR(32), `as_cs` | NOT NULL |
| `niu` | VARCHAR(32), `as_cs` | Mirrors `SchoolProfile.niu`; validated equal at save |
| `dgi_centre` | VARCHAR(64) | DGE / CIME / CDI — drives DSF deadline (see `03`) |
| `tdl_commune_id` | BIGINT FK → `Commune` RESTRICT | TDL payee |
| `cnps_regime` | ENUM(`general`,`agricole`,`enseignement_prive`) | NOT NULL. Drives the PF rate row selected |
| `rp_risk_class` | VARCHAR(8) | NOT NULL. Drives the RP rate row selected |
| `cnps_notification_document_id` | BIGINT FK → `Document` RESTRICT | **NOT NULL** — the notification letter that evidences the two columns above |
| `cnps_notification_reference` | VARCHAR(64) | NOT NULL |
| `proration_basis` | ENUM(`calendar_days`,`thirty_day_month`,`working_days`) NULL | **NULL until configured; blocks any run containing a partial month** |
| `ceiling_prorates_partial_month` | BOOLEAN NULL | **NULL until configured; blocks as above** |
| `effective_from` | DATE NOT NULL | |
| `effective_to` | DATE NULL | Exclusive |
| `created_by`, `created_at` | | Actor FK RESTRICT |

- `UNIQUE (effective_from)`.
- Overlap check on `[effective_from, effective_to)` — at most one profile covers any date.
- `ON DELETE`: **RESTRICT** everywhere. Never deleted.
- **First-run wizard step, blocking.** The step renders the literal prompt: *"Open your CNPS notification letter. Confirm the regime and risk class printed on it. These two values determine every employer contribution you will pay."* Both fields require an affirmative confirmation checkbox, recorded in `AuditLog`.

### 3.2 Risk class — where it lives

`rp_risk_class` lives on `EmployerProfile` because **CNPS classifies the employer's activity per establishment and notifies the employer**. A per-teacher editable default drifts and produces a DIPE that cannot be reconciled against the CNPS's own assessment.

`StaffContract.rp_risk_class_override` (NULLABLE) exists only for genuinely multi-classified establishments (e.g. a school operating a licensed workshop). It is gated on permission `payroll.override_risk_class`, requires `override_reason` (NOT NULL when the override is set) and is surfaced on the run's exception report.

### 3.3 `StaffMember` — identity only

v1 put position, employment type, dates, grade and salary on both the person row *and* a "versioned" contract entity. Two sources of truth, and neither could represent a teacher who is also a boarding master, or a vacataire who converts to permanent in March.

`StaffMember` keeps **identity, statutory identifiers and payment coordinates. Nothing employment-related.**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `staff_no` | VARCHAR(32), `utf8mb4_0900_as_cs` | **UNIQUE** |
| `last_name`, `first_names` | VARCHAR, `utf8mb4_0900_ai_ci` | |
| `sex`, `date_of_birth`, `place_of_birth`, `nationality` | | |
| `national_id_type` | ENUM | |
| `national_id_number` | TEXT **encrypted** | `00-core` §9.5 |
| `national_id_blind_index` | BINARY(32) | **UNIQUE**. HMAC-SHA256 |
| `cnps_number` | TEXT **encrypted** | |
| `cnps_number_blind_index` | BINARY(32) | **UNIQUE** where non-null. Required by DIPE |
| `cnps_registration_status` | ENUM(`not_required`,`pending`,`registered`,`declared_departed`) | §11.5 |
| `cnps_registered_on`, `cnps_registration_deadline` | DATE | Deadline = hire date + **8 days** |
| `niu` | VARCHAR(32), `as_cs` | **UNIQUE** where non-null |
| `bank_name`, `bank_account`, `mobile_money_number` | TEXT **encrypted** | |
| `marital_status` | ENUM | Reporting and family-allowance entitlement only. **Not an IRPP input** (defect N3) |
| `next_of_kin_name`, `next_of_kin_relationship`, `next_of_kin_phone` | | |
| `photo_document_id` | BIGINT FK → `Document` SET NULL | |
| `status` | ENUM(`active`,`on_leave`,`suspended`,`terminated`,`retired`) | Person-level lifecycle |
| `is_archived` | BOOLEAN | `00-core` §10.5 — archive flag, never `SoftDeletes` |
| `version` | INT | |

- **`dependants_count` is deleted.** It does not exist on this entity. See `StaffDependant` (§3.6).
- **`risk_class` is deleted.** See §3.2.
- **`basic_salary`, `salary_grade_id`, `department_id`, `position`, `employment_type`, `contract_start/end`, `probation_end` are deleted.** See `StaffContract`.
- `ON DELETE`: **RESTRICT** from every direction. A staff member with any payroll, mark, allocation or audit history is never deleted; `is_archived` is the retirement mechanism.

### 3.4 `StaffContract` — the single source of employment truth

Effective-dated and **possibly concurrent**: one person may hold a teaching contract and a boarding-master contract simultaneously. `00-core` §10.1's "one active X" generated-column pattern is therefore applied **per `contract_role`**, not per staff member.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `staff_member_id` | BIGINT FK → `StaffMember` **RESTRICT** | |
| `contract_role` | VARCHAR(32) | e.g. `teaching`, `boarding`, `administration`. Concurrency key |
| `contract_type` | ENUM(`cdi`,`cdd`,`temporaire`,`occasionnel`,`saisonnier`,`apprentissage`,`stage`) | **Split from working time** |
| `working_time` | ENUM(`full_time`,`part_time`,`hourly`) | `hourly` = vacataire |
| `department_id` | BIGINT FK → `Department` RESTRICT | |
| `position_id` | BIGINT FK → `Position` RESTRICT | |
| `salary_grade_id` | BIGINT FK → `SalaryGrade` RESTRICT | NULL for pure hourly |
| `collective_agreement_id` | BIGINT FK RESTRICT | NULL until §2.4 resolves |
| `category`, `echelon` | VARCHAR(16) | Printed on the payslip — legally mandatory (§14.1) |
| `starts_on` | DATE NOT NULL | |
| `ends_on` | DATE NULL | Exclusive. NOT NULL required when `contract_type='cdd'` |
| `probation_end` | DATE NULL | Validation blocked pending §2.4 |
| `renewal_count` | INT NOT NULL DEFAULT 0 | |
| `renewed_from_contract_id` | BIGINT FK SELF RESTRICT NULL | |
| `converted_to_cdi_on` | DATE NULL | |
| `mintss_visa_ref` | VARCHAR(64) NULL | **Required when the staff member's nationality is non-Cameroonian** |
| `social_security_status` | ENUM(`affilie_cnps`,`assurance_volontaire`,`convention_bilaterale`,`detache_etat`,`exempt_other`) | §3.5 |
| `is_payroll_eligible` | BOOLEAN NOT NULL | **Distinct from `StaffMember.status`** |
| `rp_risk_class_override`, `override_reason` | VARCHAR NULL | §3.2 |
| `seniority_reference_date` | DATE | Drives prime d'ancienneté; may predate `starts_on` on conversion |
| `termination_reason` | ENUM NULL | |
| `active_role_key` | VARCHAR(32) **STORED GENERATED** | `CASE WHEN ends_on IS NULL OR ends_on > CURDATE() THEN contract_role END` |

Constraints:
- `UNIQUE KEY uq_active_contract_role (staff_member_id, active_role_key)` — one active contract per role per person.
- `CHECK (ends_on IS NULL OR ends_on > starts_on)`.
- `CHECK (contract_type <> 'cdd' OR ends_on IS NOT NULL)`.
- **CDD invariant:** `contract_type='cdd'` ⟹ `renewal_count ≤ 1` **and** total elapsed duration across the renewal chain ≤ **2 years**. Violating either raises `CddLimitExceeded` at save. A CDD chain that crosses the limit converts to CDI **by operation of law** — the system does not silently allow it, because it is a standard labour-inspection finding. The Action offers a single remediation: set `converted_to_cdi_on` and open a CDI contract.
- **Expiry alerts:** a scheduled job raises a notification at T−60, T−30 and T−7 days before `ends_on`, and an overdue alert after.
- `ON DELETE`: **RESTRICT**. A contract referenced by any `PayrollItemSnapshot` is immutable and undeletable.

### 3.5 `social_security_status` — the cases v1 could not express

| Value | Meaning | Payroll behaviour |
|---|---|---|
| `affilie_cnps` | Normal | All CNPS branches apply. **The run refuses to compute for this staff member if `cnps_number` is NULL** |
| `assurance_volontaire` | Individual pays their own CNPS | **No employer contribution line is emitted.** No employee PVID line |
| `convention_bilaterale` | Expatriate under a bilateral convention with a detachment certificate | CNPS branches suppressed per `exempt_from[]`; `exemption_document_ref` NOT NULL |
| `detache_etat` | Seconded/detached State teacher (*mis à disposition* from MINESEC/MINEDUB) — **extremely common in Cameroonian private schools**. On the roster and the timetable, paid by the State | Not on the school's DIPE. Either fully off payroll, or on payroll **for a top-up prime only** — in which case the prime is a taxable earning with `exempt_from[]` covering the CNPS branches |
| `exempt_other` | | Requires `exemption_document_ref` |

`StaffContractExemption` (child table): `contract_id`, `branch` (`PVID`|`PF`|`RP`|`IRPP`|`CFC`|`FNE`|`RAV`|`TDL`), `effective_from`, `effective_to`, `exemption_document_ref` NOT NULL, `approved_by`. `UNIQUE (contract_id, branch, effective_from)`; overlap-checked. Every exemption appears on the run's exception report — an exemption is a claim the labour inspector will test.

### 3.6 `StaffDependant`

Replaces the deleted `dependants_count`. **Feeds family-allowance entitlement, which is a benefit CNPS pays. It is not an input to any contribution rate (the PF rate is a flat sector rate independent of dependants) and it is not an input to IRPP (defect N3).**

`id`, `staff_member_id` FK RESTRICT, `full_name`, `relationship` (ENUM), `date_of_birth`, `is_schooled`, `cnps_allowance_eligible`, `birth_certificate_document_id`, `valid_from`, `valid_to`, `version`.
`UNIQUE (staff_member_id, full_name, date_of_birth)`.

### 3.7 Assignment, appraisal, discipline

- `StaffAssignment` — `staff_contract_id` × `class_group_id` × `subject_id` × `academic_year_id`. Gates marks entry in the Action layer (`01-assessment.md`). `UNIQUE (staff_contract_id, class_group_id, subject_id, academic_year_id)`. **Keyed on contract, not person** — a teacher who converts mid-year keeps their history on the old contract.
- `StaffAppraisal` — `staff_contract_id`, `period`, criteria rows, `score`, `reviewer_staff_id` RESTRICT, `status`, `acknowledged_at`.
- `StaffDisciplineCase` — `staff_member_id` **and** `staff_contract_id`, `case_ref`, `opened_on`, `sanction`, `document_id`, `closed_on`. Keyed on both for the same reason `07-students.md` C3 keys student discipline on both: the sanction ladder is a property of the person, the year filter is a property of the contract.
- `StaffCostAllocation` — `staff_contract_id`, `analytic_value_id` (FK → `02-accounting.md`), `percentage_bp`, `effective_from`, `effective_to`. **Invariant: Σ`percentage_bp` = 1,000,000 (100%) per contract per effective date**, asserted in-Action. A teacher working across nursery and primary must be split or the per-section analytic P&L is wrong. Allocation of *cost* happens after statutory computation (§8.1) and uses `Money::allocate` largest-remainder.

---

## 4. `StatutoryRate` — the rate model

### 4.1 Why v1's shape was unusable

v1's columns were `rate, ceiling, floor, bracket_from, bracket_to, employer_share, employee_share`. Two developers would read `PVID: rate=8.4` with `employer_share=50` (a proportion) or `employer_share=4.2` (an absolute rate) and both readings are defensible. And there was **no `flat_amount`**, so RAV and TDL — which are flat amounts per band — could not be represented at all, though v1 claimed they were supported.

### 4.2 Schema

```
StatutoryRate
├─ id                     BIGINT PK
├─ code                   VARCHAR(16) as_cs   PVID|PF|RP|IRPP|CAC|CFC|FNE|RAV|TDL
├─ label, label_fr        VARCHAR
├─ shape                  ENUM(percentage | flat_band | progressive_bracket)
├─ basis                  ENUM(basic | sbt | gross | taxable
│                              | cnps_capped | cnps_uncapped | irpp_amount)
├─ bracket_basis          ENUM(monthly | annual) NULL   -- IRPP = annual
├─ employee_rate_bp       BIGINT NULL   -- basis points per 00-core §7.2
├─ employer_rate_bp       BIGINT NULL
├─ flat_amount            BIGINT NULL   -- SIGNED whole FCFA, RAV/TDL
├─ ceiling_amount         BIGINT NULL   -- NULL means UNCAPPED (this is the N1 fix)
├─ floor_amount           BIGINT NULL
├─ band_from              BIGINT NULL   -- inclusive
├─ band_to                BIGINT NULL   -- EXCLUSIVE; NULL = open top band
├─ risk_class             VARCHAR(8) NULL   -- RP only
├─ cnps_regime            ENUM NULL         -- PF only
├─ effective_from         DATE NOT NULL
├─ effective_to           DATE NULL         -- EXCLUSIVE
├─ source_citation        VARCHAR(255) NOT NULL
├─ source_document_id     BIGINT FK Document RESTRICT NULL
├─ is_verified            BOOLEAN NOT NULL DEFAULT FALSE
├─ verified_by, verified_at
└─ locked                 BOOLEAN NOT NULL DEFAULT FALSE  -- set on first approved-run reference
```

Constraints and invariants:

1. `CHECK ( (employee_rate_bp IS NOT NULL OR employer_rate_bp IS NOT NULL) XOR (flat_amount IS NOT NULL) )` — a row carries **either** rates **or** a flat amount, never both.
2. `CHECK (shape <> 'flat_band' OR (band_from IS NOT NULL AND flat_amount IS NOT NULL))`.
3. `CHECK (shape <> 'progressive_bracket' OR bracket_basis IS NOT NULL)`.
4. `CHECK (code <> 'RP' OR ceiling_amount IS NULL)` — **RP can never be given a ceiling.** The N1 defect is made unrepresentable, not merely documented.
5. `CHECK (code <> 'RP' OR risk_class IS NOT NULL)`.
6. `CHECK (code <> 'PF' OR (cnps_regime IS NOT NULL AND employee_rate_bp IS NULL))` — PF is employer-only.
7. `CHECK (code NOT IN ('PF','RP','FNE') OR employee_rate_bp IS NULL)`.
8. `UNIQUE (code, risk_class, cnps_regime, band_from, effective_from)`.
9. **`is_verified = FALSE` ⟹ the rate is invisible to the engine.** Resolution (§4.3) treats unverified rows as absent.
10. `ON DELETE`: **RESTRICT** (`00-core` §10.5 names `StatutoryRate` explicitly).

### 4.3 Resolution

> **The date that drives selection is the payroll period END date, not the run date.** A March run executed on 4 April uses rates effective 31 March.

```
resolve(code, periodEnd, {riskClass?, cnpsRegime?, bandValue?}) :
    rows = StatutoryRate
        .where(code)
        .where(is_verified = true)
        .where(effective_from <= periodEnd)
        .where(effective_to IS NULL OR effective_to > periodEnd)   -- exclusive
        .where(risk_class matches or IS NULL)
        .where(cnps_regime matches or IS NULL)
    if shape = flat_band:
        rows = rows.where(band_from <= bandValue
                          AND (band_to IS NULL OR band_to > bandValue))
    if rows.count = 0  -> throw StatutoryRateUnresolved(code, periodEnd)
    if rows.count > 1  -> throw StatutoryRateAmbiguous(code, periodEnd)
    return rows.first
```

Both exceptions are **fatal to the run**. Neither has a fallback path. There is no default rate anywhere in the codebase; an architecture test asserts no numeric literal appears in any file under `app/Modules/Payroll/Domain/` outside a bracket-arithmetic helper.

**Property test:** for every code, sweep every day across a 10-year window and assert **exactly one** matching row or zero — never two. Run over the customer's real configured data in CI once fixtures exist.

### 4.4 Append-only history (H7)

Editing `effective_from` in place rewrites history: a January payslip silently recomputes under a March rate.

- On the first reference by an **approved** `PayrollRun`, `locked` is set TRUE by the approve Action.
- A `locked` row rejects every UPDATE except `effective_to` being set from NULL to a date ≥ the latest referencing period end.
- "Changing a rate" is therefore always: **close the current row** (set `effective_to`) and **insert a successor**. The UI exposes only this operation; there is no edit form for a locked row.
- **Overlap and gap check** per `(code, risk_class, cnps_regime, band_from)` on `[effective_from, effective_to)`, run at save and nightly.
- BEFORE UPDATE / BEFORE DELETE triggers on `statutory_rates` reject any write where `locked = 1` and the change is not the permitted `effective_to` closure. Application-layer enforcement alone is not sufficient for a table this consequential.

### 4.5 Bands (RAV, TDL) ship empty

`StatutoryRateBand` rows for RAV and TDL are **not created at all** until the customer supplies the table. The settings screen shows an explicit empty state: *"No RAV band table configured. Payroll cannot run. Supply the current band table from your DGI notice."* The `RAV` and `TDL` `PayrollComponent` rows exist, are `is_enabled = TRUE`, and fail preflight — which is the intended behaviour, because a school that silently pays no RAV is not compliant either.

---

## 5. Compensation and components

### 5.1 `StaffCompensation` — effective-dated pay (C7, arrears)

`basic_salary` as a mutable scalar on the person means a March raise changes every January payslip. Compensation is a **history**.

`id`, `staff_contract_id` FK RESTRICT, `component_code` (FK → `PayrollComponent.code`), `amount` BIGINT SIGNED **or** `rate_bp` BIGINT (XOR-checked), `effective_from`, `effective_to` (exclusive), `granted_by` RESTRICT, `grant_reason`, `retroactive_from` DATE NULL, `document_id`.
`UNIQUE (staff_contract_id, component_code, effective_from)`; overlap-checked.

**Arrears (rappel):** when `retroactive_from < effective_from` and the intervening months are already approved, the calculate Action generates `ARREARS` earning lines — one per affected month, each carrying `arrears_for_month` — computed as *(new entitlement for that month) − (amount actually paid per that month's snapshot)*. Snapshots are never mutated (§10). Arrears are taxable in the month of payment and enter the YTD-cumulative IRPP base there, which is precisely why §6.5 exists.

### 5.2 `PayrollComponent`

```
PayrollComponent
├─ id, code (VARCHAR(24) as_cs, UNIQUE), name, name_fr
├─ type              ENUM(earning | employee_deduction | employer_charge | informational)
├─ calculation       ENUM(fixed | percentage | hourly | table | formula | statutory)
├─ basis             ENUM(basic | gross | sbt | taxable | cnps_capped
│                         | cnps_uncapped | irpp_amount | net) NULL
├─ statutory_rate_code   VARCHAR(16) NULL   ← H: this is how PVID finds its rate
├─ formula_expression    TEXT NULL          ← §5.4 grammar only
├─ calculation_order     INT NOT NULL       ← §7
├─ depends_on            JSON  (array of component codes)
├─ is_taxable            BOOLEAN   -- enters SBT
├─ is_cnps_liable        BOOLEAN   -- enters the CNPS bases
├─ is_prorated           BOOLEAN   -- subject to §8.5 partial-month proration
├─ subject_to_deduction_cap BOOLEAN
├─ expense_account_id, liability_account_id   FK ChartOfAccount RESTRICT
├─ analytic_axis_behaviour  ENUM(follow_staff_allocation | fixed_value | none)
├─ print_group, print_order   -- payslip layout
├─ is_enabled, is_system
├─ effective_from, effective_to
└─ version
```

- `CHECK (calculation <> 'statutory' OR statutory_rate_code IS NOT NULL)`.
- `CHECK (calculation <> 'formula' OR formula_expression IS NOT NULL)`.
- `is_system = TRUE` components (PVID, PF, RP, IRPP, CAC, CFC, FNE, RAV, TDL, NET) cannot be deleted or have their `calculation_order` edited.
- `ON DELETE`: **RESTRICT** where any `PayrollLine` references the component.

### 5.3 The system component set

| Code | Type | Calculation | Basis | Order |
|---|---|---|---|---|
| `BASIC` | earning | fixed | — | 100 |
| `HOURLY` | earning | hourly | — | 110 |
| `OVERTIME` | earning | table | basic | 120 |
| `SENIORITY` | earning | percentage | basic | 130 |
| `ALLOWANCE_*` | earning | fixed/percentage | basic | 140 |
| `BENEFIT_*` | earning (in kind) | table | — | 150 |
| `ARREARS` | earning | fixed | — | 160 |
| `THIRTEENTH` | earning | formula | — | 170 |
| `ALLOCATION_CONGE` | earning | formula | — | 180 |
| — *bases materialised* — | | | | **200** |
| `PVID_EE` | employee_deduction | statutory | cnps_capped | 300 |
| `PVID_ER` | employer_charge | statutory | cnps_capped | 310 |
| `PF` | employer_charge | statutory | cnps_capped | 320 |
| `RP` | employer_charge | statutory | **cnps_uncapped** | 330 |
| `IRPP` | employee_deduction | statutory | sbt (+ PVID_EE) | 400 |
| `CAC` | employee_deduction | statutory | **irpp_amount** | 410 |
| `CFC_EE` | employee_deduction | statutory | gross | 420 |
| `CFC_ER` | employer_charge | statutory | gross | 430 |
| `FNE` | employer_charge | statutory | gross | 440 |
| `RAV` | employee_deduction | statutory (flat_band) | gross | 450 |
| `TDL` | employee_deduction | statutory (flat_band) | **basic** | 460 |
| `LOAN_*`, `ADVANCE_*`, `FINE_*` | employee_deduction | fixed | — | 600 |
| `NET` | informational | formula | — | 900 |

### 5.4 The `formula` grammar (H4)

`calculation = formula` as a free-text UI field is either `eval()` — remote code execution reachable by any user with settings access — or an unspecified DSL that two developers implement differently. Combined with editable `PostingRule` conditions (`02-accounting.md` C1) it is the product's largest injection surface.

**Whitelisted grammar. Nothing else parses.**

```
expr    := term (('+' | '-') term)*
term    := factor (('*' | '/') factor)*
factor  := NUMBER | VARIABLE | '(' expr ')' | ('-' factor)
          | 'min' '(' expr ',' expr ')'
          | 'max' '(' expr ',' expr ')'
VARIABLE := one of a fixed, code-defined identifier set
            (basic, gross, sbt, taxable, cnps_capped, cnps_uncapped,
             irpp_amount, days_worked, days_in_period, hours_taught,
             ytd_sbt, ytd_irpp_withheld, <component_code>)
NUMBER   := integer literal (FCFA or basis points; no floats)
```

- **No function calls beyond `min`/`max`. No identifiers outside the fixed set. No string literals. No assignment. No property access. No dynamic evaluation of any kind.**
- Parsed to an AST **at save time**, not at run time. A parse failure or an unknown identifier rejects the save with the offending token position.
- Division by a literal zero is rejected at parse; division by a runtime zero raises `FormulaDivisionByZero` and fails the run.
- Arithmetic evaluates in integer FCFA. Intermediate division retains a rational representation until the single component-level rounding (§8.6).
- **Every formula component stores at least one unit test** (`PayrollComponentTest`: named input vector → expected integer output), executed in CI and re-executed at save. A formula with no passing test cannot be enabled.
- The settings screen provides a **dry-run preview** against a chosen staff member and month, showing the resolved variable values and the result, without writing anything.

### 5.5 Hourly / vacataire payroll (C6)

The product could not pay most teaching staff at a typical Cameroonian private school. `working_time = hourly` is a first-class path.

```
HourlyRate
├─ id
├─ scope         ENUM(staff | grade | class_level)
├─ staff_contract_id | salary_grade_id | class_level_id   (exactly one non-null)
├─ subject_id    NULL          -- optional per-subject differentiation
├─ rate_per_hour BIGINT SIGNED
├─ effective_from, effective_to (exclusive)
└─ UNIQUE (scope, staff_contract_id, salary_grade_id, class_level_id,
           subject_id, effective_from); overlap-checked
```

Resolution precedence: **staff → grade → class_level**, most specific wins; a tie is a configuration error rejected at save.

```
TeachingHoursLog
├─ id, staff_contract_id FK RESTRICT
├─ payroll_month DATE (first of month)
├─ class_group_id, subject_id, timetable_slot_id NULL
├─ hours_planned    DECIMAL(6,2)   -- from the timetable
├─ hours_taught     DECIMAL(6,2)   -- adjusted by staff attendance
├─ hours_validated  DECIMAL(6,2) NULL
├─ validated_by FK RESTRICT NULL, validated_at
├─ status  ENUM(draft | submitted | validated | rejected)
└─ UNIQUE (staff_contract_id, payroll_month, class_group_id,
           subject_id, timetable_slot_id)
```

- `hours_planned` is seeded from the timetable (`Academics`); `hours_taught` is reduced by staff-attendance absences. Both are **proposals**. Only `hours_validated` reaches payroll.
- **Payroll refuses to include any hourly staff member whose month is not fully `validated`.** No partial inclusion, no "assume planned".
- `Timesheet` is the non-teaching analogue (`hours_worked`, same lifecycle) for hourly administrative staff.
- `HOURLY` earning = `Σ hours_validated × resolve(HourlyRate)` per (class_level, subject) segment, summed, rounded once.

> **Legal note.** Vacataires are generally still **employees** for CNPS purposes. Misclassifying them as independent contractors is the single largest CNPS-audit exposure a school carries. The system therefore **defaults `social_security_status = affilie_cnps` for `working_time = hourly`**. Overriding it requires permission `payroll.classify_non_employee`, a mandatory `exemption_document_ref`, an explicit reason, and produces an entry on every subsequent run's exception report. *This default is NEEDS VERIFICATION with a labour lawyer (`00-core` §16 gate 9 answered "mixed"; the classification question is separate and open).*

### 5.6 Benefits in kind

Schools routinely house teachers on campus. Housing, vehicle, domestic staff and utilities are in **both** the CNPS contribution base **and** the taxable base, valued per the statutory barème.

`BENEFIT_HOUSING`, `BENEFIT_VEHICLE`, `BENEFIT_DOMESTIC`, `BENEFIT_UTILITIES` exist as components with `is_taxable = TRUE`, `is_cnps_liable = TRUE`, `calculation = table`. **The barème table ships EMPTY (§2.4).** Enabling any benefit component without a configured barème fails preflight.

### 5.7 Deduction cap

Cameroon caps the assignable/attachable portion of salary (*quotité cessible et saisissable*). Deducting beyond it is unlawful.

- The cap table is **EMPTY** (§2.4).
- **Consequence:** while the table is unconfigured, any `PayrollItem` carrying a component with `subject_to_deduction_cap = TRUE` (loans, advances, library fines from `06-assets-stores.md`, disciplinary deductions) **fails preflight**. Statutory deductions are exempt from the cap and are unaffected — a school with no loans can run payroll on day one.
- When configured: `capped = min(Σ cappable deductions, cap(base))`. The excess is **not discarded** — it is carried forward as `DeductionCarryForward` (staff_contract_id, source_component, amount, created_from_payroll_month, settled_at) and re-presented next month. Silently dropping it makes a loan un-repayable.

---

## 6. IRPP — written literally

### 6.1 Definitions

| Symbol | Meaning |
|---|---|
| `SBT` | *Salaire Brut Taxable* — sum of all earning components with `is_taxable = TRUE`, for the month |
| `SBC` | CNPS-liable gross — sum of all earnings with `is_cnps_liable = TRUE` |
| `cnps_capped_base` | `min(SBC, ceiling)` where `ceiling` = `resolve('PVID').ceiling_amount` (reference: 750,000) |
| `cnps_uncapped_base` | `SBC`, **no cap** |
| `PVID_EE` | `round_half_up( cnps_capped_base × employee_rate_bp / 1,000,000 )` |
| `A30` | 30% professional abatement, **capped** |
| `A_FIX` | fixed abatement, 500,000 FCFA **per year** |
| `NC` | *net catégoriel* — the IRPP base |

### 6.2 The formula, literally

**Monthly statement (as published):**

```
NC_month = 0.70 × SBT − PVID_EE − (500,000 / 12)
```

**Expanded, with the abatement cap made explicit:**

```
A30_month = min( 0.30 × SBT , 400,000 )
NC_month  = SBT − A30_month − PVID_EE − (500,000 / 12)
```

**The 30% abatement applies to SBT. PVID is deducted separately, at 100%.** It is *not* `0.70 × (SBT − PVID_EE)`. See §6.4 for the cost of the wrong reading.

### 6.3 The canonical engine path

Brackets are **annual**. Monthly withholding must not apply annual brackets to a monthly base, nor monthly-equivalent brackets to an annual base. A seed/engine mismatch here is a **12× error**.

`StatutoryRate.bracket_basis = 'annual'` for IRPP, and there is **exactly one** code path:

```
ANNUALISE  ->  APPLY ANNUAL BRACKETS  ->  DIVIDE BY 12  ->  ROUND ONCE
```

Written out (flat-pay case; the general case is §6.5):

```
1.  SBT_annual   = SBT_month × 12
2.  A30_annual   = min( 0.30 × SBT_annual , 4,800,000 )
3.  PVID_annual  = PVID_EE_month × 12
4.  NC_annual    = SBT_annual − A30_annual − PVID_annual − 500,000
5.  NC_annual    = max( NC_annual , 0 )
6.  IRPP_annual  = Σ over brackets b of
                     rate_b × ( min(NC_annual, upper_b) − lower_b )   for NC_annual > lower_b
7.  IRPP_month   = round_half_up( IRPP_annual / 12 )
8.  CAC_month    = round_half_up( IRPP_month × cac_rate_bp / 1,000,000 )
```

Notes that are load-bearing:
- Steps 1–6 are computed in **exact rational arithmetic**; the only rounding is step 7 (and step 8 on the already-rounded IRPP). This satisfies `00-core` §7.3 "round once, at component level".
- **CAC is computed on the rounded monthly `irpp_amount`**, because that is the amount actually withheld and the amount the DGI return carries. This is why `StatutoryRate.basis` includes `irpp_amount` and why `CAC.calculation_order` (410) is strictly after `IRPP` (400).
- **The IRPP withholding floor is NEEDS VERIFICATION (§2.4).** Until configured, **no floor is applied**. The payslip preview and the run exception report both carry: *"No IRPP withholding floor is configured. Tax has been computed from the first franc."*

**Annual bracket table** (reference values, §2.3 — not seeded):

| Band (annual NC) | Rate |
|---|---|
| 0 – 2,000,000 | 10% |
| 2,000,001 – 3,000,000 | 15% |
| 3,000,001 – 5,000,000 | 25% |
| > 5,000,000 | 35% |

**Golden tests at every bracket boundary, in both directions:** `NC_annual` ∈ {1,999,999 / 2,000,000 / 2,000,001 / 2,999,999 / 3,000,000 / 3,000,001 / 4,999,999 / 5,000,000 / 5,000,001} and the corresponding `SBT_month` that produces each, asserted to the franc.

### 6.4 Worked examples

Assumptions common to all four: monthly gross = SBT = SBC (no non-taxable elements, no benefits in kind); PVID employee 4.2%; ceiling 750,000; CFC employee 1%; CAC 10% of IRPP; RAV and TDL **not computed — band tables empty**, so in a real run these examples would be *blocked at preflight*. They are shown to the statutory-deduction stage.

---

#### Example A — SBT = 80,000 FCFA/month

```
cnps_capped_base = min(80,000, 750,000)              = 80,000
PVID_EE          = 80,000 × 4.2%                     =  3,360

SBT_annual       = 80,000 × 12                       =   960,000
A30_annual       = min(0.30 × 960,000 , 4,800,000)
                 = min(288,000 , 4,800,000)          =   288,000
PVID_annual      = 3,360 × 12                        =    40,320
NC_annual        = 960,000 − 288,000 − 40,320 − 500,000 = 131,680

IRPP_annual      = 10% × 131,680                     =    13,168
IRPP_month       = 13,168 / 12 = 1,097.333…  →  round =   1,097
CAC_month        = 10% × 1,097 = 109.7        →  round =     110
CFC_EE           = 1% × 80,000                       =       800
```

Employer side: `PVID_ER` 3,360 · `PF` = 3.70% × 80,000 = 2,960 (enseignement privé) · `RP` = risk-class rate × **80,000 uncapped** · `CFC_ER` 1,200 · `FNE` 800.

`NET (before RAV/TDL)` = 80,000 − 3,360 − 1,097 − 110 − 800 = **74,633**

---

#### Example B — SBT = 250,000 FCFA/month

```
PVID_EE          = 250,000 × 4.2%                    =    10,500

SBT_annual       = 3,000,000
A30_annual       = min(900,000 , 4,800,000)          =   900,000
PVID_annual      = 126,000
NC_annual        = 3,000,000 − 900,000 − 126,000 − 500,000 = 1,474,000

IRPP_annual      = 10% × 1,474,000                   =   147,400
IRPP_month       = 147,400 / 12 = 12,283.333… → round =    12,283
CAC_month        = 10% × 12,283 = 1,228.3     → round =     1,228
CFC_EE           = 1% × 250,000                      =     2,500
```

`NET (before RAV/TDL)` = 250,000 − 10,500 − 12,283 − 1,228 − 2,500 = **223,489**

**The N4 counterexample, at this salary.** Reading the abatement as `0.70 × (SBT − PVID)`:

```
WRONG:  NC_annual = 0.70 × (3,000,000 − 126,000) − 500,000
                  = 0.70 × 2,874,000 − 500,000
                  = 2,011,800 − 500,000            = 1,511,800
CORRECT: NC_annual                                 = 1,474,000
```

The wrong base is **37,800 FCFA/year higher**, and it also **crosses the 2,000,000 boundary differently at higher salaries**. Here the excess tax is 10% × 37,800 = **3,780 FCFA/year, 315 FCFA/month over-withheld** — on every employee, every month, invisibly, and owed back to them.

---

#### Example C — SBT = 900,000 FCFA/month *(above the ceiling — the N1 case)*

```
cnps_capped_base   = min(900,000 , 750,000)          =   750,000
cnps_uncapped_base =                                     900,000     ← RP uses this
PVID_EE            = 750,000 × 4.2%                  =    31,500

SBT_annual         = 10,800,000
A30_annual         = min(3,240,000 , 4,800,000)      = 3,240,000     (cap not binding)
PVID_annual        = 378,000
NC_annual          = 10,800,000 − 3,240,000 − 378,000 − 500,000 = 6,682,000

IRPP_annual:
   10% ×  2,000,000                                  =   200,000
   15% ×  1,000,000   (2,000,001–3,000,000)          =   150,000
   25% ×  2,000,000   (3,000,001–5,000,000)          =   500,000
   35% ×  1,682,000   (6,682,000 − 5,000,000)        =   588,700
                                              total  = 1,438,700
IRPP_month         = 1,438,700 / 12 = 119,891.666… → = 119,892
CAC_month          = 10% × 119,892 = 11,989.2      → =  11,989
CFC_EE             = 1% × 900,000                    =   9,000
```

`NET (before RAV/TDL)` = 900,000 − 31,500 − 119,892 − 11,989 − 9,000 = **727,619**

Employer side, showing the two different bases:

```
PVID_ER = 4.2%  × 750,000 (capped)   =  31,500
PF      = 3.70% × 750,000 (capped)   =  27,750
RP      = rate  × 900,000 (UNCAPPED) ← v1 would have used 750,000 here
CFC_ER  = 1.5%  × 900,000            =  13,500
FNE     = 1%    × 900,000            =   9,000
```

At RP 1.75% the v1 shortfall on this one employee is `1.75% × 150,000 = 2,625 FCFA/month`, accruing surcharges until a CNPS inspection finds it.

---

#### Example D — SBT = 1,500,000 FCFA/month *(the abatement cap binds)*

```
cnps_capped_base   = 750,000
PVID_EE            = 31,500

SBT_annual         = 18,000,000
0.30 × 18,000,000  = 5,400,000   >   4,800,000  →  CAP BINDS
A30_annual         = 4,800,000
PVID_annual        = 378,000
NC_annual          = 18,000,000 − 4,800,000 − 378,000 − 500,000 = 12,322,000

IRPP_annual:
   10% × 2,000,000                                   =   200,000
   15% × 1,000,000                                   =   150,000
   25% × 2,000,000                                   =   500,000
   35% × 7,322,000                                   = 2,562,700
                                              total  = 3,412,700
IRPP_month         = 3,412,700 / 12 = 284,391.666… → =  284,392
CAC_month          = 10% × 284,392 = 28,439.2      → =   28,439
CFC_EE             = 1% × 1,500,000                  =   15,000
```

`NET (before RAV/TDL)` = 1,500,000 − 31,500 − 284,392 − 28,439 − 15,000 = **1,140,669**

**Regression test for the cap:** without the 4,800,000 cap, `A30_annual` would be 5,400,000, `NC_annual` 11,722,000, `IRPP_annual` 3,202,700 — **210,000 FCFA/year under-withheld** on one employee. The cap is a LF 2024 change; a system built against pre-2024 documentation is wrong in the school's disfavour.

### 6.5 YTD-cumulative IRPP is the default (H10)

§6.3's annualisation is exact **only when pay is perfectly flat**. Any bonus, arrears payment, raise, mid-year joiner or 13th month makes `this month × 12` a fiction, and December explodes: a 13th month annualised as if it recurred twelve times produces massive over-withholding, and the employee has no mechanism to reclaim it.

**Default engine mode: `irpp_mode = ytd_cumulative`** on `EmployerProfile`.

For payroll month *m* (m = 1…12 of the **fiscal** year, which OHADA fixes at 1 Jan – 31 Dec — see `02-accounting.md`):

```
1. ΣSBT_m       = Σ SBT over months 1..m         (from prior PayrollItemSnapshots)
2. ΣPVID_m      = Σ PVID_EE over months 1..m
3. A30_m        = min( 0.30 × ΣSBT_m , 400,000 × m )
4. NC_ytd       = ΣSBT_m − A30_m − ΣPVID_m − (500,000 × m / 12)
5. NC_projected = NC_ytd × 12 / m
6. TAX_proj     = brackets( max(NC_projected, 0) )
7. TAX_due_ytd  = TAX_proj × m / 12
8. IRPP_month   = round_half_up( TAX_due_ytd ) − Σ IRPP withheld in months 1..m-1
9. IRPP_month   = max( IRPP_month , 0 )      -- never a negative withholding line
```

- Step 9's clamp means an over-withheld employee is corrected by **reduced future withholding**, not a negative deduction. If m = 12 and the clamp binds, the residual is settled by the **December regularisation run** (§8.7) as an `IRPP_REGULARISATION` earning line, which is a refund to the employee and a negative movement on the DGI liability.
- **Equivalence test:** for perfectly flat pay, YTD-cumulative and §6.3 annualisation must produce identical monthly amounts for m = 1…12. Property-tested at Examples A–D.
- `ΣSBT` and `Σ IRPP withheld` are read from **`PayrollItemSnapshot`**, never recomputed (§10). A mid-year joiner's prior-employer income is **not** included — Cameroon's monthly withholding is per-employer; the employee reconciles on their own annual return.
- The **annual salary certificate** (§14.3) gives the employee the figures they need for that return.

---

## 7. Calculation order and the dependency graph

v1 had no ordering and no `irpp_amount` base, so CAC could not be computed from IRPP at all. Order is not an implementation detail here; it is arithmetic.

### 7.1 The graph

```
                         [contract, compensation, timesheet, attendance]
                                          │
        ┌─────────────────────────────────┼─────────────────────────────┐
        ▼                                 ▼                             ▼
     BASIC ─────► SENIORITY          HOURLY (hours×rate)          BENEFIT_*
        │              │                  │                            │
        ├──► OVERTIME ─┤                  │                     ARREARS│ THIRTEENTH
        ├──► ALLOWANCE_*                  │                            │ ALLOCATION_CONGE
        │              │                  │                            │
        └──────────────┴──────────────────┴────────────────────────────┘
                                          │
                                          ▼
                            ══════ BASES (order 200) ══════
                     gross · SBT (is_taxable) · SBC (is_cnps_liable)
                     cnps_capped_base = min(SBC, ceiling)
                     cnps_uncapped_base = SBC
                                          │
        ┌──────────────┬──────────────┬───┴──────────┬──────────────┐
        ▼              ▼              ▼              ▼              ▼
    PVID_EE        PVID_ER          PF             RP          CFC_EE/ER
   (capped)        (capped)      (capped)      (UNCAPPED)       (gross)
        │                                                            FNE
        │                                                          (gross)
        ▼
      IRPP   ◄── needs SBT, PVID_EE, YTD snapshots, annual brackets
        │
        ▼
      CAC    ◄── basis = irpp_amount  (NOT gross — this edge did not exist in v1)
        │
        ├──────────────► RAV  (flat_band on gross)
        ├──────────────► TDL  (flat_band on BASIC)
        │
        ▼
   LOAN_* / ADVANCE_* / FINE_*   ── subject to deduction cap (§5.7)
        │
        ▼
      NET = gross − Σ(employee_deduction)
```

### 7.2 Execution rules

1. Components execute in ascending `calculation_order`. Ties are resolved by `code` ascending — deterministic, never insertion order.
2. `depends_on` is validated as a **DAG at save time**. The validator runs Kahn's algorithm; a cycle rejects the save naming the cycle members. A component may not depend on one with a higher or equal `calculation_order`.
3. **Base materialisation at order 200 is a barrier.** No earning may be added after it; no statutory component may be evaluated before it. Architecture test enforced.
4. Employer charges are computed in the same pass but are **never** subtracted from net. `CHECK`: no `employer_charge` component appears in the `NET` formula. Invariant test: `gross − Σ(employee_deduction) = net`, **exactly**, on every payslip (`00-core` §7.3).
5. A component whose statutory rate is unresolved does not produce a zero line. It **throws** and fails the run.

---

## 8. The payroll run

### 8.1 `PayrollRun` is employer-wide (H3)

> v1 scoped runs **per section**. The ceiling, the IRPP annualisation, the RAV/TDL bands and family-allowance entitlement are all **per employee, per employer, per month**. A staff member appearing in a nursery run and a primary run gets **two ceilings and two band applications** — systematically wrong contributions and a DIPE that cannot reconcile.

**Statutory amounts are computed employer-wide, once per month. Cost is allocated to sections afterwards**, via `StaffCostAllocation` (§3.7) using `Money::allocate` largest-remainder so the allocated parts sum exactly to the computed cost.

```
PayrollRun
├─ id
├─ payroll_month        DATE (first day of month)   -- 00-core §5 vocabulary
├─ run_type             ENUM(regular | thirteenth_month | final_settlement
│                            | regularisation | reversal)
├─ status               ENUM(draft | calculating | calculated | approved
│                            | paid | closed | cancelled)
├─ fiscal_year_id       FK RESTRICT   ┐ 02-accounting C3: BOTH calendars
├─ academic_year_id     FK RESTRICT   ┘
├─ accounting_period_id FK RESTRICT
├─ employer_profile_id  FK RESTRICT   -- the profile version in force
├─ inputs_hash          CHAR(64)      -- SHA-256, set at calculate
├─ calculated_by/at, approved_by/at, paid_at, closed_at, cancelled_by/at
├─ cancellation_reason  TEXT NULL
├─ reverses_run_id      FK SELF RESTRICT NULL, UNIQUE
├─ journal_entry_id     FK RESTRICT NULL
├─ idempotency_key      VARCHAR(64) UNIQUE    -- 00-core §6.2 rule 7
└─ version
```

- `UNIQUE (payroll_month, run_type, employer_profile_id)` where `status <> 'cancelled'`, via the generated-column pattern of `00-core` §10.1.
- **`UNIQUE (payroll_month, staff_member_id)` across all runs** on `PayrollItem` (`00-core` §10.4) — the constraint that makes double payment structurally impossible. Scoped to exclude `cancelled` runs via a stored generated column.
- `ON DELETE`: **RESTRICT**. An approved run is never deleted; see §8.7.

### 8.2 Locking

Per `00-core` §11: `SELECT … FOR UPDATE` on the `PayrollRun` row **plus** a named advisory lock on `(employer_profile_id, payroll_month)` held for the duration of calculate and of approve. The advisory lock is what prevents two *different* run types racing on the same month.

### 8.3 `inputs_hash`

Computed at `calculate` over a canonical, ordered serialisation of: every included contract's employment attributes, every `StaffCompensation` row in force, every validated timesheet total, days worked, every resolved `statutory_rate_id` **with its amount columns**, `EmployerProfile` version, and the enabled `PayrollComponent` set with their `calculation_order` and `formula_expression`.

**Re-verified at `approve`. If it differs, approval fails** with a diff of what changed and who changed it. It does not silently recalculate — a run someone approved is a run someone *reviewed*.

### 8.4 Inputs

| Input | Source | Rule |
|---|---|---|
| `days_worked` | staff attendance registers + leave ledger | **Mandatory. DIPE requires days worked per employee per month** (§11.4). Without it CNPS pension quarters are mis-recorded, a harm that surfaces decades later |
| `days_in_period` | `EmployerProfile.proration_basis` | **Blocking until configured** |
| `hours_validated` | `TeachingHoursLog` / `Timesheet` | Must be `validated`; otherwise the staff member is excluded and reported |
| Compensation | `StaffCompensation` in force at period end | |
| Rates | `resolve(code, period_end)` | §4.3 |

### 8.5 Mid-period joiners, leavers and suspended staff

Calendar days, a 30-day month and working days give **three different answers**. The convention is therefore **configuration, not code**, and it is **NULL until the customer decides** (`EmployerProfile.proration_basis`, §2.4). A run containing any partial month fails preflight while it is NULL.

Once configured:

```
prorated_amount = round_half_up( full_amount × days_worked / days_in_period )
```

applied only to components with `is_prorated = TRUE`. `BASIC` and fixed allowances prorate; flat-band deductions (RAV, TDL) do not; hourly earnings are inherently proportional and are never additionally prorated.

**Whether the CNPS ceiling itself prorates for a partial month is NEEDS VERIFICATION** — `EmployerProfile.ceiling_prorates_partial_month` is NULL and blocking.

Treatment by `StaffMember.status` × `StaffContract.is_payroll_eligible`:

| status | Pay | Contributions |
|---|---|---|
| `active` | Full | Full |
| `on_leave` (paid) | Full, per `LeaveType.payer` | Full |
| `on_leave` (CNPS-paid, e.g. maternity) | Employer **advances**; claims reimbursement (§11.6) | Full |
| `on_leave` (unpaid) | Zero earnings | Zero base ⟹ zero contributions. Days worked reduced |
| `suspended` | Per the suspension decision; `is_payroll_eligible` drives inclusion | If unpaid, zero base |
| `terminated`, `retired` | Excluded from `regular`; included in `final_settlement` only | Per settlement |

### 8.6 Rounding

Per `00-core` §7.3: **round once, at component level, half-up to 1 FCFA.** No intermediate rounding inside a component's own arithmetic; no rounding of bases.

Invariant, asserted inside the Action on every `PayrollItem` before persist:

```
gross − Σ(employee_deduction lines) = net        -- exactly, no tolerance
```

A failure raises and rolls back the whole run. There is no "off by one franc" tolerance because the tolerance is where the bug hides.

*DIPE bases may require rounding to the nearest 100 — NEEDS VERIFICATION (§2.4). If so it applies to the **export only**, never to the payslip or the ledger.*

### 8.7 Lifecycle, reversal and regularisation

```
draft ─calculate─► calculated ─approve─► approved ─pay─► paid ─close─► closed
                        │                    │
                        └──────discard───────┘
                                             └──── reverse ────► (new run,
                                                    run_type=reversal)
                                                    original → cancelled
```

- Every transition uses **conditional UPDATE with affected-rows check** (`WHERE status = 'calculated'`), never read-then-write (`00-core` §10.4).
- **No approved run is ever mutated.** Mirroring the `Payment` void pattern of `04-fees.md`: reversal creates a **new** `PayrollRun` with `run_type = 'reversal'`, `reverses_run_id` set (**UNIQUE**, and reversing a reversal is forbidden), which contrepasses the original journal entry **dated in the earliest open accounting period** — never the original date (`02-accounting.md` C9) — and voids the payslips. The original run's status becomes `cancelled`; its `PayrollItemSnapshot` rows remain readable forever.
- **Segregation of duties:** `calculated_by <> approved_by` is enforced. The approver requires permission `payroll.approve`; reversal requires `payroll.reverse` and a mandatory `cancellation_reason`.
- `run_type = 'regularisation'` is the December run implementing §6.5 step 9's residual settlement.
- Posting is governed by `02-accounting.md`. On approval: gross to class 6 (split by analytic axis via `StaffCostAllocation`), net to class 42 staff payable, each employee deduction to its class 4 State/CNPS liability, each employer charge to class 6 with the matching class 4 liability. Multi-line `PostingRule` (8–12 lines) — the exact case `02-accounting.md` C1 exists to support. Events dispatch **after commit** (`00-core` §6.2 rule 6).

### 8.8 `PayrollPayment`

v1's "on payment: clear the payables" had no operational trigger and no entity.

`id`, `payroll_run_id` FK RESTRICT, `payment_method_id` FK (`04-fees.md` `PaymentMethod` table — bank transfer, mobile money, cash), `treasury_account_id`, `value_date`, `total_amount`, `disbursement_file_id`, `status` (`prepared|exported|confirmed|partially_failed`), `exported_by/at`, `journal_entry_id`.

`PayrollPaymentLine`: `payroll_item_id` FK RESTRICT, `staff_member_id`, `amount`, `beneficiary_account` (decrypted at export only), `status`, `failure_reason`.

Exports a **bank / mobile-money disbursement file** in a configurable fixed-layout or CSV format. A failed line does not fail the run; it moves to `partially_failed` and is re-exportable, with an idempotency key preventing double disbursement.

---

## 9. Preflight — how payroll refuses to run

### 9.1 `PayrollPreflightCheck`

Executed at the head of `CalculatePayrollRun`, inside the transaction, before any computation. **Every failure is fatal.** There is no "proceed anyway".

| # | Check | Failure |
|---|---|---|
| 1 | An `EmployerProfile` covers the period end | `EmployerProfileMissing` |
| 2 | `cnps_regime` and `rp_risk_class` set, with `cnps_notification_document_id` | `EmployerRegimeUnconfirmed` |
| 3 | `proration_basis` and `ceiling_prorates_partial_month` set **if** any staff member has a partial month | `ProrationConventionUnconfigured` |
| 4 | For every **enabled** component with `calculation = statutory`: `resolve()` returns exactly one **verified** row | `StatutoryRateUnresolved(code)` / `StatutoryRateAmbiguous(code)` |
| 5 | RAV and TDL band tables cover the full range of every included employee's basis value, with no gap | `StatutoryBandCoverageIncomplete(code, uncovered_range)` |
| 6 | IRPP brackets are **contiguous, non-overlapping, starting at 0, top band open** | `BracketCoverageInvalid` |
| 7 | Every enabled `formula` component has a passing stored unit test | `FormulaTestFailing(code)` |
| 8 | Every `affilie_cnps` staff member has a `cnps_number` | `CnpsNumberMissing(staff_no[])` |
| 9 | Every hourly staff member's month is fully `validated` | `TimesheetNotValidated(staff_no[])` |
| 10 | `days_worked` resolvable for every included staff member | `DaysWorkedUnavailable` |
| 11 | Deduction cap table configured **if** any cappable deduction is present | `DeductionCapUnconfigured` |
| 12 | Benefits-in-kind barème configured **if** any benefit component is enabled | `BenefitBaremeUnconfigured` |
| 13 | The accounting period for the payroll month is open (`02-accounting.md` C8) | `AccountingPeriodLocked` |
| 14 | No `PayrollItem` already exists for any included staff member for this `payroll_month` | `DuplicatePayrollMonth(staff_no[])` |
| 15 | No unfiled prior-period `StatutoryDeclaration` | **Warning, not fatal** — surfaced prominently on the run screen |

The preflight result is persisted (`PayrollPreflightResult`: run_id, check_code, status, detail JSON, checked_at) so the bursar sees a checklist, not a stack trace. Each failing row links directly to the settings screen that fixes it.

### 9.2 The empty-state UI contract

The Payroll Tax Rates screen renders unconfigured rates as an explicit, non-dismissible block:

> **Not configured — payroll is blocked.**
> *Risques Professionnels (RP), employer share.* CNPS assigns your risk class in your notification letter. Rate options are 1.75%, 2.50% or 5.00% depending on class. **Do not guess.**
> `[ Enter rate ]` `[ Attach CNPS notification ]`

It never pre-fills a value, never shows a "suggested" figure in an input, and never offers "use typical value".

---

## 10. Reproducibility: the snapshot is authoritative (C7)

### 10.1 Why

v1 shipped *both* "snapshot of all inputs" and "recomputing reproduces the amounts" without saying which is authoritative — so neither was tested, and both were false, because `basic_salary`, `salary_grade_id`, `risk_class`, `department_id` and `SalaryGrade.basic_salary` were mutable scalars with no versioning. A March raise changed every January payslip.

> **Declaration: the snapshot is authoritative.** Payslip re-render, DIPE re-export, DGI return re-generation, the registre d'employeur "as at" view and every audit read **read the snapshot and never recompute.** Recomputation exists only inside `calculate`, on a `draft` run.

This mirrors `01-assessment.md`'s resolution for report cards, deliberately: same problem, same answer.

### 10.2 `PayrollItem` and `PayrollItemSnapshot`

```
PayrollItem                                   -- the live row, mutable while draft
├─ id, payroll_run_id FK RESTRICT
├─ staff_member_id FK RESTRICT, staff_contract_id FK RESTRICT
├─ days_worked            DECIMAL(5,2)   -- DIPE requirement
├─ days_in_period         DECIMAL(5,2)
├─ hours_validated        DECIMAL(7,2) NULL
├─ gross                  BIGINT SIGNED
├─ sbt                    BIGINT SIGNED
├─ cnps_capped_base       BIGINT SIGNED   ┐ the N1 fix, at the row level
├─ cnps_uncapped_base     BIGINT SIGNED   ┘
├─ taxable_base           BIGINT SIGNED   -- NC_annual / 12 for display
├─ irpp_amount            BIGINT SIGNED   -- CAC's basis
├─ total_employee_deductions, total_employer_charges
├─ net                    BIGINT SIGNED
├─ ytd_sbt, ytd_irpp_withheld              -- for §6.5
├─ exception_flags        JSON             -- exemptions, overrides, missing floor
└─ UNIQUE (payroll_run_id, staff_member_id)
   + the cross-run UNIQUE(payroll_month, staff_member_id) of 00-core §10.4

PayrollLine
├─ id, payroll_item_id FK CASCADE-within-draft-only / RESTRICT once approved
├─ payroll_component_id FK RESTRICT
├─ statutory_rate_id    FK RESTRICT NULL   ← H: this is the missing link
├─ base_amount          BIGINT SIGNED      -- printed on the payslip (legally required)
├─ applied_rate_bp      BIGINT NULL        -- printed on the payslip
├─ applied_flat_amount  BIGINT NULL
├─ bracket_detail       JSON NULL          -- per-bracket breakdown for IRPP
├─ amount               BIGINT SIGNED
├─ arrears_for_month    DATE NULL
└─ UNIQUE (payroll_item_id, payroll_component_id, arrears_for_month)

PayrollItemSnapshot                        -- written at APPROVAL, immutable
├─ id, payroll_item_id FK RESTRICT, UNIQUE
├─ snapshot_version     INT
├─ payload              JSON (or LONGTEXT) -- see below
├─ payload_hash         CHAR(64)
├─ rendered_pdf_hash    CHAR(64) NULL      -- set on first payslip render
├─ created_at
└─ INSERT-only: BEFORE UPDATE and BEFORE DELETE triggers reject unconditionally
```

`payload` carries, denormalised and self-contained:
- employer block: name, address, `cnps_employer_number`, `niu`, `dipe_number`, `cnps_regime`, `rp_risk_class`;
- employee block: names, `staff_no`, CNPS number, NIU, job title, **category and classification**, department, bank/mobile coordinates masked;
- period, `days_worked`, `hours_validated`, `proration_basis` used;
- every earning and deduction line with its **base**, its **rate or flat amount**, the `statutory_rate_id` **and a copy of that rate row's amount columns**, and the resulting amount;
- employer contributions with the same detail;
- YTD figures used by §6.5;
- leave balance at period end;
- the `PayrollComponent` set with `calculation_order` and formula ASTs;
- the applicable `ReportConfig`/template version id for the payslip layout.

Storing the rate *values* — not just the FK — is deliberate: it makes the snapshot readable after a decade even if the rate table is migrated.

### 10.3 The reproducibility test

```
1. Run and approve payroll for month M.
2. Render every payslip; record the PDF hashes.
3. Mutate: SalaryGrade.basic_salary, StaffCompensation, StaffContract.department_id,
   EmployerProfile.rp_risk_class, PayrollComponent.calculation_order,
   insert a successor StatutoryRate for every code.
4. Re-render every payslip for month M.
5. ASSERT byte-identical PDFs and identical DIPE export bytes.
```

This is a release gate, not a unit test.

---

## 11. Statutory declarations (C5)

v1 specified only DIPE. A school running v1 would believe it was compliant while never filing **the return with the most aggressive penalty regime**.

### 11.1 `StatutoryDeclaration`

```
├─ id
├─ type          ENUM(dipe | cnps_contribution_schedule | dgi_monthly_salary_return
│                     | tdl_remittance | annual_salary_return
│                     | cnps_annual | staff_register)
├─ payee         ENUM(CNPS | DGI | Commune)
├─ period_month  DATE  (or period_year for annual types)
├─ due_date      DATE NOT NULL
├─ status        ENUM(not_due | due | generated | filed | paid | late | rejected)
├─ generated_at, filed_at, paid_at
├─ external_reference   VARCHAR(64) NULL   -- the receipt/acknowledgement number
├─ amount_declared      BIGINT SIGNED
├─ amount_paid          BIGINT SIGNED
├─ penalty_amount       BIGINT SIGNED DEFAULT 0
├─ generated_from_run_ids  JSON
├─ export_document_id   FK Document RESTRICT
├─ filed_by FK RESTRICT
└─ UNIQUE (type, period_month)
```

`ON DELETE`: **RESTRICT**.

### 11.2 The return set

| Return | Payee | Contents | Deadline |
|---|---|---|---|
| **DIPE** | CNPS | Per employee: CNPS number, **days worked**, capped base, uncapped base, PVID EE/ER, PF, RP | **15th of the following month** |
| CNPS contribution schedule | CNPS | Totals per branch | 15th |
| **DGI monthly salary return** | DGI | **IRPP, CAC, CFC (both shares), RAV, FNE** | **15th of the following month** |
| **TDL remittance** | **Commune** | TDL collected, per band | Per commune schedule — *NEEDS VERIFICATION* |
| Annual salary return | DGI | Per-employee annual totals | *NEEDS VERIFICATION* |
| CNPS annual | CNPS | Annual reconciliation | *NEEDS VERIFICATION* |

Where a deadline is unverified, `due_date` is **NULL** and the declaration shows as *"Deadline not configured"* rather than a fabricated date. A fabricated deadline is worse than none: it produces false confidence.

### 11.3 Compliance calendar

A scheduled job materialises the declaration rows for each closed payroll month, computes `due_date` where known, and raises alerts at T−7, T−3, T−1 and on overdue. `00-core` §3's offline commitment applies: alerts are in-app and queued outbox, never a blocking network call.

**Unfiled prior-period declarations appear as a warning banner on the next run's screen** (preflight check 15). Not fatal — a school in arrears still needs to pay its staff — but impossible to miss.

**Late-payment surcharges are recorded as a distinct expense line** (`PENALTY_*` component / dedicated expense account), never netted into the contribution. Netting them makes the reconciliation against the CNPS's own statement impossible and hides a controllable cost.

### 11.4 DIPE export

Two artefacts:

1. **Magnetic export** — fixed-layout `.txt` for the e-DIPE portal. "Exportable" is not a specification; without a byte-level layout the bursar re-keys every employee every month, which is the actual failure mode. **Layout source: `cnps.cm/images/pdf/dipe.pdf`. Exact field positions NEEDS VERIFICATION (§2.4)** — the export is implemented behind a `DipeLayout` definition object (field name, offset, length, alignment, padding, format) that ships **unpopulated**, and the export button is disabled with an explicit message until it is.
2. **Printable C04** — the paper form, rendered from the same snapshot data.

Both read `PayrollItemSnapshot` exclusively. Both require `days_worked` per employee per month; a snapshot lacking it fails export rather than exporting a zero, because a zero here mis-records the employee's CNPS pension quarters and the harm surfaces decades later, to the employee, irreversibly.

### 11.5 CNPS worker lifecycle

- **Registration within 8 days of hire.** `StaffMember.cnps_registration_status = 'pending'` is set by the hire Action, `cnps_registration_deadline = hire_date + 8 days`. Alerts at T−3, T−1, overdue. An overdue registration appears on the run exception report.
- **Declaration of departure** — a required CNPS filing on termination; a `staff_departure` declaration row is created by the termination Action.
- `WorkAccident` — `staff_member_id`, `occurred_at`, `location`, `description`, `witness_names`, `declared_to_cnps_at`, `cnps_reference`, `medical_certificate_document_id`, `days_lost`, `status`. Declaration deadline *NEEDS VERIFICATION*.

### 11.6 `CnpsBenefitClaim`

The employer **advances** maternity daily allowances and then claims reimbursement from CNPS. That is a **receivable**, and v1 had neither an entity nor a posting for it.

`id`, `staff_member_id` FK RESTRICT, `claim_type` (`maternity`|`work_accident`|`sickness`|`family_allowance`), `period_from`, `period_to`, `amount_advanced`, `amount_claimed`, `amount_reimbursed`, `submitted_at`, `cnps_reference`, `status` (`draft|submitted|part_reimbursed|reimbursed|rejected`), `journal_entry_id`.

Posting: the advance debits a **CNPS receivable** account and credits staff payable; reimbursement clears it against treasury. Account codes are owned by `02-accounting.md`; *the specific sub-account NEEDS VERIFICATION*. An outstanding claim ages on the receivables report like any other.

---

## 12. Leave (H8)

### 12.1 Why a ledger

`StaffLeave` was a mutable `balance` column with no accrual rate, no monetary value and no payroll link. A mutable balance is unauditable and cannot answer "what was the balance on 30 June". Same reasoning, same fix as `06-assets-stores.md` mandates for stock: **signed deltas, never a mutable quantity**.

### 12.2 Entities

```
LeaveType                     -- reference data, seeded WITHOUT statutory_days
├─ code, name, name_fr
├─ is_paid                    BOOLEAN
├─ payer                      ENUM(employer | cnps | unpaid)
├─ accrues_leave              BOOLEAN   -- does time on this leave itself accrue?
├─ counts_as_effective_service BOOLEAN  -- drives the 1.5 j.o./month accrual
├─ statutory_days             INT NULL  -- NULL where unverified; never guessed
├─ requires_medical_certificate BOOLEAN
├─ max_consecutive_days       INT NULL
├─ is_active
└─ UNIQUE (code)

LeaveAccrual                  -- APPEND-ONLY signed-delta ledger
├─ id, staff_contract_id FK RESTRICT
├─ leave_type_id FK RESTRICT
├─ entry_type    ENUM(accrual | taken | adjustment | encashed
│                     | carried_forward | forfeited | opening)
├─ delta_days    DECIMAL(6,2) SIGNED    -- + accrual, − taken
├─ effective_on  DATE
├─ source_type/source_id                 -- LeaveRequest, PayrollRun, import
├─ reason, created_by FK RESTRICT, created_at
└─ INSERT-only; BEFORE UPDATE / BEFORE DELETE triggers reject

LeaveRequest
├─ id, staff_contract_id FK RESTRICT, leave_type_id FK RESTRICT
├─ starts_on, ends_on, working_days DECIMAL(5,2)
├─ status ENUM(draft|submitted|approved|rejected|cancelled|taken)
├─ approver_staff_id FK RESTRICT, approved_at, rejection_reason
├─ medical_certificate_document_id
├─ replacement_staff_contract_id NULL     -- who covers the classes
└─ version
```

- Balance is **always** `SELECT SUM(delta_days)`, never stored. A materialised `LeaveBalanceCache` is permitted for the dossier screen, rebuilt from the ledger, never authoritative.
- **Overlap invariant:** no two `approved` `LeaveRequest` rows for one contract may overlap in date. Checked under `FOR UPDATE` on the contract row.
- Approving a request writes one `taken` accrual row; cancelling writes a compensating `adjustment` row. **Nothing is ever deleted or edited.**

### 12.3 Accrual

Reference: **1.5 jours ouvrables per month of effective service** (§2.3). A monthly scheduled Action writes one `accrual` row per eligible contract, keyed idempotently on `UNIQUE (staff_contract_id, leave_type_id, entry_type='accrual', effective_on)`. Months on a `counts_as_effective_service = FALSE` leave type accrue nothing.

### 12.4 `ALLOCATION_CONGE`

The *allocation de congé* is **≥ 1/16 of remuneration over the preceding 12 months** — a legally mandatory **cash payment**, not a notional balance.

```
allocation_conge = max( trailing_12_month_remuneration / 16 × (days_taken / annual_entitlement) ,
                        statutory_floor )
```

The trailing-12-month remuneration is read from `PayrollItemSnapshot` (§10). It is an `earning` component, taxable, CNPS-liable, computed at `calculation_order` 180 — before the bases barrier at 200, which is why the barrier exists.

### 12.5 Provision

Accrued-but-untaken leave is a **liability the balance sheet must carry**. A monthly Action posts `Dr 66x / Cr 428x` for the movement in the provision, reversed when the allocation is paid.

> **Account codes `66x` / `428x` NEEDS VERIFICATION** (§2.4). Until confirmed, the provision **calculates and reports but does not post** — the Action raises `ProvisionAccountsUnconfigured` and records the computed amount on a report. Leave accrual and the allocation payment are unaffected; only the accounting entry is withheld.

---

## 13. Termination (H9)

### 13.1 `TerminationSettlement`

```
├─ id, staff_contract_id FK RESTRICT, UNIQUE
├─ termination_type  ENUM(resignation | licenciement | licenciement_faute_lourde
│                         | fin_cdd | retirement | death | mutual)
├─ notice_start, notice_end, notice_served BOOLEAN
├─ last_working_day, settlement_date
├─ seniority_years DECIMAL(5,2)   -- from StaffContract.seniority_reference_date
├─ indemnite_licenciement          BIGINT SIGNED   -- from the severance schedule
├─ indemnite_compensatrice_preavis BIGINT SIGNED
├─ leave_compensation              BIGINT SIGNED   -- from the LeaveAccrual ledger
├─ other_amounts JSON
├─ exempt_portion, taxable_portion BIGINT SIGNED   -- severance IRPP treatment
├─ status ENUM(draft|approved|paid), approved_by FK RESTRICT
├─ payroll_run_id FK RESTRICT      -- the final_settlement run
└─ certificat_travail_document_id, solde_de_tout_compte_document_id
```

- **`indemnite_licenciement` cannot be computed. The severance schedule (Arrêté 016/MTPS/SG/CJ of 26/05/1993) is NEEDS VERIFICATION — sources conflict materially (§2.4).** The field is enterable manually with a mandatory `basis_note`, and the automated computation is disabled with an explicit message. Computing severance from a guessed schedule produces a number an employee will litigate.
- **Severance has different IRPP treatment — it is partly exempt.** The exempt/taxable split is `exempt_portion` / `taxable_portion`; only `taxable_portion` enters SBT. *The exemption rule NEEDS VERIFICATION;* until configured the split must be entered manually and is flagged on the run.
- The `final_settlement` run type processes the settlement alongside any final month's pay, in one `PayrollItem`, so the CNPS ceiling and IRPP annualisation apply once (H3).

### 13.2 Mandatory departure documents

Generated by the termination Action, logged in `DocumentPrintLog` (`00-core` §14):

- **Certificat de travail** — mandatory on departure. Contents: employer identity, employee identity, dates of entry and exit, position(s) held. Nothing more (a certificat de travail carrying an appraisal is unlawful).
- **Reçu pour solde de tout compte** — itemised, with the employee's signature block.
- CNPS declaration of departure (§11.5).

---

## 14. Documents

### 14.1 Payslip — the mandatory field checklist

Missing fields are an on-the-spot labour-inspection finding. v1 left payslip content unspecified.

**Employer:** name · address · **CNPS employer number** · **NIU**.
**Employee:** name · **CNPS number** · job title · **category and classification**.
**Period:** payroll month · **days worked** (and hours worked for hourly staff) · base rate.
**Earnings:** each gross element, separately named, with its amount.
**Deductions:** **each deduction with its base AND its rate** — not just the amount. `PayrollLine.base_amount` and `applied_rate_bp` exist for this and are printed.
**Employer contributions:** each, with base and rate (informational to the employee, mandatory on the document).
**Net:** the amount paid · **payment date and method**.
**Leave:** balance at period end.

Rendered bilingually (FR/EN per `00-core` §7.5 localisation). **Golden-file tests per language**, asserting the presence of every checklist item by anchor, run in CI.

Reprints are watermarked `DUPLICATA` and logged (`00-core` §14).

### 14.2 `Registre d'employeur`

Must be produced on demand to a labour inspector **with an "as at" date**. This is only possible because of the snapshot (§10): the register is reconstructed from `PayrollItemSnapshot` and `StaffContract` history as they stood on the requested date, not from current rows.

### 14.3 Annual salary certificate

Per employee, per fiscal year: total SBT, total abatements, total PVID, total IRPP withheld, total CAC/CFC/RAV/TDL. The employee needs it for their own annual return, and it is the reconciliation artefact for §6.5.

### 14.4 Other

Payment advice · CNPS contribution schedule · DIPE C04 · attestation de virement · staff dossier export.

Full rendering specifications in `10-documents.md`. No payroll document may carry any element forbidden by `00-core` §13.2.

---

## 15. Screens

Per `09-ui.md` for layout rules. Mockups: `teacher profile.png`, `flow wizards.png` panel 5.

**Payroll Management** — pay-period selector, KPI row (headcount, gross, total deductions, employer charges, net), editable grid, `Process Payroll`, `Compute Statutory Deductions`, `Print Payment Advice`. The preflight checklist (§9.1) renders above the grid and **disables the process button** while any check fails, with each failure linking to its fix.

**Payroll Tax Rates (settings)** — effective-dating UI: rows show `[effective_from, effective_to)`, locked rows are read-only with a "Close and supersede" action (§4.4), unconfigured rows render per §9.2, every row shows its `source_citation` and a link to its attached source document.

**Staff Profile dossier** — header (identity, photo, contracts) plus tabs: Subjects Taught · Classes · Timetable · Documents · Leave History · Appraisals · Disciplinary Records · Payroll History. Plus an **Assigned Information** panel and a **per-type Leave Balance** panel computed from the `LeaveAccrual` ledger.

**Timesheet validation** — per month, per hourly staff member; planned vs taught vs validated hours; bulk validate; the gate that unblocks hourly payroll.

**Compliance calendar** — declarations by due date, with status and overdue highlighting.

---

## 16. Testing and acceptance

| Level | Requirement |
|---|---|
| Property | `resolve()` returns exactly one row per code over a 10-year daily sweep |
| Property | `gross − Σ(employee_deduction) = net`, exactly, for random component sets |
| Golden | IRPP at every annual bracket boundary, both directions (§6.3) |
| Golden | Worked examples A–D (§6.4) reproduced to the franc |
| Golden | Payslip field checklist present, per language (§14.1) |
| Equivalence | YTD-cumulative ≡ flat annualisation for constant pay, m = 1…12 (§6.5) |
| Regression | RP computed on `cnps_uncapped_base` for a 900,000 salary (N1) |
| Regression | PF resolves to the `enseignement_prive` row for that regime (N2) |
| Regression | No IRPP code path reads any dependants field (N3 — architecture test: the identifier does not exist) |
| Regression | Abatement applied to SBT, not to `SBT − PVID` (N4) |
| Reproducibility | §10.3 mutate-and-re-render, byte-identical, **release gate** |
| Refusal | With any statutory rate unconfigured, `calculate` throws and writes nothing — asserted per code |
| Refusal | Seeder run on a clean database leaves **zero** rows with a non-null rate amount |

### Acceptance gate

> **~20 real anonymised payslips from the customer school, reproduced to the franc.** Hard release gate for Phase 11. No partial credit, no tolerance. If the school's own payslips cannot be reproduced, the configuration is wrong or the school's prior payroll was wrong — and both outcomes must be resolved before the first live run, not after.

---

## 17. Open items carried to the gate

`00-core` §16 gates **7** (CNPS regime and RP risk class from the school's notification letter), **8** (current CNPS rates and ceiling, IRPP brackets, CAC/CFC/RAV/TDL tables, SMIG, severance schedule, overtime premiums) and **9** (vacataires — *answered: mixed, both paths required*) all resolve into this document. Gate 8 is the one that keeps payroll switched off. The complete list of what ships empty, and what each empty field blocks, is §2.4.
