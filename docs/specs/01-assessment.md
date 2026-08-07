# 01 — Assessment & Report Card Engine

**Version:** 2.0
**Status:** Draft for review
**Owns:** frameworks, assessment periods, subject allocations, exams, marks, competencies, the grading pipeline, ranking, statistics, report-card configuration, snapshots, approval chain, publication, amendment.
**Binding parent:** `00-core.md`. Where this document appears to conflict with 00-core, 00-core wins and this document is defective.
**Module:** `app/Modules/Assessment`, with `Academics` owning `Subject`, `ClassLevel`, `ClassGroup`, `Stream` (00-core §8).

> **Why this document exists in this form.** v1's pipeline was arithmetically wrong (it composed raw marks with unequal maxima), "no mark row" was not a state (so unassessed subjects silently raised everyone's average), Σcoefficient = 0 produced a Fail rather than a NULL, five printed report-card blocks had no backing entity, awards that a *conseil de classe* votes on were derived from grade bands, and publication was global so one late teacher blocked the school. If the bulletin is wrong the product is unsellable. Every rule below is stated with a worked numeric example.

---

## 1. Scope and cross-references

| Concern | Owned by |
|---|---|
| Money, rates, `Score` type, rounding, `Money::allocate` | `00-core` §7 |
| Academic years, sections, class levels, class groups, streams | `00-core` §8, `07-students` |
| `Enrollment`, `EnrollmentSegment`, promotion | `07-students` |
| Attendance registers, per-lesson attendance, discipline cases | `07-students` |
| Printable layout catalogue, document integrity policy | `10-documents`, `00-core` §13 |
| Screen navigation shell, responsive rules | `09-ui` |
| Fee-balance block printed on the bulletin | `04-fees` |

This document defines `EnrollmentSegment` **usage** (§12.6); the entity itself is defined in `07-students`.

**Mockups (UI source of truth):** `Results management.png`, `subject management.png`, `accademic setting.png`, and the Examinations screens (`Exam`, `ExamType`, `MarkScheme`, `RemarkTemplate` tabs).

---

## 2. The correction that drives the whole design

### 2.1 v1's pipeline was Collect → Compose → Normalize. It is wrong.

Composing raw component marks that have **different maxima** produces a number with no defined maximum. The v1 order therefore normalised a quantity that was already meaningless.

**Worked counterexample (this is the acceptance test).**

A subject has two components: *Contrôle continu* marked out of 30 at weight 30, and *Examen* marked out of 100 at weight 70. A student scores **CA 24/30** and **Exam 60/100**. Framework `max_score` is 20; the pass mark is 10/20 (= 50%).

*v1 (wrong) — compose raw, then normalise:*

```
raw = 24 × 0.30 + 60 × 0.70
    = 7.2 + 42.0
    = 49.2        ← out of what? 30×0.3 + 100×0.7 = 79 is the true max,
                    but v1 had no stage that computed it.
```
Any implementer normalising 49.2 against the obvious-looking 100 gets **49.2 % → 9.84/20 → FAIL**. Normalising against 20 gets 49.2/20, an impossible score. Normalising against 79 happens to be right, but nothing in v1 said to do that, and no v1 stage carried a composed maximum.

*v2 (correct) — normalise each component to a unit ratio first, then compose:*

```
Stage 2  normalise:  CA   = 24 / 30  = 0.800000
                     Exam = 60 / 100 = 0.600000
Stage 3  compose:    r    = 0.800000 × (30/100) + 0.600000 × (70/100)
                          = 0.240000 + 0.420000
                          = 0.660000        ← dimensionless, always in [0,1]
Stage 4  scale:      s    = 0.660000 × 20 = 13.200 → 13.20 / 20   PASS
```

**The correction flips the student from Fail to Pass.** The two orders are not merely stylistically different; they are different products.

### 2.2 The corrected six-stage pipeline

```
1. COLLECT    gather Mark rows for (enrollment, subject_allocation, period, component)
2. NORMALIZE  each component → unit ratio in [0,1] against its OWN effective maximum
3. COMPOSE    weighted mean of surviving component ratios → subject unit ratio
4. WEIGHT     scale to framework.max_score; multiply by coefficient
5. AGGREGATE  Σ(subject_score × coefficient) ÷ Σ(coefficient)  → general average
6. RANK       round, then order within the rank cohort
```

Stages 1–4 are per (enrollment, subject_allocation, period). Stage 5 is per (enrollment, period). Stage 6 is per (cohort, period). The pipeline is implemented once, in `Assessment\Domain\GradingPipeline`, a pure class with **no Laravel and no Eloquent** (00-core §6.2 rule 1). Everything that needs an average — report card, broadsheet, promotion engine, statistics, GPA, the annual average — calls it. The promotion engine calling its own arithmetic is a review-blocking defect (see §9.4).

---

## 3. Framework configuration

### 3.1 `AssessmentFramework`

The per-`SchoolSection` (00-core §8) declaration of how assessment works for that level and sub-system.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `school_section_id` | FK → `SchoolSection` | RESTRICT |
| `academic_year_id` | FK → `AcademicYear` | RESTRICT. Frameworks are year-scoped so a rule change never rewrites history |
| `code` | VARCHAR(32) `utf8mb4_0900_as_cs` | e.g. `MINESEC_FR_SEC1` |
| `name`, `name_fr` | VARCHAR(160) | |
| `family` | ENUM | `A`…`F` — see §3.2 |
| `assessment_mode` | ENUM(`numeric`,`competency`,`hybrid`) | `competency` ⇒ no marks, no coefficients, no rank |
| `max_score` | DECIMAL(6,3) | the framework's canonical scale. 20.000 for MINESEC, 100.000 where a percentage basis is confirmed |
| `pass_score` | DECIMAL(6,3) | **the single source of "pass"** (§10.3). CHECK `0 < pass_score <= max_score` |
| `score_precision` | TINYINT | dp for the final rounding, default 2 |
| `uses_coefficients` | BOOL | |
| `uses_rank` | BOOL | |
| `rank_scope` | ENUM(`class_group`,`class_level`,`stream`) | 00-core §5 |
| `rank_cohort_rule` | ENUM(`identical_basket`,`same_stream`,`all`) | default `same_stream` (§10.2) |
| `annual_composition` | ENUM(`mean_of_leaf_periods`,`weighted_children`,`mean_of_terms`) | default `mean_of_leaf_periods` (§9) |
| `requires_conseil` | BOOL | gates publication on a held `ConseilDeClasse` (§13.4) |
| `requires_hod_validation` | BOOL | gates publication on all marks `validated` (§7.4) |
| `requires_per_lesson_attendance` | BOOL | **must be true for MINESEC families** (§14) |
| `missing_component_policy` | ENUM(`redistribute`,`zero`,`block_publication`) | default `redistribute` (§6.4) |
| `min_periods_assessed` | TINYINT | below this a student is **NC** (§10.5) |
| `gpa_scale_id` | FK → `GpaScale` NULL | §11 |
| `is_active` | BOOL | |

- `UNIQUE(school_section_id, academic_year_id, code)`
- `UNIQUE(school_section_id, academic_year_id, is_default_key)` where `is_default_key` is the generated-column pattern of 00-core §10.1 — one default framework per section per year.
- `ON DELETE RESTRICT` from every child. Frameworks are archived (`is_active = 0`), never deleted (00-core §10.5).

### 3.2 Framework families

| Family | Intended for | Mode | Rank | Coefficients |
|---|---|---|---|---|
| **A** | MINESEC Francophone secondary (1er & 2nd cycle) | numeric /20 | yes | yes |
| **B** | MINESEC Anglophone secondary | numeric — **/20 vs % NEEDS VERIFICATION**, 00-core gate 5 | yes | yes |
| **C** | Technical / teacher-training | numeric, subject groups | yes | yes |
| **D** | MINEDUB primary Francophone | numeric + competency hybrid | yes | yes |
| **E** | MINEDUB primary Anglophone | numeric + competency hybrid | yes | yes |
| **F** | **MINEDUB maternelle (nursery)** | competency only | **no** | **no** |

**Family F is mandatory scope**, not an extra: the product is sold to nursery-only schools. See §8.

**NEEDS VERIFICATION (00-core blocking gate 5).** Evidence indicates Anglophone Cameroonian secondary schools mark **internally out of 20 with coefficients and subject groups**, identically to Francophone practice, and that GCE letter grades (A–E, U; O-Level pass A–C) apply **only to the Board examination**, not to internal school reporting. v1 seeded generic percentage bands for Anglophone schools; that is not confirmed. **Do not seed Family B's `max_score` or its bands.** The framework refuses to run until an administrator confirms the basis against a specimen report card.

### 3.3 `GradeBand`

| Column | Type | Notes |
|---|---|---|
| `framework_id` | FK | RESTRICT |
| `purpose` | ENUM(`internal`,`exam_o_level`,`exam_a_level`) | **new.** GCE letter grades are Board-exam artefacts and must not leak into internal reporting |
| `scale_basis` | ENUM(`out_of_max`,`percentage`) | **new.** v1 mixed /20 and percentage bands in one table with no column saying which, so a /20 framework matched no Anglophone band and printed a blank grade |
| `class_level_id` | FK NULL | optional narrowing (e.g. different bands in Form 5) |
| `min_score`, `max_score` | DECIMAL(6,3) | half-open `[min, max)` except the top band, which is **closed** `[min, max]` |
| `label`, `label_fr` | VARCHAR(48) | `Très Bien`, `Credit` |
| `mention` | VARCHAR(48) NULL | the derived band label printed as *Appréciation*/*Mention*. **Never an award** (§12.2) |
| `grade_point` | DECIMAL(4,2) NULL | feeds GPA (§11) |
| `is_pass` | BOOL | advisory only — the authoritative pass test is `score >= framework.pass_score` (§10.3) |
| `colour` | CHAR(7) NULL | |
| `order_index` | SMALLINT | |

`UNIQUE(framework_id, purpose, scale_basis, class_level_id_key, min_score)`.

**Coverage invariant (validated by an Action at save, blocking).** For each distinct `(framework_id, purpose, scale_basis, class_level_id)` tuple, ordering bands by `min_score`:

1. `bands[0].min_score = 0`;
2. `bands[i].max_score = bands[i+1].min_score` — **contiguous, no gaps, no overlaps**;
3. `bands[last].max_score = ` the scale ceiling (`framework.max_score` when `scale_basis = out_of_max`, else 100);
4. the top band is closed so a perfect score bands.

Failing any clause rejects the save with the offending interval named. A framework with an incomplete band set **cannot be published against**.

*Worked example, Family A internal /20:*

| min | max | label | grade_point |
|---|---|---|---|
| 0 | 5 | Très Faible | 0.00 |
| 5 | 10 | Faible | 1.00 |
| 10 | 12 | Passable | 2.00 |
| 12 | 14 | Assez Bien | 3.00 |
| 14 | 16 | Bien | 4.00 |
| 16 | 20 | Très Bien *(closed)* | 5.00 |

A score of 12.00 bands **Assez Bien** (half-open lower bound inclusive). A score of 20.00 bands **Très Bien** (top band closed). A score of 11.995 rounds to 12.00 *before* banding (§10.1), so the printed number always explains the printed mention.

---

## 4. Assessment periods

### 4.1 `AssessmentPeriod`

A self-referencing tree per academic year. 00-core §5 forbids calling anything else a "period".

| Column | Type | Notes |
|---|---|---|
| `academic_year_id` | FK | RESTRICT |
| `framework_id` | FK | RESTRICT |
| `parent_id` | FK self NULL | |
| `type` | ENUM(`year`,`term`,`trimestre`,`sequence`,`evaluation`,`month`) | **`month` added** — see below |
| `code`, `name`, `name_fr` | | `S1`, `Séquence 1` |
| `order_index` | SMALLINT | |
| `starts_on`, `ends_on` | DATE | CHECK `starts_on <= ends_on`; CHECK contained within the parent's range |
| `weight` | DECIMAL(8,4) | **arbitrary positive**, normalised at composition time by Σ over *participating* children (§9.1). CHECK `weight > 0` |
| `counts_toward_parent` | BOOL default 1 | **new** — lets a *Bac blanc* / GCE mock exist as a period without polluting the term average (§16.5) |
| `marks_entry_opens_at`, `marks_entry_closes_at` | DATETIME NULL | evaluated with `business_date()`/`business_now()` **at transaction start** (§7.6) |
| `is_reporting_period` | BOOL | true ⇒ a report card is issued for it |
| `status` | ENUM(`planned`,`open`,`closed`) | |

- `UNIQUE(academic_year_id, framework_id, code)`
- Depth invariant: `year → (term|trimestre) → (sequence|evaluation|month)`. A cycle-detection check runs on save; `parent_id` may not reference a descendant.
- Leaf periods are those with no children. **Marks attach only to leaf periods.** A Mark row whose period has children is rejected at the Action.

**`month` (H-correction).** MINEDUB primary reportedly moved to **9 monthly evaluations with monthly report cards**. The `month` type and a 9-child template exist for it. **NEEDS VERIFICATION: whether 9 monthly evaluations is still the arrangement for 2025-26.** This is 00-core blocking gate 4; no monthly template is seeded until confirmed against a MINEDUB specimen.

### 4.2 Standard shapes (templates, not seeds)

- Family A/B/C: `Year → 3 × Trimestre → 2 × Séquence` = **6 sequences**.
- Family D/E: `Year → 9 × Month` *(pending gate 4)*, or `Year → 3 × Term → 2 × Evaluation`.
- Family F: `Year → 3 × Term`, observation-only, `is_reporting_period = 1`.

Templates are offered in the setup wizard and are fully editable. Nothing is created silently.

---

## 5. Subjects, allocations and components

### 5.1 `SubjectAllocation` — year-scoped and period-scoped

v1's allocation was scoped to neither the academic year nor a period range. Two consequences, both silent and both corrupting:

- editing a coefficient **rewrote every historical report card** that had used it;
- deactivating a subject mid-year **raised everyone's average**, because the pipeline (following the reference) ignores marks for subjects not on the class list — the subject vanished from the numerator *and* the denominator, and a weak subject vanishing raises the mean.

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `academic_year_id` | FK | **new**, RESTRICT |
| `class_level_id` | FK | RESTRICT |
| `stream_id` | BIGINT NOT NULL DEFAULT 0 | **sentinel, not NULL** — MySQL permits unlimited duplicate NULLs in a UNIQUE index, which would defeat the constraint entirely (same defect as `FeeStructure` in 04) |
| `subject_id` | FK → `Subject` | RESTRICT |
| `coefficient` | DECIMAL(5,2) | CHECK `coefficient >= 0` |
| `subject_group_id` | FK NULL | for Family C / grouped bulletins (Σ per group printed) |
| `required_components` | JSON | **new** — array of `component_id`. Lets an exam-only subject (e.g. *Éducation physique*, practicals) be declared without a phantom CA (§6.4) |
| `max_score_override` | DECIMAL(6,3) NULL | see §6.3 for the precedence chain — in v1 this column was read by **no** pipeline stage |
| `is_optional` | BOOL | elective — affects the ranking cohort (§10.2) |
| `counts_toward_average` | BOOL default 1 | a subject reported but excluded from the moyenne |
| `effective_from_period_id` | FK → `AssessmentPeriod` NULL | **new** |
| `effective_to_period_id` | FK → `AssessmentPeriod` NULL | **new**, inclusive |
| `is_active` | BOOL | |
| `version` | INT | 00-core §10.6 |

- **`UNIQUE(academic_year_id, class_level_id, stream_id, subject_id)`** — the constraint v1 lacked.
- `ON DELETE RESTRICT` from `Mark`. **Deletion is refused where any Mark row exists**; the allocation is deactivated instead. Attempting deletion returns the count of dependent marks.
- Changing `coefficient` on an allocation that already has `Mark` rows in a **published** period is rejected. The operator must close the current allocation with `effective_to_period_id` and create a successor. This makes coefficient history append-only in effect.

### 5.2 `Mark.subject_allocation_id`

Every `Mark` stores `subject_allocation_id` **NOT NULL**, so a mark is permanently bound to the coefficient, the max-score override and the required-component set it was graded under. Recomputing a closed term two years later reproduces the original bulletin because the pipeline reads the allocation on the mark, never the allocation that is current today.

### 5.3 `AssessmentComponent`

| Column | Notes |
|---|---|
| `framework_id` | RESTRICT |
| `code`, `name`, `name_fr` | `CA`, `EXAM`, `TP`, `ORAL` |
| `max_score` | DECIMAL(6,3) — the component's **own** maximum; this is what stage 2 divides by |
| `order_index` | column order on the entry grid and the printed card |
| `is_active` | |

`UNIQUE(framework_id, code)`.

### 5.4 `ComponentWeight`

Weights are resolvable at three levels of specificity; **most specific wins, ties are a configuration error rejected at save**.

| Column | Notes |
|---|---|
| `framework_id` | RESTRICT |
| `assessment_period_id` | NOT NULL sentinel 0 = "any period" |
| `subject_allocation_id` | NOT NULL sentinel 0 = "any subject" |
| `component_id` | RESTRICT |
| `weight` | SMALLINT UNSIGNED, integer percentage points |

- `UNIQUE(framework_id, assessment_period_id, subject_allocation_id, component_id)`
- **Σ invariant, enforced by a validation Action at save and by a nightly integrity job: for every resolved (framework, period, allocation) triple, Σ weight over the *declared* components = exactly 100.** v1 had no sum invariant at all, so 30 + 60 = 90 was accepted and every student in that subject was quietly marked out of 90 % of the intended scale.
- Resolution order: `(period, allocation)` → `(period, any)` → `(any, allocation)` → `(any, any)`. If two rows tie at the same specificity the save is rejected; nothing is guessed.

---

## 6. The `Mark` entity and mark-state semantics

### 6.1 `Mark`

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `enrollment_id` | FK → `Enrollment` | RESTRICT. One `Enrollment` per student-year owns **all** marks (§12.6) |
| `subject_allocation_id` | FK | RESTRICT |
| `assessment_period_id` | FK (leaf only) | RESTRICT |
| `component_id` | FK | RESTRICT |
| `score` | DECIMAL(6,3) NULL | NULL unless `state = scored` |
| `state` | ENUM(`pending`,`scored`,`absent_justified`,`absent_unjustified`,`exempt`) | **assessment semantics** |
| `workflow_state` | ENUM(`draft`,`submitted`,`validated`) | **approval chain — orthogonal to `state`** (§7) |
| `entered_by`, `entered_at` | FK user RESTRICT / DATETIME | |
| `submitted_by`, `submitted_at` | | |
| `validated_by`, `validated_at` | | |
| `raw_score` | DECIMAL(6,3) NULL | pre-moderation value, always recoverable (§16.4) |
| `moderation_id` | FK NULL | §16.4 |
| `attempt_no` | TINYINT default 1 | re-sits (§16.6) |
| `comment` | VARCHAR(255) NULL | |
| `version` | INT | 00-core §10.6 optimistic lock |

- **`UNIQUE(enrollment_id, subject_allocation_id, assessment_period_id, component_id, attempt_no)`**
- `CHECK ((state = 'scored') = (score IS NOT NULL))`
- `CHECK (score IS NULL OR (score >= 0 AND score <= effective_max))` — enforced in the Action against the resolved maximum of §6.3, since the effective max is not a column.
- **Never cascade into `Mark`** (00-core §10.5). Deleting a class group, a subject or a user cannot remove a mark.
- Index: `(assessment_period_id, subject_allocation_id)` for the entry grid; `(enrollment_id, assessment_period_id)` for the card; `(assessment_period_id, workflow_state)` for the pending-publication gate.

### 6.2 Materialisation — "no row" is not a state

**C2.** In v1, a subject a teacher never opened produced no rows at all. That subject then vanished from both the numerator and the denominator of every affected student, so **everyone's average rose**, and the pending-publication gate had nothing to detect because there was nothing to look at.

**Rule.** `OpenAssessmentPeriod` materialises a `Mark` row with `state = pending`, `workflow_state = draft`, `score = NULL` for **every** (enrollment × active `SubjectAllocation` in effect for that period × component in `required_components`). "No row" becomes impossible by construction.

- It is a batch Action (`forEnrollments(array $ids)`, 00-core §6.2 rule 5), chunk-inserted.
- It is idempotent: `INSERT … ON DUPLICATE KEY UPDATE id = id`, keyed on the UNIQUE above, plus an Action-layer `idempotency_key` (00-core §6.2 rule 7).
- It re-runs on: a late enrollment, an `EnrollmentSegment` transfer in, a new `SubjectAllocation` becoming effective, or a component being added to `required_components`.
- **Volume.** 1 200 students × 11 subjects × 2 components × 6 sequences ≈ **158 400 rows/year**. Sized, indexed and acceptable. This is the price of a correct denominator.

### 6.3 Effective maximum — the precedence chain

`max_score_override` existed in v1 and was read by no pipeline stage. Precedence, highest first:

```
1. SubjectAllocation.max_score_override   (if NOT NULL)
2. AssessmentComponent.max_score
3. AssessmentFramework.max_score
```

Resolution is a single pure function `effectiveMax(Mark): Score`. If an override is used, the subject's contribution is **still re-scaled to `framework.max_score` at stage 4** before weighting — otherwise the override silently changes the subject's real weight.

**Worked example.** Framework `max_score = 20`. *Travaux pratiques* is marked out of 40 by departmental convention, so `SubjectAllocation.max_score_override = 40.000`. The subject has one component, `TP`, whose `AssessmentComponent.max_score` is 20 — the override wins.

```
Mark: score = 34.000, effectiveMax = 40.000
Stage 2  normalise: 34.000 / 40.000 = 0.850000
Stage 3  compose:   single component at weight 100 → r = 0.850000
Stage 4  scale:     0.850000 × 20 = 17.000 / 20
         coefficient 2 → contribution 34.000, coefficient weight 2
```
Without the re-scale, 34.000 would have entered the aggregate as if it were a /20 mark and the student's average would be inflated by roughly 70 % of that subject's weight.

### 6.4 The mark-state semantics table

Applied **per component**, during stage 3 (compose). v1 left mixed states within one subject — CA scored, exam absent — undefined.

| `state` | Numerator ratio | Weight in the denominator | Printed | Rationale |
|---|---|---|---|---|
| `scored` | `score / effectiveMax` | its `ComponentWeight` | the number | normal |
| `absent_unjustified` | **0.000000** | **retained** | `0` / `AbNJ` | an unexcused absence is a zero; excluding it would reward absence |
| `absent_justified` | excluded | **removed**, surviving weights renormalised | `AbJ` | the student is not penalised for a certified absence |
| `exempt` | excluded | **removed**, surviving weights renormalised | `Disp.` | e.g. medical exemption from EPS |
| `pending` | — | — | — | **blocks publication** (§13.2). Never silently treated as anything |

**`missing_component_policy` governs only the `pending` case at forced publication**, never the four resolved states above. This is the safety fix for C4's warning: `redistribute` must not be the mechanism by which a student who simply *missed the exam* gets an A from their CA alone.

| policy | behaviour for a `pending` component at publication |
|---|---|
| `block_publication` | publication refuses; the pending list is returned. Safest |
| `zero` | the component is treated as `absent_unjustified` (ratio 0, weight retained) and stamped as such on the snapshot |
| `redistribute` *(default)* | the component is treated as `exempt` **only if** the enrollment has an `absent_justified` attendance record covering the component's assessment date; otherwise it falls through to `zero`. The chosen path is recorded on the snapshot per mark |

Every application of a policy writes an `AppliedPolicyNote` on the snapshot, so the printed card can be explained two years later.

**Worked example — mixed states in one subject.** Mathematics, coefficient 4, components CA (max 20, weight 40) and Exam (max 20, weight 60).

*Case 1 — CA 14/20 scored, Exam `absent_unjustified`.*
```
CA   ratio 14/20 = 0.700000, weight 40   (retained)
Exam ratio 0.000000,          weight 60   (retained)
r = (0.700000×40 + 0.000000×60) / (40+60) = 28.000000/100 = 0.280000
score = 0.280000 × 20 = 5.600 → 5.60/20 · Très Faible · FAIL
contribution = 5.60 × 4 = 22.40, coefficient weight 4
```

*Case 2 — identical marks, Exam `absent_justified` (medical certificate on file).*
```
CA   ratio 0.700000, weight 40  (retained)
Exam excluded,        weight 60  (removed) → surviving Σweight = 40
r = (0.700000 × 40) / 40 = 0.700000
score = 0.700000 × 20 = 14.000 → 14.00/20 · Bien · PASS
contribution = 14.00 × 4 = 56.00, coefficient weight 4
```

The two cases differ by **8.40 points out of 20 in one subject** — on a Σcoef of 30 that is 1.12 points of general average and several rank places. The justification flag is therefore a controlled field: setting `absent_justified` requires the Class Master role or above and a reason, and is audited.

*Case 3 — both components `exempt`.* Surviving Σweight = 0 ⇒ **the subject is unassessed**: it contributes nothing to the numerator **and its coefficient is removed from the denominator**. It prints as `Disp.` with no score, and the student's Σcoef printed on the card is reduced accordingly (§10.4).

---

## 7. The approval chain

### 7.1 Why `state` and `workflow_state` are separate

v1 had one status column trying to be both. "This mark is a 14" and "this mark has been verified by the head of department" are orthogonal facts; conflating them makes it impossible to represent a *validated absence* or a *draft 14*.

### 7.2 The real MINESEC flow

```
subject teacher enters       → Mark.workflow_state = draft → submitted
head of department verifies  → validated  (or → draft with a rejection reason)
class master compiles, writes the subject/class-master remark
conseil de classe deliberates, votes awards and decisions
principal visas
publish
```

### 7.3 `MarkSubmission`

A batch header giving submission and validation an auditable identity.

| Column | Notes |
|---|---|
| `subject_allocation_id`, `assessment_period_id`, `class_group_id` | |
| `submitted_by`, `submitted_at` | RESTRICT |
| `validated_by`, `validated_at` | RESTRICT, NULL until validated |
| `status` | ENUM(`open`,`submitted`,`validated`,`returned`) |
| `return_reason` | VARCHAR(500) NULL, required when `returned` |

`UNIQUE(subject_allocation_id, assessment_period_id, class_group_id)`. Transitions use **conditional UPDATE with an affected-rows check** (00-core §10.4), never read-then-write.

### 7.4 Gates

- `framework.requires_hod_validation` ⇒ publication requires every non-`pending` Mark in scope to be `validated`.
- `framework.requires_conseil` ⇒ publication requires a `ConseilDeClasse` for that (period, class group) with `status = closed` (§13.4).
- Marks may be edited while `draft`. Editing a `submitted` mark returns it to `draft` and clears `submitted_*`. Editing a `validated` mark is refused; it must be returned first, by someone holding the validating role.
- After publication, edits go through **Amendment** only (§15).

### 7.5 Departed teachers — delegation

`MarkEntryDelegation` (`subject_allocation_id`, `class_group_id`, `assessment_period_id`, `delegate_staff_id`, `granted_by`, `reason`, `valid_from`, `valid_to`). Marks-entry authorisation is `teacher is the assigned teacher for this allocation` **OR** `an active delegation names them`. Without this, a teacher who resigns in November makes their subject permanently unenterable and the whole class unpublishable. The delegation is audited and printed in the period's publication dossier.

### 7.6 The entry window

`marks_entry_opens_at` / `closes_at` are evaluated **once, at transaction start**, against `business_now()` in `Africa/Douala` (00-core §7.5) — never `now()` in UTC, which is wrong between 00:00 and 01:00 local and would reject a legitimate 00:30 save on closing night. Outside the window, entry is refused with the window printed in the message. `Exams Officer` and above may open an override window with a reason.

### 7.7 Concurrency

Marks entry uses the 00-core §10.6 optimistic lock: `UPDATE marks SET … , version = version + 1 WHERE id = ? AND version = ?`. On zero affected rows the UI shows **what changed, the previous value, and who changed it**, and offers keep-mine / keep-theirs. Silent last-write-wins — v1's behaviour — loses a teacher's afternoon of work with no trace.

---

## 8. Family F — MINEDUB maternelle (nursery)

The product is sold to nursery-only schools and v1 had no nursery family at all.

**Characteristics:** no marks, no coefficients, no rank, no general average, no mention. Assessment is **observation-based** against competencies grouped into five learning domains.

### 8.1 The five domains

`CompetencyDomain` (`framework_id`, `code`, `name`, `name_fr`, `order_index`):

1. **Literacy & Communication**
2. **Science & Technology Skills**
3. **Practical Life Skills**
4. **Arts & Crafts**
5. **Motor Skills**

**NEEDS VERIFICATION (00-core blocking gate 4):** the current official MINEDUB domain names in both languages and the competency statements beneath them. The five domains above are the structure; **no competency statement is seeded** until an APC framework document is supplied. The framework refuses to run with an empty competency set and says so.

### 8.2 Entities

```
CompetencyDomain   framework_id, code, name, name_fr, order_index
                   UNIQUE(framework_id, code)

Competency         domain_id, class_level_id (nullable), code, statement,
                   statement_fr, order_index, is_active
                   UNIQUE(domain_id, code)

CompetencyLevel    framework_id, code, label, label_fr, order_index,
                   min_score DECIMAL(6,3) NULL, max_score DECIMAL(6,3) NULL,
                   numeric_equivalent DECIMAL(6,3) NULL, colour
                   UNIQUE(framework_id, code)

CompetencyAssessment  enrollment_id, competency_id, assessment_period_id,
                      competency_level_id NULL, score DECIMAL(6,3) NULL,
                      state ENUM(pending, assessed, not_observed),
                      observation VARCHAR(500) NULL,
                      workflow_state, entered_by/at, validated_by/at, version
                      UNIQUE(enrollment_id, competency_id, assessment_period_id)
```

`CompetencyAssessment` rows are materialised at period open exactly as `Mark` rows are (§6.2), for the same reason.

### 8.3 The MINEDUB scale is three levels, and derivation runs numeric → level

**H-correction.** v1 seeded a 4-level scale. The MINEDUB scale is **3 levels**:

| code | label (EN) | label (FR) |
|---|---|---|
| `A` | Acquired | Acquis |
| `ECA` | Being acquired | En cours d'acquisition |
| `NA` | Not acquired | Non acquis |

In practice **teachers mark out of 20 and then tick the box**, so the primary derivation is **numeric → level**, which is why `CompetencyLevel` carries `min_score`/`max_score`. `numeric_equivalent` exists for the **reverse** direction only (rendering a level as an indicative number where a report demands one) and is never used to compute an average.

*Worked example.* Suppose the confirmed boundaries are ECA `[10, 15)` and A `[15, 20]`. A teacher enters 16.5 for *Reconnaît les lettres de l'alphabet*. `deriveLevel(16.5)` → 16.5 ∈ `[15, 20]` → **A / Acquis**. Entering 9.0 → `[0, 10)` → **NA**. Ticking `A` directly with no number stores `competency_level_id` with `score = NULL`; no numeric equivalent is invented.

**NEEDS VERIFICATION:** the official `min_score`/`max_score` boundaries for A / ECA / NA. Do not seed 10 and 15; they are illustrative here. Boundaries obey the same coverage invariant as `GradeBand` (§3.3).

### 8.4 Family F output

The nursery report shows, per domain: each competency statement, its level, and an observation. Plus a class-master narrative, conduct (§12.3) and attendance. **`uses_rank` and `uses_coefficients` are forced false and the UI hides rank, average, mention and Σcoef entirely** — not merely blanks them, because a blank "Rang" box on a nursery card invites a bursar to fill it in.

---

## 9. Composition: term and annual

### 9.1 Term composition — skip nulls and renormalise

`AssessmentPeriod.weight` is **arbitrary positive** and is always normalised by Σ over *participating* children. A child does not participate when the subject is unassessed in it (§6.4 case 3) or the child has `counts_toward_parent = 0`.

For a subject *s* in parent period *P* with children *c₁…cₙ*:

```
participating = { cᵢ : cᵢ.counts_toward_parent AND score(s, cᵢ) IS NOT NULL }
if participating = ∅              → score(s, P) = NULL   (subject unassessed in P;
                                     dropped from BOTH numerator and denominator)
else score(s, P) = Σ_participating ( score(s,cᵢ) × cᵢ.weight ) / Σ_participating cᵢ.weight
```

**Worked example — one null child.** Trimestre 1 = Séquence 1 (weight 1) + Séquence 2 (weight 1). In *Histoire-Géographie*, Séq 1 is NULL (student admitted in November; §10.5) and Séq 2 = 13.50.

```
participating = { Séq 2 }
score = (13.50 × 1) / 1 = 13.500 → 13.50/20
```
Not `(0 + 13.50)/2 = 6.75`, which is what an implementation that treats NULL as zero produces — a two-band drop caused entirely by a date of admission.

**Worked example — unequal weights.** Trimestre 2 = Séq 3 (weight 1) + Séq 4 (weight 2). *Mathématiques*: Séq 3 = 11.00, Séq 4 = 14.00.
```
score = (11.00×1 + 14.00×2) / (1+2) = (11.00 + 28.00)/3 = 39.00/3 = 13.000 → 13.00/20
```
If Séq 3 were NULL: `(14.00×2)/2 = 14.00`. The surviving weight is renormalised, not carried as a smaller denominator.

### 9.2 Annual average = Σ(6 sequence averages) ÷ 6

**H-correction, and it is load-bearing.** The annual average is the **unweighted mean of the sequence averages**, not a weighted mean of the three trimestre averages. `annual_composition = mean_of_leaf_periods` implements exactly this.

**Worked example.** A student's six sequence general averages:

| Séq | 1 | 2 | 3 | 4 | 5 | 6 |
|---|---|---|---|---|---|---|
| Moyenne | 12.40 | 13.10 | 11.75 | 14.00 | 12.85 | 13.30 |

```
Annual = (12.40 + 13.10 + 11.75 + 14.00 + 12.85 + 13.30) / 6
       = 77.40 / 6
       = 12.900 → 12.90 / 20
```

**Contrast with the wrong method.** Trimestre averages under §9.1 with equal sequence weights: T1 = 12.75, T2 = 12.875, T3 = 13.075. A mean of trimestres gives `(12.75 + 12.875 + 13.075)/3 = 12.900` — identical here **only because every sequence participated**. Now suppose Séq 3 is NULL for this student. Correct method: `(12.40+13.10+14.00+12.85+13.30)/5 = 65.65/5 = 13.130`. Trimestre method: T2 becomes 14.00, so `(12.75 + 14.00 + 13.075)/3 = 13.275`. **A 0.145 divergence** — enough to change rank and, at a band boundary, the mention. The two methods are not interchangeable and the product must implement one.

If a sequence is NULL at the general-average level (the student was not assessable in it, §10.5), it is skipped and the divisor is the count of participating sequences — printed on the card as `Moyenne annuelle (5 séq.)` so the reader knows the divisor.

### 9.3 `annual_composition` alternatives

- `mean_of_leaf_periods` **(default, MINESEC)** — as above.
- `weighted_children` — normalised weighted mean of the immediate children (§9.1 recursively).
- `mean_of_terms` — unweighted mean of the term averages. Offered only because some private schools do this; never the default, and the setup wizard warns that it diverges from the ministry convention.

### 9.4 One service, no second implementation

`ComputeAnnualAverage` is a single Action in the Assessment module. **The promotion engine in `07-students` calls it** through the module boundary (00-core §6.2 rule 2), with the batch signature `forEnrollments(array $ids)` (rule 5).

v1 shipped ported promotion code that used a plain unweighted mean of *term percentages*. For the student above with a missing sequence, the report card printed **13.13** and the promotion engine decided on **13.28** — for the same student, in the same run. A parent comparing the bulletin to the promotion list finds two different annual averages, and the school cannot explain either. **An architecture test asserts that no class outside `Assessment\Domain` computes an average**, and a golden test asserts report card and promotion return byte-identical values over the 1 200-student reference fixture (00-core §15 Phase 0).

---

## 10. Aggregation, ranking and statistics

### 10.1 General average

```
Σcoef        = Σ over assessed subjects of SubjectAllocation.coefficient
                (counts_toward_average = 1, subject not unassessed per §6.4 case 3)
Σ(M×Coef)    = Σ over the same subjects of ( subject_score × coefficient )
general_avg  = Σ(M×Coef) / Σcoef          ← NULL when Σcoef = 0
```

**Rounding:** the result is computed in `DECIMAL(6,3)` and **rounded half-up to `framework.score_precision` (default 2 dp) exactly once, at the end of aggregation**. Ranking and banding operate on the **rounded** value (00-core §7.4). Two students printing 12.45 therefore rank equally; they do not diverge on invisible decimals the parent cannot see. This is a correctness rule, not a display rule.

**Worked example (also the totals row of §13.6).**

| Subject | M /20 | Coef | M × Coef |
|---|---|---|---|
| Mathématiques | 13.00 | 4 | 52.00 |
| Physique | 11.50 | 3 | 34.50 |
| SVT | 14.25 | 3 | 42.75 |
| Français | 12.00 | 4 | 48.00 |
| Anglais | 15.00 | 2 | 30.00 |
| Histoire-Géo | 13.50 | 2 | 27.00 |
| EPS | *Disp.* | — | — |
| **Totaux** | | **18** | **234.25** |

```
general_avg = 234.25 / 18 = 13.013888… → 13.01 / 20 · Assez Bien · PASS
```
EPS is `exempt` in every component, so it leaves both columns; the printed Σcoef is **18**, not 20. The card prints the totals row because that is where a Cameroonian reader derives the moyenne by hand and checks the school's arithmetic.

### 10.2 C3 — Σcoefficient = 0 must be NULL, not zero

v1 divided by zero, caught it, and returned **0.00** — which banded as a Fail, ranked last, *and still counted in the class-size denominator*. A student with no assessed subjects was printed as the worst student in the class.

**Rule.** `Σcoef = 0 ⇒ general_avg = NULL`. A NULL average is:

- **excluded from ranking** — the student receives no rank;
- **excluded from the ranking denominator** — `Rang : 5ᵉ / 62` counts only ranked students;
- **excluded from every class statistic** — mean, min, max, pass rate, standard deviation;
- **printed as "Non évalué / Not assessed"**, never as `0.00` and never as a blank that reads as zero.

The same rule applies at subject level: a subject with no surviving component weight is NULL, prints `n/e`, and is absent from that subject's rank and statistics.

### 10.3 The single source of "pass"

**`score >= framework.pass_score`.** One expression, one place: `Assessment\Domain\PassRule`. `GradeBand.is_pass` is display metadata and is **never** consulted by the pass-rate statistic. v1 had a band flag, a hard-coded 10, and a percentage threshold in the promotion code — three sources that disagreed at the boundary. An architecture test forbids literal pass thresholds outside `PassRule`.

### 10.4 Ranking

```
rank_cohort = students in scope per framework.rank_scope, filtered by rank_cohort_rule
sort key    = rounded general_avg DESC
tie rule    = COMPETITION RANKING (1, 2, 2, 4) — tied students share the better rank
              and the following rank(s) are skipped
```

**`rank_cohort_rule`** — different elective baskets mean different Σcoef and non-comparable averages, and a Cameroonian *conseil de classe* will reject a ranking that mixes them:

| value | cohort |
|---|---|
| `identical_basket` | only students whose set of `counts_toward_average` allocations is identical |
| `same_stream` **(default)** | students sharing `Stream.subject_basket` (00-core §8) |
| `all` | everyone in the `rank_scope` regardless of basket |

**Printed on every card:** the denominator (`Rang : 5ᵉ / 62`), the cohort rule in the legend, and **the student's own Σcoef** so the basis is visible.

**Worked example.** Cohort of 6 ranked students, `same_stream`:

| Student | avg (rounded) | Rank |
|---|---|---|
| Ngu | 15.20 | 1 |
| Fotso | 14.05 | 2 |
| Mbah | 13.60 | 3 |
| Atangana | 13.01 | **4** |
| Tabi | 13.01 | **4** |
| Njoya | 11.40 | 6 |

Atangana and Tabi tie at 13.01 after rounding (raw 13.0138… and 13.0072…) and both print `4ᵉ`; rank 5 is skipped; Njoya is `6ᵉ`. Two students in the cohort have NULL averages: they print *Non évalué*, receive no rank, and **the denominator is 6, not 8**.

### 10.5 NC — non classé

`Enrollment.assessable_from_period_id` (defined in `07-students`) marks the first period in which a student is assessable — a November arrival is not ranked against students who sat Séquence 1.

A student is **NC (non classé)** and receives no rank when:

- the period precedes `assessable_from_period_id`; or
- the count of leaf periods in which the student has a non-NULL average is `< framework.min_periods_assessed` (for the term/annual card); or
- the general average is NULL (§10.2).

**NC students are excluded from the ranking denominator and from class statistics**, and the card prints `Rang : NC` with a footnote naming the reason. They still receive a full report card with their marks.

### 10.6 Subject rank

Defined explicitly, because v1 did not define it and three implementations are possible:

- **Population:** the same `rank_cohort` as the general rank, restricted to students with a non-NULL score in **that** subject allocation.
- **Value:** the **normalised, framework-scaled subject score** (stage 4 output, rounded to `score_precision`) — never the raw component mark, which is not comparable across override maxima.
- **Tie rule:** competition ranking, as §10.4.

### 10.7 Class statistics

Computed per (period, class group, and separately per subject allocation), over **ranked, non-NULL** students only.

| Statistic | Definition |
|---|---|
| `n` | count of non-NULL averages in the cohort |
| `mean` | Σ avg / n, on rounded values |
| `min`, `max` | *Cote* — printed as `[Min–Max]` beside the subject line |
| `median` | lower median for even n, stated |
| `stdev` | **population standard deviation, divisor `n`** — stated explicitly on the report and in the API field name `stdev_population`. v1 said "stdev" and would have been implemented as sample stdev by half the team; at n = 62 the two differ by ~0.8 %, which is enough to make two schools' figures irreconcilable |
| `pass_count`, `pass_rate` | `count(avg >= framework.pass_score) / n`, using §10.3 and nothing else |

Statistics are computed **inside the publication transaction** and snapshotted (§14). A report card printed a month later shows the class mean as it was at publication, not as it is today.

---

## 11. Grade points and GPA

The Statement of Results (00-core §13.4) is built entirely on grade points; v1's engine had none.

```
GpaScale        framework_id, code, name, max_point DECIMAL(4,2),
                rounding_dp TINYINT, UNIQUE(framework_id, code)
```

Grade points come from `GradeBand.grade_point`. GPA is **coefficient-weighted**:

```
GPA = Σ( grade_point(subject_score) × coefficient ) / Σ coefficient
```

**Worked example** using the §10.1 table and the §3.3 band points:

| Subject | M | band | point | Coef | pt × Coef |
|---|---|---|---|---|---|
| Maths | 13.00 | Assez Bien | 3.00 | 4 | 12.00 |
| Physique | 11.50 | Passable | 2.00 | 3 | 6.00 |
| SVT | 14.25 | Bien | 4.00 | 3 | 12.00 |
| Français | 12.00 | Assez Bien | 3.00 | 4 | 12.00 |
| Anglais | 15.00 | Bien | 4.00 | 2 | 8.00 |
| Hist-Géo | 13.50 | Assez Bien | 3.00 | 2 | 6.00 |
| | | | | **18** | **56.00** |

```
GPA = 56.00 / 18 = 3.111… → 3.11 / 5.00
```
Note the GPA is computed from **banded** points, so it is deliberately coarser than the average and the two will not track linearly. Both are printed; neither is derived from the other.

`GradeBand.grade_point` is NULL-able; if any banded subject in scope has a NULL point, **GPA is NULL** rather than silently computed over a subset.

---

## 12. Report-card content entities

**C5.** Five printed blocks had no backing entity in v1 — they were described in prose and could not be stored.

### 12.1 `ReportCardRemark`

| Column | Notes |
|---|---|
| `enrollment_id` | RESTRICT |
| `assessment_period_id` | RESTRICT |
| `scope` | ENUM(`subject`,`class_master`,`principal`) |
| `subject_allocation_id` | NOT NULL sentinel 0 unless `scope = subject` |
| `body` | VARCHAR(1000) |
| `remark_template_id` | FK NULL (§16.3) |
| `author_staff_id`, `authored_at` | RESTRICT |
| `version` | optimistic lock |

`UNIQUE(enrollment_id, assessment_period_id, scope, subject_allocation_id)`.

### 12.2 `mention` vs `conseil_award` — C6

**The conseil de classe *votes* these awards**, weighing conduct and progress, not arithmetic. v1 derived them from grade bands, which **fabricates an award on a permanent record** — a student can be shown as having received *Félicitations* from a body that never met.

| Concept | Source | Column |
|---|---|---|
| **mention** | derived from `GradeBand.mention` at print time | not stored, recomputed from the snapshot |
| **conseil_award** | **voted and stored** | `ConseilDecision.award` |

`ConseilAward` reference table: `tableau_honneur`, `felicitations`, `encouragements`, `avertissement_travail`, `avertissement_conduite`, `blame`, `mise_en_garde`. Each row carries `label`, `label_fr`, `is_positive`, `suggest_min_average` (NULL-able), `suggest_max_average`, `requires_conduct_clear`.

`suggest_*` **only produces a suggestion in the conseil UI**, pre-ticked nothing, and the suggestion is not persisted. An award appears on a card **only** because a `ConseilDecision` row exists with `decided_at` set. `AUTO_APPLY` does not exist as a code path.

### 12.3 `ConductAssessment`

The MINESEC conduct block: five graded dimensions.

| Column | Notes |
|---|---|
| `enrollment_id`, `assessment_period_id` | RESTRICT |
| `conduite` | ENUM/scale ref |
| `travail` | |
| `assiduite` | |
| `discipline` | |
| `tenue` | |
| `scale_id` | FK → `ConductScale` (`TB/B/AB/P/M` or `A/ECA/NA`, per framework) |
| `assessed_by_staff_id`, `assessed_at` | RESTRICT |
| `notes` | VARCHAR(500) NULL |

`UNIQUE(enrollment_id, assessment_period_id)`. Conduct is **not** an input to the general average and never enters §10.1.

### 12.4 `ConseilDeClasse`

| Column | Notes |
|---|---|
| `class_group_id`, `assessment_period_id` | RESTRICT |
| `held_on` | DATE |
| `president_staff_id`, `secretary_staff_id` | RESTRICT |
| `status` | ENUM(`scheduled`,`in_session`,`closed`) |
| `minutes` | TEXT NULL |
| `quorum_met` | BOOL |
| `closed_by`, `closed_at` | RESTRICT |

`UNIQUE(class_group_id, assessment_period_id)`. `ConseilAttendee` (`conseil_id`, `staff_id`, `role`, `present`) records who deliberated — the *procès-verbal* is a real document (`10-documents`).

### 12.5 `ConseilDecision`

| Column | Notes |
|---|---|
| `conseil_id` | RESTRICT |
| `enrollment_id` | RESTRICT |
| `award_code` | FK → `ConseilAward` NULL |
| `decision` | ENUM(`none`,`promoted`,`promoted_conditional`,`repeat`,`oriented`,`excluded`) NULL — the annual conseil only |
| `orientation_stream_id` | FK NULL |
| `remark` | VARCHAR(500) NULL |
| `vote_for`, `vote_against`, `vote_abstain` | SMALLINT NULL |
| `decided_at` | DATETIME |

`UNIQUE(conseil_id, enrollment_id)`. `decision` feeds `07-students`' promotion engine as **an input to be reconciled**, never overwritten silently: where the conseil decision and the computed criteria disagree, the promotion wizard surfaces both and requires an explicit override with a reason.

### 12.6 Mid-year transfer — which class group owns rank

`Mark.enrollment_id` was justified in v1 partly *for* transfers, but a transfer that creates a second `Enrollment` makes the term card see only post-transfer marks — the pre-transfer sequence disappears.

**Resolution (entity in `07-students`):** exactly **one `Enrollment` per student per academic year**, owning all marks. `EnrollmentSegment` (`enrollment_id`, `class_group_id`, `starts_on`, `ends_on`, `reason`) carries the class-group history, with a no-overlap invariant and contiguous coverage of the enrollment.

**Ownership rules, stated so two developers cannot differ:**

1. **Rank and class statistics for a period belong to the class group of the segment covering `AssessmentPeriod.ends_on`.** The student is ranked once, in the class they finished the period in.
2. **All marks in the period count**, whichever segment they were entered under. The pipeline reads `Mark`, not the segment.
3. The card prints the class group of rule 1, and lists prior segments in the period as `Transféré de 5ᵉA le 14/11/2025`.
4. A student whose segment covering `ends_on` began after `AssessmentPeriod.starts_on` is still ranked, provided §10.5's `min_periods_assessed` is met; otherwise **NC**.
5. Class rosters, effectif and the ranking denominator all derive from segments, so a transferred student never appears on two rosters for one period.

---

## 13. Report-card configuration and publication

### 13.1 `ReportCardConfig` — immutably versioned

v1's reprint fidelity guarantee was **false**: only *numbers* were snapshotted, while layout, labels, branding and the set of enabled blocks lived in a mutable config. Reprinting a January bulletin in June produced the January numbers in the June layout, with a school logo the school had since changed.

```
ReportCardConfig         id, framework_id, code, name, is_active
                         UNIQUE(framework_id, code)

ReportCardConfigVersion  id, config_id, version_no, payload JSON,
                         payload_hash CHAR(64), created_by, created_at,
                         frozen_at DATETIME NULL
                         UNIQUE(config_id, version_no)
```

- Editing a config that has **never** been used mutates the current version in place.
- Editing a config whose current version is referenced by any `ReportCardSnapshot` **creates a new version**; the old row becomes immutable (`frozen_at` set). A BEFORE UPDATE trigger rejects writes to a frozen version.
- `ReportCardSnapshot.report_card_config_version_id` is **NOT NULL**. Re-render reads the version, never the config head.

### 13.2 `PeriodPublication` — C8, per class group

v1's publication was per-period and **global**: one teacher late with one subject in one class blocked report cards for the entire school. In a 30-class secondary school this is a guaranteed weekly deadlock.

| Column | Notes |
|---|---|
| `assessment_period_id` | RESTRICT |
| `class_group_id` | RESTRICT |
| `status` | ENUM(`draft`,`marks_open`,`marks_closed`,`publishing`,`published`,`unpublished`) |
| `snapshot_batch_id` | UUID NULL — groups every snapshot written by one publication |
| `generation` | INT default 1 — incremented by each amendment (§15) |
| `report_card_config_version_id` | FK NOT NULL once published |
| `published_by`, `published_at` | RESTRICT |
| `unpublished_by`, `unpublished_at`, `unpublish_reason` | RESTRICT / VARCHAR(500) |
| `blocking_report` | JSON NULL — the last computed gate failures |
| `version` | optimistic lock |

`UNIQUE(assessment_period_id, class_group_id)`.

**Concurrency (00-core §11).** `PublishPeriod` takes `SELECT … FOR UPDATE` on the `PeriodPublication` row, sets `publishing`, validates gates, computes the pipeline, writes snapshots, sets `published`, commits. Marks entry takes a **shared lock** on the same row and is rejected unless the status is `marks_open`. Publication is check-then-act and must never be implemented as a bare read followed by a write.

**Publication gates (all blocking, all reported together, not one at a time):**

1. no `Mark` in scope with `state = pending`, unless `missing_component_policy` resolves it (§6.4);
2. `requires_hod_validation` ⇒ all marks `validated`;
3. `requires_conseil` ⇒ a `ConseilDeClasse` for (period, class group) with `status = closed`;
4. `ComponentWeight` Σ = 100 for every resolved triple in scope (§5.4);
5. `GradeBand` coverage invariant satisfied (§3.3);
6. class-master remark present for every enrollment, if the config's `class_master_remark` block is enabled and marked required;
7. `ConductAssessment` present for every enrollment, if that block is enabled and required.

**Bulk publication** publishes a selected set of class groups in one Action with per-class-group results — 25 published, 3 blocked, with reasons — never an all-or-nothing failure.

**Un-publication** is explicit, permission-gated (Principal and above), requires a reason, sets `unpublished`, and **revokes portal visibility immediately**. Snapshots are retained, never deleted; the card is simply no longer issuable. Un-publication of a period with printed cards raises a warning naming the `DocumentPrintLog` count.

### 13.3 `ReportCardSnapshot`

| Column | Notes |
|---|---|
| `enrollment_id`, `assessment_period_id` | RESTRICT |
| `class_group_id` | the owning group per §12.6 rule 1 |
| `period_publication_id` | RESTRICT |
| `generation` | INT, matches the publication generation at write time |
| `snapshot_batch_id` | UUID |
| `report_card_config_version_id` | **NOT NULL** |
| `payload` | JSON — every printed number: per-subject component marks and states, normalised ratios, subject scores, coefficients, M×Coef, totals row, general average, rank, denominator, Σcoef, mention, GPA, class statistics, conduct, remarks, conseil award and decision, attendance figures, fee balance if enabled |
| `payload_hash` | CHAR(64) SHA-256 of the canonicalised payload |
| `issued_at` | DATETIME — **printed on the card** |
| `pdf_hash` | CHAR(64) NULL — SHA-256 of the issued PDF bytes |
| `applied_policy_notes` | JSON — every `missing_component_policy` application (§6.4) |
| `superseded_by_snapshot_id` | FK NULL |

`UNIQUE(enrollment_id, assessment_period_id, generation)`.

**The snapshot is authoritative.** Re-render, portal display, transcript assembly and the Statement of Results read the snapshot and **never recompute**. Test: publish, then mutate every underlying mark, coefficient, band and config; re-render; assert the PDF hash is unchanged. (This is the same declaration `05-hr-payroll` makes for `PayrollItemSnapshot`.)

### 13.4 Conseil gate

Where `framework.requires_conseil`, publication gate 3 applies. The conseil UI shows, per student: the computed average, rank, conduct, attendance summary, previous-period average, and the **threshold-suggested** award (§12.2) as a suggestion only. Closing the conseil is a conditional UPDATE with an affected-rows check.

### 13.5 `marks_columns` — C9, the configurator must express a real bulletin

v1's configurator could not express a Cameroonian *bulletin de trimestre*, which shows per-sequence columns beside the term column:

```
Matière | Séq 1 | Séq 2 | Moy/20 | Coef | M×Coef | Rang | Cote [Min–Max] | Appréciation | Visa
```

`marks_columns` is therefore an **ordered array of parameterised objects**, not a list of enum keys:

```jsonc
{
  "marks_columns": [
    { "key": "subject_name",        "label_fr": "Matière",   "width": 30 },
    { "key": "period_score",        "label_fr": "Séq 1",     "period_ref": "child:1" },
    { "key": "period_score",        "label_fr": "Séq 2",     "period_ref": "child:2" },
    { "key": "subject_score",       "label_fr": "Moy/20",    "decimals": 2 },
    { "key": "coefficient",         "label_fr": "Coef" },
    { "key": "score_times_coef",    "label_fr": "M×Coef",    "decimals": 2 },
    { "key": "subject_rank",        "label_fr": "Rang" },
    { "key": "cote_min_max",        "label_fr": "Cote",      "format": "[{min}–{max}]" },
    { "key": "appreciation",        "label_fr": "Appréciation" },
    { "key": "teacher_visa",        "label_fr": "Visa" }
  ]
}
```

`period_ref` accepts `self`, `child:<order_index>`, `child:<code>`, `parent`, or `year`. This is the mechanism that lets a term column and its own sequence columns coexist — the single thing v1 could not do.

**Column keys** (complete set): `subject_name`, `subject_group`, `period_score`, `subject_score`, `coefficient`, `score_times_coef`, `subject_rank`, `cote_min_max`, `class_average_subject`, `appreciation`, `teacher_name`, `teacher_visa`, `competencies_assessed`, `annual_average`, `previous_term_average`, `component_score` (with `component_ref`), `grade_letter`, `grade_point`.

### 13.6 The totals row

**Mandatory when `uses_coefficients`.** The printed card carries a totals row with **Σ Coef** and **Σ (M×Coef)**, and states the derivation beneath it: `Moyenne = 234,25 / 18 = 13,01`. That is where a Cameroonian reader — parent, class master, inspector — derives and checks the moyenne by hand. A card that prints only the final average cannot be verified and will be rejected.

### 13.7 Other blocks

Toggleable, each backed by a real entity: header (`state_header` per 00-core §13.1), student identity and photo, subject table, totals row, general average and rank, mention, GPA, conduct (§12.3), **absence hours** (§14), conseil award and decision, remarks (subject / class master / principal), class statistics, previous-period and annual averages, fee balance (`04-fees`), signatures and school stamp, QR verification token (00-core §13.5), **version and issue date** (§15).

---

## 14. Attendance on the bulletin — a scope consequence

MINESEC bulletins carry **heures d'absence justifiées / non justifiées**, plus *retards*, *consignes* and *exclusions*. Daily present/absent attendance **cannot produce hours**.

**Therefore `requires_per_lesson_attendance` is `true` for Families A, B and C, and per-lesson attendance is mandatory, not optional.** This changes the scope and phase ordering of the Attendance module (`07-students`), which 00-core §15 places in Phase 8 while report cards are Phase 3. **Resolution: the per-lesson attendance *schema and capture* move into Phase 3's dependency set**; the fuller attendance analytics may remain in Phase 8. Without this the Phase 3 bulletin cannot be printed and the phase's acceptance criteria cannot be met.

Volume consequence, per `07-students`: per-lesson attendance is roughly an **8× multiplier** on daily attendance — about **1.7 M rows/year at 1 200 students** — and becomes the largest table in the system. Index accordingly and make enabling it a deliberate, warned choice for non-MINESEC frameworks.

The snapshot stores the absence figures as printed; a later attendance correction does not silently change an issued card (it becomes an amendment, §15).

---

## 15. Amendment — C10, corrections are class-wide

A post-publication mark correction changes that student's average, which changes the **class mean, min, max, pass rate, standard deviation and every other student's rank** — all of which are already printed on 61 other cards. v1 treated a correction as a single-student edit, which silently made 61 cards wrong.

### 15.1 `ReportCardAmendment`

| Column | Notes |
|---|---|
| `period_publication_id` | RESTRICT |
| `from_generation`, `to_generation` | INT |
| `reason` | VARCHAR(1000) NOT NULL |
| `requested_by`, `approved_by`, `approved_at` | RESTRICT — approval is Principal-level |
| `rank_freeze_policy` | ENUM(`reissue_class`,`freeze_at_publication`) |
| `affected_enrollment_ids` | JSON — computed and returned |
| `mark_changes` | JSON — before/after per mark |
| `status` | ENUM(`draft`,`applied`) |

### 15.2 Semantics

`AmendPeriodPublication` runs under the §13.2 lock and:

1. applies the approved mark changes (each written through the optimistic lock, each audited);
2. **recomputes the entire class group**, not one student;
3. increments `PeriodPublication.generation`;
4. writes a **new generation of snapshots for every enrollment in the class group**, setting `superseded_by_snapshot_id` on the previous generation;
5. returns the set of students whose **printed values changed** — average, rank, mention, award, or any class statistic — so the school knows exactly which cards to recall and reissue.

**`rank_freeze_policy`:**

- `reissue_class` — ranks and statistics are recomputed; every affected card is reissued. Correct, and expensive.
- `freeze_at_publication` — the corrected student's own numbers are updated, but **ranks and class statistics remain at their generation-1 values**, and the card prints `Classement figé au JJ/MM/AAAA`. This exists because a school will not always recall 62 cards for a 0.25-point correction, and pretending otherwise produces off-ledger manual edits.

**Both generations print `Version 2 · Émis le 14/03/2026`** on the card. `DocumentPrintLog` (00-core §14) records which generation each printed copy carries, and reprints of a superseded generation are watermarked `DUPLICATA` and refused unless explicitly requested with a reason.

---

## 16. Examinations

Four tabs of the Examinations screen in the mockups had **zero coverage** in v1.

### 16.1 `Exam` is not an `AssessmentPeriod`

An `AssessmentPeriod` is a window of the academic calendar. An **`Exam`** is a scheduled sitting: a date, a start time, a duration, a room, invigilators, a seating plan and a mark scheme. One `AssessmentPeriod` (Séquence 3) contains many `Exam` rows (one per subject per class group). Conflating them makes it impossible to schedule, invigilate or seat anything.

```
ExamType     code, name, name_fr, is_internal, counts_toward_parent BOOL,
             default_duration_minutes, requires_seating_plan,
             UNIQUE(code)
             seeds: sequence_exam, term_exam, mock (GCE mock / Bac blanc),
                    resit, entrance, placement

Exam         id, exam_type_id, assessment_period_id, subject_allocation_id,
             class_group_id, scheduled_on DATE, starts_at TIME,
             duration_minutes SMALLINT, room_id NULL, mark_scheme_id NULL,
             max_score DECIMAL(6,3), status ENUM(planned, scheduled,
             in_progress, marked, cancelled), created_by, version
             UNIQUE(assessment_period_id, subject_allocation_id,
                    class_group_id, exam_type_id)

ExamInvigilator  exam_id, staff_id, role ENUM(chief, assistant),
                 UNIQUE(exam_id, staff_id)
                 INVARIANT: a staff member cannot invigilate two exams whose
                 [starts_at, starts_at + duration) intervals overlap on the
                 same date. Checked under lock on assignment.

ExamSeat     exam_id, enrollment_id, room_id, seat_label,
             UNIQUE(exam_id, enrollment_id), UNIQUE(exam_id, room_id, seat_label)
             INVARIANT: seats assigned per room ≤ Room.capacity, enforced under
             FOR UPDATE on the room row.

MarkScheme   id, name, framework_id, max_score, sections JSON
             (question no, max, topic), is_active
```

**Question bank: explicitly OUT of scope for v1.** `MarkScheme.sections` records question structure for per-question mark capture and item analysis; authoring, storing and randomising question content is not built. Stated here so nobody infers it from `MarkScheme`.

### 16.2 Exam marks feed the pipeline

An `Exam` result is written as a `Mark` against the exam's `component_id`, carrying `exam_id`. There is no second grading path.

### 16.3 `RemarkTemplate`

`RemarkTemplate` (`framework_id`, `scope` matching `ReportCardRemark.scope`, `code`, `body`, `body_fr`, `suggest_min_average`, `suggest_max_average`, `is_active`). Templates **populate the editor**; they are never auto-applied, for the same reason as §12.2. `ReportCardRemark.remark_template_id` records which template seeded the text, and the text remains editable and is stored in full.

### 16.4 Moderation / harmonisation

MINESEC organises **harmonised continuous assessment**; marks are moderated after entry and **the raw mark must stay recoverable**.

```
MarkModeration  id, assessment_period_id, subject_allocation_id,
                class_group_id, method ENUM(linear_shift, scale_factor,
                target_mean, manual), parameters JSON,
                applied_by, applied_at, reason VARCHAR(500) NOT NULL,
                reverted_by, reverted_at
```

On application, each affected `Mark` gets `raw_score = COALESCE(raw_score, score)` (written once, never overwritten) and a new `score`, with `moderation_id` set. Reverting restores `score = raw_score`. Both directions are audited. **The report card prints the moderated mark; the broadsheet can show both.**

*Worked example — `scale_factor` 1.10, cap at the effective maximum.* Raw marks 12.0, 18.0, 19.5 out of 20 become 13.2, 19.8 and **20.0** (capped, not 21.45). The cap is applied per mark and the number of capped marks is reported, because a moderation that caps 30 % of a class is a bad moderation and the operator must see it.

### 16.5 Mock exams

A GCE mock or *Bac blanc* is an `ExamType` with `counts_toward_parent = 0`, sat inside an `AssessmentPeriod` whose own `counts_toward_parent` is `0`. It produces marks, a report and statistics, and **is excluded from the term and annual averages** by §9.1's participation filter. It is printed on the card as an information row only, clearly labelled.

### 16.6 Re-sits

`Mark.attempt_no` (default 1) is part of the UNIQUE key. `SubjectAllocation.resit_policy` ENUM(`best_attempt`, `latest_attempt`, `capped_pass`, `not_allowed`):

- `best_attempt` — the pipeline collects `MAX(score)` across attempts;
- `latest_attempt` — highest `attempt_no`;
- `capped_pass` — a passing re-sit is recorded at exactly `framework.pass_score`;
- `not_allowed` — a second attempt is rejected at the Action.

*Worked example, `capped_pass`, pass_score 10.00:* attempt 1 = 7.00, attempt 2 = 15.50 ⇒ the pipeline uses **10.00**. Both attempts remain visible on the broadsheet and in the snapshot's `applied_policy_notes`.

### 16.7 Outputs

- **Broadsheet** — one row per student, one column per subject, plus Σcoef, average, rank, mention; the class master's working document, exportable to XLSX and PDF.
- **Mark sheet** — one subject × one class group, per-component columns, blank-fillable for offline entry and re-importable with a checksum on the header.
- **Statement of Results** — per student, grade-point based (§11), **school-issued only**, subject to 00-core §13.2 and §13.3.

---

## 17. The marks-entry screen

The single highest-traffic academic screen. Mockup: `Results management.png`.

**Contract:**

- **Scope selector:** class group → subject allocation → assessment period → component. The selector is **teacher-assignment gated**: a Teacher sees only allocations they are assigned to, or delegated (§7.5). Enforced in the Action, not the Blade view; the deny-by-default route enumeration suite (00-core §9.2) covers it.
- **Keyboard-first grid:** one row per enrollment, ordered by the class list. `↑ ↓` move rows, `Enter` moves down, `Tab` moves across components, typing replaces. Single-key state shortcuts: `a` = `absent_unjustified`, `j` = `absent_justified`, `x` = `exempt`, `Del` clears back to `pending`. A teacher must be able to enter 62 marks without touching the mouse.
- **Alpine-local state.** Keystrokes mutate a local Alpine store only. **No Livewire round-trip per cell** — at 62 students × 2 components that is 124 requests, unusable on school Wi-Fi.
- **Batched save: exactly one request per save**, carrying the changed rows and their `version` values plus one `idempotency_key` for the form instance. `SaveMarksBatch` validates range and window (§7.6), applies each row under the optimistic lock, and returns per-row outcomes. Conflicts are shown inline with the other party's value and name; nothing is silently overwritten.
- **Autosave** on a debounce and on navigate-away, using the same batch endpoint and the same idempotency key.
- **Live footer:** entered / pending counts, class mean of what is entered so far, out-of-range warnings.
- **Offline tolerance:** a failed save keeps the local buffer and retries; the teacher never loses a grid to a dropped Wi-Fi connection (00-core §3).
- **Submit for validation** is a separate, explicit action writing `MarkSubmission` (§7.3) — never a side effect of saving.

Related screens: `subject management.png` (subjects, allocations, coefficients, components, weights — with the Σ = 100 validator inline), `accademic setting.png` (frameworks, periods, bands, report-card configurator with live preview).

---

## 18. Invariants (consolidated)

1. Every `ComponentWeight` set resolving for a (framework, period, allocation) sums to **exactly 100**.
2. `GradeBand` coverage is contiguous, gapless, non-overlapping, spans `[0, ceiling]`, top band closed — per `(framework, purpose, scale_basis, class_level)`.
3. No `Mark` may exist for a non-leaf `AssessmentPeriod`.
4. `(state = 'scored') ⇔ (score IS NOT NULL)`.
5. `0 ≤ score ≤ effectiveMax(mark)`.
6. At period open, a `Mark` row exists for every (enrollment × effective allocation × required component). **"No row" is unreachable.**
7. `Σcoef = 0 ⇒ general_avg IS NULL`, excluded from ranking, ranking denominator and all statistics.
8. Composition normalises weights over **participating** children only; all-null ⇒ subject unassessed and dropped from both numerator and denominator.
9. Rounding to `score_precision` happens **once**, at the end of aggregation; rank and band read the rounded value.
10. Pass is `score >= framework.pass_score` and is computed in exactly one place.
11. A published period's snapshot is authoritative; re-render never recomputes.
12. `ReportCardSnapshot.report_card_config_version_id` is NOT NULL and points at a frozen version.
13. A `conseil_award` exists on a card **only** if a `ConseilDecision` row exists with `decided_at` set.
14. An amendment recomputes the **whole class group** and increments the generation.
15. `Mark.subject_allocation_id` is immutable after insert.
16. Deleting a `SubjectAllocation` with dependent marks is RESTRICTed.
17. A staff member never invigilates two temporally overlapping exams.
18. `Mark.raw_score`, once written by a moderation, is never overwritten.

---

## 19. Test obligations (blocking)

| # | Test |
|---|---|
| T1 | **The §2.1 counterexample**: CA 24/30 + Exam 60/100 at 30/70 ⇒ 13.20/20 PASS. Asserted numerically |
| T2 | Materialisation: open a period, assert a `Mark` row per (enrollment × allocation × required component); re-run, assert no duplicates |
| T3 | Deactivate a subject mid-year; assert **no student's average changes** for already-published periods |
| T4 | Σcoef = 0 ⇒ NULL average, no rank, absent from the ranking denominator and every statistic |
| T5 | The four mark states, per §6.4's three worked cases, to 3 dp |
| T6 | `missing_component_policy` = `redistribute` does **not** convert a plain missing exam into an A |
| T7 | Term composition with a null child renormalises (13.50, not 6.75); all-null ⇒ subject dropped |
| T8 | Annual = Σ(6 sequences)/6 = 12.90; and with a missing sequence, report card and **promotion engine** return byte-identical values |
| T9 | `max_score_override`: 34/40 ⇒ 17.00/20 |
| T10 | Ranking: competition ties, NULL students excluded from the denominator, NC students excluded |
| T11 | Rounding: two students at raw 13.0138 and 13.0072 both print 13.01 and share rank 4 |
| T12 | Band coverage validator rejects a gap, an overlap, and an open top band |
| T13 | Snapshot fidelity: publish, mutate marks + coefficients + bands + config, re-render, assert identical `pdf_hash` |
| T14 | Publication: one class group blocked does not block the other 29; bulk publish reports per class group |
| T15 | Amendment returns every affected student, not only the corrected one; generation increments; superseded snapshots are retained |
| T16 | Optimistic lock: two concurrent mark saves ⇒ the second is rejected with the conflicting value and actor |
| T17 | Publication lock: two concurrent publishes ⇒ exactly one snapshot batch |
| T18 | Entry window evaluated in `Africa/Douala`; a 00:30 local save on the closing date succeeds |
| T19 | Family F: rank, average, mention and Σcoef are absent from the payload and the rendered card |
| T20 | Competency derivation numeric → level at every boundary in both directions |
| T21 | Marks-entry batch save issues **exactly one** request for 62 changed rows |
| T22 | Deny-by-default: a Teacher cannot read or write marks for an allocation they are not assigned or delegated |
| T23 | Architecture: no average computed outside `Assessment\Domain`; no pass threshold literal outside `PassRule` |
| T24 | Invigilator overlap and seat-capacity invariants both reject |

Golden fixtures run against the 1 200-student reference dataset from 00-core §15 Phase 0, with a per-batch performance budget on report-card rendering.

---

## 20. Open items — NEEDS VERIFICATION

Nothing below is seeded. The dependent feature refuses to run until configured, per 00-core §16.

| # | Item | Blocks | 00-core gate |
|---|---|---|---|
| V1 | MINESEC **Anglophone** secondary report-card specimen; whether internal marking is **/20 with coefficients** or by percentage | Family B `max_score`, `scale_basis`, all Family B bands | 1, 5 |
| V2 | MINESEC **Francophone** *bulletin de notes* specimen — exact column set, labels, totals-row wording | Family A default `ReportCardConfig` | 2 |
| V3 | MINEDUB basic-education report-card specimen, both sub-systems | Families D, E | 3 |
| V4 | Current **APC competency framework** / learning-domain and competency statements | Families D, E, F content | 4 |
| V5 | Whether **MINEDUB primary is still on 9 monthly evaluations** for 2025-26 | the `month` period template | 4 |
| V6 | **A / ECA / NA `min_score`–`max_score` boundaries** | `CompetencyLevel` seeds (§8.3) | 4 |
| V7 | Whether GCE letter grades (A–E, U; O-Level pass A–C) are ever used for **internal** school reporting, or Board-exam only | `GradeBand.purpose` seeds | 5 |
| V8 | Official conduct scale labels and dimensions per sub-system | `ConductScale` seeds (§12.3) | — |
| V9 | Whether *conseil* award thresholds are conventionally published, and at what averages | `ConseilAward.suggest_*` — suggestions only, never applied | — |
| V10 | Standard *cote* / statistics block wording on MINESEC bulletins (`Moy. Classe`, `Cote`, `Plus forte / Plus faible moyenne`) | printed labels | — |
| V11 | Whether absence hours on the bulletin are counted per **lesson hour** or per **clock hour** of the timetable slot | §14 computation | — |

---

## 21. Change log against v1

| Ref | v1 defect | Resolution |
|---|---|---|
| C1 | Collect → Compose → Normalize | Six-stage pipeline, §2, with the pass/fail-flipping counterexample |
| C2 | "No row" was not a state | `pending` materialisation at period open, §6.2 |
| C3 | Σcoef = 0 ⇒ 0.00, Fail, last rank | NULL, excluded everywhere, §10.2 |
| C4 | No weight-sum invariant, no missing-component rule | Σ = 100 enforced; `missing_component_policy`; `required_components`, §5.4, §6.4 |
| C5 | Five printed blocks with no entity | `ReportCardRemark`, `ConductAssessment`, `ConseilDeClasse`, `ConseilDecision`, §12 |
| C6 | Awards derived from grade bands | `mention` (derived) vs `conseil_award` (voted, stored), §12.2 |
| C7 | No nursery family | Family F, five domains, competency-only, §8 |
| C8 | Global publication | `PeriodPublication` per (period, class group), bulk publish, explicit un-publish, §13.2 |
| C9 | Configurator could not express a real bulletin | Parameterised `marks_columns` with `period_ref`; mandatory totals row, §13.5–13.6 |
| C10 | Single-student correction after publication | Class-wide amendment, generations, `rank_freeze_policy`, version + issue date printed, §15 |
| H | 4-level MINEDUB scale | 3 levels, numeric → level derivation, §8.3 |
| H | Annual = weighted mean of trimesters | Σ(6 sequences)/6, one shared service with promotion, §9.2–9.4 |
| H | `max_score_override` used by nothing | Explicit precedence chain + re-scale, §6.3 |
| H | Mark keyed on enrollment broke transfers | `EnrollmentSegment`; rank owned by the end-of-period group, §12.6 |
| H | No approval chain | `workflow_state` separate from `state`; `MarkSubmission`; delegation, §7 |
| H | Reprint fidelity false | Immutable `ReportCardConfigVersion` + `pdf_hash` on the snapshot, §13.1, §13.3 |
| H | `SubjectAllocation` unscoped | `academic_year_id` UNIQUE, effective period range, `Mark.subject_allocation_id`, §5.1 |
| H | Attendance "optional" for MINESEC | Per-lesson attendance mandatory; phase-order consequence stated, §14 |
| H | Last-write-wins marks entry | Optimistic lock, §7.7 |
| H | Publication check-then-act | `FOR UPDATE` per 00-core §11, §13.2 |
| H | No timezone contract on the entry window | `business_now()` at transaction start, §7.6 |
| — | `Exam`, `ExamType`, `MarkScheme`, `RemarkTemplate` absent | §16 |
| — | No moderation, mocks, re-sits, GPA, broadsheet | §16.4–16.7, §11 |
