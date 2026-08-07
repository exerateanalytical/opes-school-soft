# OPES SCHOOL — 07 Students, Guardians, Enrollment, Admissions, Promotion, Attendance

**Version:** 2.0
**Status:** Draft for review
**Binding parent:** `00-core.md`. Where this document and 00-core disagree, 00-core wins.
**Modules owned:** `Students`, `Guardians`, `Admissions`, `Attendance`, plus the promotion engine.
**Cross-references:** `01-assessment.md` (marks, annual average, EnrollmentSegment consumers, report-card absence hours) · `04-fees.md` (invoicing, defaulters, withdrawal settlement) · `08-operations.md` (import, rollover, retention, storage) · `09-ui.md` (navigation shell) · `10-documents.md` (Transfer Certificate, Attestation of Attendance, ID card).

---

## 1. Scope and the five things that must not be got wrong

This module is the spine. Every other module keys off `Enrollment`. Five failures in v1 are load-bearing and are fixed here:

1. **No UNIQUE on Enrollment** → duplicate active enrollments → double invoicing, double effectif, two report cards, corrupted rank denominators. §4.3.
2. **Mid-year transfer loses marks** → `Mark` keys on `enrollment_id`, a transfer made a second Enrollment. Fixed with `EnrollmentSegment`. §5.
3. **Wrong-keyed satellite entities** → attendance, discipline, transport, hostel keyed on `student_id`. §3.4 states the correct key for each.
4. **Attendance had no denominator** → a class that never takes a register passes the attendance promotion criterion for everyone. Fixed with a register header. §9.
5. **The guardian authorization matrix was undefined** → the highest-risk surface in the product. Specified cell by cell in §7.5.

---

## 2. Entity map

```
Student ─┬─ StudentGuardian ── Guardian ─┬─ GuardianMeeting
         │                                ├─ GuardianCommunication
         │                                └─ GuardianDocument
         ├─ StudentDocument
         ├─ StudentMedicalRecord
         ├─ StudentStatusTransition
         ├─ StudentActivityLog
         ├─ AdmissionApplication (pre-student; converts to Student)
         └─ Enrollment (one per student × academic year)
              ├─ EnrollmentSegment (1..n; class_group + date range)
              ├─ EnrollmentStatusTransition
              ├─ AttendanceRecord ── AttendanceRegister
              ├─ DisciplineCase (also FK student_id)
              ├─ TransportAllocation / HostelAllocation  (defined in Welfare, keyed here)
              ├─ Invoice                                  (04-fees)
              ├─ Mark                                     (01-assessment)
              └─ PromotionDecision ── PromotionRun
```

Reference data owned elsewhere: `AcademicYear`, `SchoolSection`, `ClassLevel`, `ClassGroup`, `Stream`, `House` (00-core §8, Academics module).

---

## 3. Student

### 3.1 Fields

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `matricule` | VARCHAR(32) `utf8mb4_0900_as_cs` | **UNIQUE**, globally. Immutable once `matricule_is_official`. 00-core §12. |
| `matricule_is_official` | BOOLEAN | false = temporary prefix. §6.4. |
| `admission_no` | VARCHAR(32) `as_cs` | **UNIQUE**. Issued by Admissions, format `HA/ADM/{year}/{seq}`. Distinct from matricule (mockup shows both: `HA2026-00045` and `ADM/2026/045`). |
| `first_name`, `middle_name`, `last_name` | VARCHAR(80) `ai_ci` | `middle_name` nullable. |
| `preferred_name` | VARCHAR(80) | nullable |
| `date_of_birth` | DATE NOT NULL | Age is **derived**, never stored. |
| `birth_certificate_no` | VARCHAR(64) | nullable |
| `place_of_birth` | VARCHAR(120) | |
| `gender` | ENUM('male','female') | |
| `nationality` | CHAR(2) ISO-3166 | default `CM` |
| `state_of_origin` | VARCHAR(80) | Cameroonian region/department. Free text; **no seeded list** — the 10-region list is stable but department names are not verified here. |
| `religion` | VARCHAR(60) **ENCRYPTED** | 00-core §9.5 |
| `blood_group` | VARCHAR(5) **ENCRYPTED** | |
| `genotype` | VARCHAR(5) **ENCRYPTED** | sickle-cell status |
| `national_id_number` | VARCHAR(40) **ENCRYPTED** + `national_id_blind_index` CHAR(64) | blind index UNIQUE where non-null |
| `photo_path` | VARCHAR(255) | private disk only (08-operations) |
| `phone`, `email` | VARCHAR | student's own, nullable — present on the profile mockup for senior students |
| `address_line`, `city`, `region` | VARCHAR | residential |
| `house_id` | FK House NULL | RESTRICT |
| `status` | ENUM | §3.2 |
| `first_admission_date` | DATE | |
| `left_on` | DATE NULL | |
| `is_archived` | BOOLEAN | 00-core §10.5 |
| `created_by`, `updated_by` | FK User RESTRICT | |
| `pseudonymised_at` | TIMESTAMP NULL | 08-operations erasure |

**Not on Student:** `is_repeater` (moved to `Enrollment.is_repeat`, §4.1 / C4), `class_group_id`, `stream_id`, `academic_year_id`, `roll_number`, `position_in_class` — all of these are properties of an enrollment or are derived. The mockup's "Class: Form 2A", "Roll Number", "Position in Class" are **read through the current Enrollment/EnrollmentSegment**, not columns.

### 3.2 Student.status vs Enrollment.status — the derivation rule

v1 had two overlapping lifecycles with no stated relationship. They diverge within a term. **Rule: `Student.status` is a derived cache of the student's enrollment history, recomputed by one Action and never hand-edited.**

`Student.status ∈ { prospective, active, inactive, graduated, transferred_out, withdrawn, deceased }`
`Enrollment.status ∈ { pending, active, suspended, withdrawn, transferred_out, completed, cancelled }`

Derivation, evaluated in order, first match wins:

| Condition on the student's enrollments | `Student.status` |
|---|---|
| `deceased_on` is set | `deceased` |
| An enrollment in the **current** academic year with status `active` or `suspended` | `active` |
| An enrollment in the current year with status `pending` | `prospective` |
| Latest terminal enrollment is `completed` **and** its class level `is_exit_level` | `graduated` |
| Latest terminal enrollment is `transferred_out` | `transferred_out` |
| Latest terminal enrollment is `withdrawn` | `withdrawn` |
| No enrollment in the current year but at least one historical enrollment | `inactive` |
| No enrollment at all | `prospective` |

`RecomputeStudentStatus` runs inside every Action that writes an `Enrollment` status, and as a nightly reconciliation job that reports (does not silently fix) any divergence. A Pest test sweeps every enrollment-history shape and asserts the table.

`deceased` and `is_archived` are the only student-level flags an operator sets directly; both are permissioned and audited.

### 3.3 Status transition log

`StudentStatusTransition` and `EnrollmentStatusTransition` are identical in shape and both **append-only**:

`id · {student|enrollment}_id FK RESTRICT · from_status · to_status · effective_on DATE · reason_code · reason_text · actor_id FK User RESTRICT · actor_name_at_time · created_at`

This makes "history is never deleted" actually reconstructable — v1 asserted it with no mechanism. `ON DELETE RESTRICT` throughout; these rows outlive interest in them.

Allowed transitions (anything else is rejected by the Action, not merely by the UI):

```
Enrollment: pending → active | cancelled
            active  → suspended | withdrawn | transferred_out | completed
            suspended → active | withdrawn | transferred_out
            withdrawn | transferred_out | completed | cancelled → (terminal)
```

`cancelled` exists for an enrollment created in error before the student ever attended; it is the only status that permits reversal of invoicing without a credit note (04-fees), and it is blocked once any Mark, Attendance or Invoice row references the enrollment.

### 3.4 Keying matrix for satellite entities (C3)

| Entity | Keyed on | Why |
|---|---|---|
| `Mark` | `enrollment_id` (+ `EnrollmentSegment` for provenance) | 01-assessment |
| `AttendanceRecord` | `enrollment_id` + `attendance_register_id` | a repeater's attendance must not collide across years |
| `DisciplineCase` | **both** `student_id` and `enrollment_id` | the sanction ladder needs cross-year student history; promotion needs a year filter. `enrollment_id` nullable only for cases raised outside an enrolled period; `student_id` NOT NULL |
| `TransportAllocation` | `enrollment_id` | otherwise last year's allocation stays "active" forever and re-bills |
| `HostelAllocation` | `enrollment_id` | as above; bed side constrained too (00-core §10.2) |
| `Invoice` | `enrollment_id` | 04-fees |
| `StudentDocument` | `student_id` | a birth certificate is not annual |
| `StudentMedicalRecord` | `student_id` | chronic conditions persist across years |
| `StudentGuardian` | `student_id` | the relationship survives the year |
| `LibraryMember` | `student_id` | 06-assets-stores |

`ON DELETE`: every FK in the table above is **RESTRICT**. Students and enrollments are never deleted (00-core §10.5); pseudonymisation is the erasure path.

### 3.5 Derived / computed values shown on the profile

| Shown | Source |
|---|---|
| Age ("14 Years, 3 Months") | `date_of_birth` vs `business_date()` |
| Class ("Form 2A") | current `EnrollmentSegment.class_group_id` |
| Position in Class | 01-assessment ranking service for the selected period. **Never stored on Student.** |
| Roll Number | `EnrollmentSegment.roll_number` (§5.1) |
| Attendance % | §9.6 formula, with the register denominator |
| Total Due | 04-fees balance formula. Read-only here; this module never computes money. |
| Emergency Contact | primary guardian's `emergency_contact_*`, or the student's own override |

---

## 4. Enrollment

One row per student per academic year. This is the object every other module joins to.

### 4.1 Fields

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `student_id` | FK Student **RESTRICT** | |
| `academic_year_id` | FK AcademicYear **RESTRICT** | |
| `class_level_id` | FK ClassLevel RESTRICT | the level for the year; the *group* lives on the segment |
| `stream_id` | FK Stream NULL RESTRICT | drives the elective basket and the ranking cohort |
| `school_section_id` | FK SchoolSection RESTRICT | denormalised from class level, frozen at enrollment |
| `status` | ENUM | §3.2 |
| `is_repeat` | BOOLEAN NOT NULL DEFAULT 0 | **C4.** Repeating is a property of the year. Derived-and-confirmed at creation from prior-year `PromotionDecision.outcome = 'repeat'`, then frozen. |
| `enrollment_type` | ENUM('new','returning','transfer_in','re_admission') | `FeeItem.applies_to new|returning` reads **this**, never a person-level flag |
| `enrolled_on` | DATE NOT NULL | |
| `left_on` | DATE NULL | set with a terminal status |
| `assessable_from_period_id` | FK AssessmentPeriod NULL | 01-assessment; a January joiner is not ranked on Term 1 |
| `min_periods_assessed` | SMALLINT NULL | override of the framework default |
| `boarding_status` | ENUM('day','boarder') | operational default; the actual bed is a HostelAllocation |
| `previous_school_name` | VARCHAR(160) NULL | carried from admission for `transfer_in` |
| `financial_clearance` | BOOLEAN DEFAULT 0 | set by `WithdrawalSettlement` (04-fees); the Transfer Certificate gates on it |
| `active_year_key` | BIGINT **generated** | §4.3 |
| `created_by`, `updated_by` | FK User RESTRICT | |

### 4.2 Invariants

1. `enrolled_on` falls inside `[AcademicYear.starts_on, AcademicYear.ends_on]`. CHECK is not expressible across tables in MySQL 8 without a trigger; enforce in the Action **and** with a nightly integrity job.
2. `left_on >= enrolled_on` — CHECK.
3. `status` terminal ⟺ `left_on IS NOT NULL` — enforced in the Action.
4. `class_level_id.school_section_id = school_section_id`.
5. `stream_id`, where non-null, belongs to the same `school_section_id`.
6. `is_repeat = 1` requires a prior-year enrollment at the **same** `class_level_id`. Warn, do not block (a repeat can follow a transfer in).
7. Class group **capacity** is enforced under `SELECT … FOR UPDATE` on the `ClassGroup` row when a segment is opened (00-core §10.2). Over-capacity requires an explicit permissioned override, recorded with a reason on the segment.

### 4.3 The uniqueness constraint (C1)

MySQL 8 has no partial indexes. Use the 00-core §10.1 generated-column pattern **plus** a plain composite:

```sql
ALTER TABLE enrollments
  ADD COLUMN active_year_key BIGINT
    AS (CASE WHEN status IN ('pending','active','suspended')
             THEN academic_year_id END) STORED,
  ADD UNIQUE KEY uq_enrollment_active_year (student_id, active_year_key),
  ADD UNIQUE KEY uq_enrollment_student_year (student_id, academic_year_id);
```

`uq_enrollment_student_year` is the stronger of the two and is the one that matters: **one Enrollment per student per year, full stop**, including terminal ones. The generated column is retained because it is the pattern 00-core mandates and because it survives any future decision to permit a cancelled-then-recreated row (which would drop the second index and rely on the first).

The brief's `UNIQUE(student_id, academic_year_id, class_group_id)` is **deliberately not implemented**: `class_group_id` moves to `EnrollmentSegment` (§5), so including it on Enrollment would reintroduce the multi-row-per-year shape the constraint exists to prevent. The equivalent guarantee is `uq_segment_open` in §5.2.

**Test (blocking):** two concurrent `EnrollStudent` calls for the same (student, year) — one succeeds, one fails on the constraint and surfaces "already enrolled for 2026/2027", not a 500.

---

## 5. EnrollmentSegment (C2 — mid-year class transfer)

One Enrollment per student-year **owns all marks, attendance, invoices and discipline**. Segments carry the class group and a date range. A mid-year transfer closes one segment and opens another; it never creates a second Enrollment.

### 5.1 Fields

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `enrollment_id` | FK Enrollment **RESTRICT** | |
| `class_group_id` | FK ClassGroup **RESTRICT** | |
| `starts_on` | DATE NOT NULL | |
| `ends_on` | DATE NULL | NULL = open |
| `roll_number` | SMALLINT NULL | position in the class register; unique per open segment set |
| `reason` | ENUM('initial','class_transfer','stream_change','group_rebalance','correction') | |
| `reason_text` | VARCHAR(255) NULL | mandatory for `correction` |
| `open_key` | BIGINT generated | `CASE WHEN ends_on IS NULL THEN enrollment_id END` |
| `created_by` | FK User RESTRICT | |

### 5.2 Constraints

```sql
UNIQUE KEY uq_segment_open (open_key),                    -- ≤1 open segment per enrollment
UNIQUE KEY uq_segment_roll (class_group_id, roll_number, open_key_present),
CHECK (ends_on IS NULL OR ends_on >= starts_on)
```

Plus, enforced in `TransferClassGroup` under a transaction:

- Segments of one enrollment **must not overlap** and **must be contiguous** — the new segment starts the day after the previous one ends. A gap would mean days that belong to no class group, and the attendance denominator (§9) resolves a date to a class group through this table.
- The **union** of segments covers `[enrolled_on, left_on ?? year end]`.
- The first segment's `starts_on = Enrollment.enrolled_on`; `reason = 'initial'`.
- On a terminal enrollment status, the open segment's `ends_on` is set to `left_on` in the same transaction.

A nightly integrity job asserts contiguity and coverage for every enrollment in the active year and reports violations to the health page.

### 5.3 Which class group owns rank and statistics

Stated once, here, and consumed by 01-assessment:

> **For an `AssessmentPeriod` P, an enrollment's owning class group is the class group of the segment covering `P.ends_on`. If the enrollment terminated before `P.ends_on`, it is the segment covering `left_on`.**

Consequences, all intentional:

- A student who transfers from Form 2A to Form 2B in November appears in **Form 2B's** Term 1 broadsheet, rank and class statistics, once, with **all** their Term 1 marks including those earned in 2A.
- Form 2A's Term 1 class size denominator does **not** include them. Ranking is computed over the students the period-end segment resolves to, so `Σ class sizes = class-level population` exactly, with no double-count. This is precisely the "two class rosters for one term" defect C2 names.
- `Mark` carries `enrollment_segment_id` (nullable, informational) so a teacher can see which group a mark was earned in, and so a class master can be held to the marks entered under their own segment. It is **never** used as a filter for report-card composition.
- The report card prints the period-end class group. Where a transfer occurred inside the printed period, the card also prints a one-line provenance note (`Transféré de Form 2A le 12/11/2026`), driven by the segment rows.

**Worked example.** Student 1042, Form 2A, transfers to 2B on 12 Nov. Term 1 ends 15 Dec. Marks: Maths 14/20 entered 20 Oct (segment 2A), Maths 16/20 entered 2 Dec (segment 2B). Term-1 Maths composition uses **both** marks per the framework's component weights. Rank is computed inside 2B over 2B's 41 period-end students. 2A ranks 38 students, not 39. Under v1, the October mark belonged to a different `enrollment_id` and vanished — the student's Maths term average was 16 instead of 15, and 2A's class mean was computed over a student who was no longer there.

---

## 6. Admissions

### 6.1 AdmissionApplication

A pre-student record. Conversion to `Student` + `Enrollment` is a single Action.

| Column | Notes |
|---|---|
| `application_no` | VARCHAR(32) `as_cs` **UNIQUE**. Series per 00-core §12, gaps permitted. |
| `academic_year_id` | FK RESTRICT |
| `admission_term_id` | FK AssessmentPeriod NULL — "Admission Term" on the wizard |
| `class_level_id`, `stream_id`, `school_section_id` | applied-for |
| `category` | VARCHAR(40) NULL — school-defined (day/boarding intent, scholarship stream). Reference table `AdmissionCategory`, archive-flagged. |
| identity block | first/middle/last name, DOB, gender, nationality, religion, place of birth, state of origin, blood group, genotype — same types and **same encryption** as Student §3.1 |
| `previous_school_name`, `last_class_completed`, `year_completed`, `reason_for_leaving` | VARCHAR / SMALLINT |
| `special_information` | TEXT — "e.g. Medical condition, disability" per the wizard. **Encrypted**; it is health data about a minor. |
| `photo_path` | private disk, ≤2 MB, re-encoded on upload (08-operations) |
| `proposed_roll_number` | SMALLINT NULL |
| `status` | ENUM('draft','submitted','under_review','accepted','rejected','enrolled','expired','withdrawn') |
| `decided_by`, `decided_at`, `decision_reason` | FK User RESTRICT |
| `converted_student_id` | FK Student NULL RESTRICT, **UNIQUE** — the idempotency backstop on conversion |
| `purge_due_on` | DATE — §6.5 |
| `idempotency_key` | VARCHAR(64) UNIQUE (00-core §6.2 rule 7) |

Guardian data captured at step 3 is written to `AdmissionApplicationGuardian` (same shape as `Guardian` §7.1 plus `relationship`, `is_primary`, and the proposed authorization flags), then matched-or-created on conversion (§7.7).

Documents captured at step 4 go to `AdmissionApplicationDocument`, migrated to `StudentDocument` on conversion with `ON DELETE RESTRICT` and the original file moved, not copied.

### 6.2 The 5-step wizard

Per the mockup, steps are **Basic Information · Academic Details · Parent/Guardian · Other Information · Documents & Review**. (The brief's alternate labelling — Student Information / Guardian Information / Previous School / Documents Upload / Review & Confirm — describes the same five stages; the mockup wins.)

- **Draft/resume:** the application row is created with `status='draft'` on first Next. Each step is a partial save; a power cut loses at most one step. The Admissions list has a "Drafts" tab.
- **Admission-number preview:** the wizard shows the *next* number without consuming it (`Sequence.peek()`). The number is **allocated at submit**, from the row-locked sequence table. A previewed number that is never submitted is never burned; two operators previewing simultaneously see the same number and the second one's submit gets the next. The preview field is labelled "(Auto)" and is read-only, as in the mockup.
- **Admission Summary panel** re-renders from the partially-saved row: photo, generated number, DOB, gender, class applying for, academic session, plus a required-fields checklist.
- **Validation** is per-step and blocking on `*` fields; cross-step validation (e.g. age vs class level) runs at Review and warns rather than blocks — Cameroonian schools legitimately admit over-age students.
- **Printed Admission Form** is a `10-documents` deliverable, logged to `DocumentPrintLog`.

### 6.3 Conversion

`ConvertApplicationToEnrollment` (single transaction, idempotency-keyed, batch signature `forApplications(array $ids)`):

1. `FOR UPDATE` on the application; reject unless `status='accepted'`.
2. Reject if `converted_student_id IS NOT NULL` (returns the existing student — idempotent, not an error).
3. Allocate `matricule` and `admission_no` from their sequences.
4. Create `Student`, `Enrollment` (`enrollment_type='new'` or `'transfer_in'` where `previous_school_name` is set), and the initial `EnrollmentSegment` — the class **group** is chosen at this point, not at application time; the application only names a class **level**.
5. Match-or-create guardians (§7.7) and create `StudentGuardian` links with the authorization flags proposed at step 3, `valid_from = enrolled_on`.
6. Move documents.
7. Set `status='enrolled'`, `converted_student_id`.
8. Emit `student.enrolled` **after commit** (00-core §6.2 rule 6) — Fees listens and generates the invoice.

### 6.4 Matricule: the one supervised temporary → official transition

`matricule_is_official = 0` permits exactly one subsequent change. `PromoteMatriculeToOfficial(student, new_matricule)`:

- requires a dedicated permission (`students.matricule.finalise`), not `students.update`;
- requires `matricule_is_official = 0` — the conditional `UPDATE … WHERE matricule_is_official = 0` with an affected-rows check (00-core §10.4) makes this single-use under concurrency;
- writes the old and new values to `AuditLog` and to `StudentActivityLog`;
- sets `matricule_is_official = 1`, after which the column is immutable and a model observer throws on any further write.

**Format validation.** `SchoolSection.matricule_format` is a per-section template. At save of any section format, `ValidateMatriculeFormats` asserts that the configured formats are **mutually non-colliding** — that no two sections can generate the same string — by requiring either a section-discriminating literal segment or disjoint numeric ranges. Global uniqueness is guaranteed by the UNIQUE index regardless; this check exists so the failure surfaces at configuration time rather than as a mysterious insert failure in the middle of an import.

### 6.5 Rejected-application retention

`purge_due_on = decided_at + 12 months` for `status='rejected'`, `'expired'` or `'withdrawn'`. A scheduled `PurgeExpiredApplications` job **pseudonymises** rather than deletes: names, DOB, photo, contact details, `special_information` and all guardian rows replaced with a tombstone; the application number, class applied for, decision and decision date survive for admissions statistics. Deletes the files from the private disk. Audited, reported on the health page as "N applications purged", never silent. Applications with `status='enrolled'` are exempt.

### 6.6 Transfer-in and bulk transfer

- **Transfer-in** is an admission with `enrollment_type='transfer_in'`, additionally capturing prior-school report cards as `StudentDocument` and an optional `carried_forward_average` (informational only — it never enters any computation in 01-assessment).
- **Bulk transfer (internal)** — moving N students between class groups, e.g. rebalancing 2A/2B in October. `TransferClassGroupBatch(array $enrollment_ids, $to_class_group_id, $effective_on, $reason)`: one transaction, capacity checked under lock **once** for the whole batch, one segment closed and one opened per enrollment, preview showing resulting class sizes before apply.
- **Transfer-out** sets `Enrollment.status='transferred_out'`, closes the segment, and gates the Transfer Certificate on `financial_clearance` (04-fees `WithdrawalSettlement`).

---

## 7. Guardians

A first-class module with its own list and profile screens — not three pivot flags on a link table.

### 7.1 Guardian

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `guardian_no` | VARCHAR(32) `as_cs` UNIQUE | |
| `title` | VARCHAR(16) | Mr./Mrs./Dr. |
| `first_name`, `last_name` | VARCHAR(80) `ai_ci` | |
| `date_of_birth` | DATE NULL | |
| `gender` | ENUM('male','female') | |
| `nationality` | CHAR(2) | |
| `id_type` | ENUM('national_id','passport','residence_permit','drivers_licence','other') | |
| `id_number` | VARCHAR(40) **ENCRYPTED** + `id_number_blind_index` CHAR(64) | blind index UNIQUE where non-null — this is the duplicate-detection key |
| `occupation` | VARCHAR(120) | |
| `employer` | VARCHAR(120) NULL | |
| `marital_status` | ENUM('single','married','divorced','widowed','separated') | |
| `phone` | VARCHAR(24) NOT NULL | E.164-normalised on save |
| `alternative_phone` | VARCHAR(24) NULL | |
| `email` | VARCHAR(160) NULL | |
| `address_line`, `city`, `region`, `country` | VARCHAR | home address |
| `residential_status` | ENUM('own_house','rented','family_house','other') | |
| `preferred_contact_method` | ENUM('phone','sms','email','whatsapp','in_person') | |
| `language` | ENUM('en','fr') | drives every message and document sent to them |
| `emergency_contact_name` / `_phone` / `_relationship` / `_address` | VARCHAR | the mockup's Emergency Contact card |
| `photo_path` | VARCHAR(255) | private disk |
| `status` | ENUM('active','inactive') | "Active Guardian" badge |
| `notify_sms`, `notify_email`, `notify_push`, `receives_reports`, `receives_invoices` | BOOLEAN | the five preferences in the mockup. **`receives_reports` / `receives_invoices` here are *delivery* preferences, not authorization** — see §7.4. |
| `portal_user_id` | FK User NULL RESTRICT, UNIQUE | Identity module |
| `is_archived` | BOOLEAN | |

`GuardianNote`: `guardian_id`, `body` TEXT, `author_id` FK User RESTRICT, `author_name_at_time`, `created_at`. Append-only — the mockup shows "Added by / Date", so notes are attributable and are not editable in place.

### 7.2 StudentGuardian (the link)

| Column | Type | Notes |
|---|---|---|
| `student_id` | FK Student **RESTRICT** | |
| `guardian_id` | FK Guardian **RESTRICT** | |
| `relationship` | ENUM('father','mother','stepfather','stepmother','grandparent','uncle','aunt','sibling','legal_guardian','sponsor','other') | |
| `relationship_other` | VARCHAR(60) | required when `other` |
| `is_primary` | BOOLEAN | |
| `has_custody` | BOOLEAN | |
| `receives_reports` | BOOLEAN | |
| `receives_invoices` | BOOLEAN | |
| `is_emergency_contact` | BOOLEAN | |
| `is_authorised_for_pickup` | BOOLEAN | |
| `is_fee_payer` | BOOLEAN | |
| `valid_from` | DATE NOT NULL | |
| `valid_to` | DATE NULL | NULL = open-ended |
| `revocation_reason` | VARCHAR(255) NULL | mandatory when `valid_to` is set to a past/current date |
| `primary_key_col` | BIGINT generated | `CASE WHEN is_primary AND valid_to IS NULL THEN student_id END` |
| `created_by`, `updated_by` | FK User RESTRICT | |

```sql
PRIMARY KEY (id),
UNIQUE KEY uq_link (student_id, guardian_id, valid_from),
UNIQUE KEY uq_primary_guardian (primary_key_col),   -- 00-core §10.1
CHECK (valid_to IS NULL OR valid_to >= valid_from)
```

Invariants:
- **Every active student has exactly one current primary guardian.** Enforced by `uq_primary_guardian` (at most one) plus an Action-level check at enrollment and a nightly report (at least one).
- A student may have at most **one open link per guardian**; re-linking after revocation creates a new row with a later `valid_from`. Links are **never updated to change scope retroactively** — a scope change closes the current row (`valid_to = business_date()`) and inserts a successor. This is what makes "a custody change neither deletes history nor leaves stale access" true rather than aspirational.
- `is_primary = 1` implies `has_custody = 1`. Rejected otherwise.
- Unlink is `valid_to = business_date()` + `revocation_reason`. There is no hard delete. `ON DELETE` on both FKs is RESTRICT.

### 7.3 Validity gating — the exact predicate

Every authorization decision and every message-delivery decision resolves the link with:

```sql
WHERE valid_from <= CURDATE()
  AND (valid_to IS NULL OR valid_to >= CURDATE())
```

using `business_date()` (00-core §7.5), evaluated **once at transaction start** and passed down — not re-evaluated per query, so a request spanning midnight cannot see two different answers. A link with `valid_from` in the future grants nothing.

### 7.4 Two different concepts, deliberately separated

| | Lives on | Answers |
|---|---|---|
| **Delivery preference** | `Guardian.notify_sms/email/push`, `receives_reports`, `receives_invoices` | "Do we push this to them?" |
| **Authorization scope** | `StudentGuardian.has_custody / receives_reports / receives_invoices / …` | "May they see it if they go looking?" |

The guardian-level flags are per-person channel choices; the link-level flags are per-child grants. A guardian of two children may see fees for one and not the other. **Authorization is always evaluated on the link, never on the guardian row.** A test asserts that no policy in the codebase reads `Guardian.receives_*`.

### 7.5 The guardian authorization matrix — cell by cell

This is the highest-risk surface in the product. v1 named three flags and four portal scopes and drew no mapping. The following table **is** the specification; it is transcribed into a single `GuardianScopeMatrix` class in `Guardians/Domain/`, and every policy calls it. Nothing else may make this decision.

Portal scopes: **Results** · **Fees** · **Discipline** · **Documents**, plus the profile/contact and attendance surfaces the mockups require.

Legend: **✔** granted · **✘** denied · **✔†** granted with the restriction in the note.

| # | Capability (portal route / Action) | Scope | `has_custody` | `receives_reports` | `receives_invoices` | `is_fee_payer` | `is_emergency_contact` | Grant rule |
|---|---|---|---|---|---|---|---|---|
| 1 | See the child exists; name, photo, class, matricule | Profile | ✔ | ✔ | ✔ | ✔ | ✔ | **any** valid link |
| 2 | Child's DOB, gender, address, guardians list | Profile | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 3 | Child's medical: allergies + emergency-relevant conditions | Profile | ✔ | ✘ | ✘ | ✘ | ✔ | `has_custody OR is_emergency_contact` |
| 4 | Child's full medical record (genotype, blood group, notes) | Profile | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 5 | Published report card (view) | Results | ✘ | ✔ | ✘ | ✘ | ✘ | `receives_reports` |
| 6 | Published report card (download PDF) | Results | ✘ | ✔ | ✘ | ✘ | ✘ | `receives_reports` |
| 7 | Individual marks for a **published** period | Results | ✘ | ✔ | ✘ | ✘ | ✘ | `receives_reports` |
| 8 | Marks for an **unpublished** period | Results | ✘ | ✘ | ✘ | ✘ | ✘ | **nobody** — publication state is checked first, always |
| 9 | Class rank, class mean, position | Results | ✘ | ✔† | ✘ | ✘ | ✘ | `receives_reports`; **only the child's own rank and the class denominator** — never another student's mark, name or position |
| 10 | Annual average / promotion decision | Results | ✘ | ✔ | ✘ | ✘ | ✘ | `receives_reports`, and only after the decision is `applied` |
| 11 | Attendance summary (present/absent/late counts, %) | Results | ✔ | ✔ | ✘ | ✘ | ✘ | `has_custody OR receives_reports` |
| 12 | Per-day / per-lesson attendance detail | Results | ✔ | ✔ | ✘ | ✘ | ✘ | `has_custody OR receives_reports` |
| 13 | Invoice list and invoice detail | Fees | ✘ | ✘ | ✔ | ✔ | ✘ | `receives_invoices OR is_fee_payer` |
| 14 | Fee statement / outstanding balance | Fees | ✘ | ✘ | ✔ | ✔ | ✘ | `receives_invoices OR is_fee_payer` |
| 15 | Receipts (view / reprint as DUPLICATA) | Fees | ✘ | ✘ | ✔ | ✔ | ✘ | `receives_invoices OR is_fee_payer` |
| 16 | Payments **made by this guardian**, any child | Fees | ✔ | ✔ | ✔ | ✔ | ✔ | **any** valid link — it is their own transaction |
| 17 | Payments made by **another** guardian | Fees | ✘ | ✘ | ✔ | ✔ | ✘ | `receives_invoices OR is_fee_payer` |
| 18 | Initiate a payment / record intent | Fees | ✘ | ✘ | ✘ | ✔ | ✘ | `is_fee_payer` |
| 19 | Discipline case list (date, category, outcome) | Discipline | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 20 | Discipline case **narrative detail** | Discipline | ✔† | ✘ | ✘ | ✘ | ✘ | `has_custody` **and** case `visibility = 'guardian'`; cases involving another named minor are `internal` and invisible to every guardian |
| 21 | Acknowledge a sanction / respond | Discipline | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 22 | Documents the **school issues** (report card PDF, attestation, ID card) | Documents | ✔ | ✔ | ✘ | ✘ | ✘ | `has_custody OR receives_reports` |
| 23 | Documents the **guardian supplied** (birth certificate, photo) | Documents | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 24 | Upload a document | Documents | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody`; lands as `unverified`, never auto-verified |
| 25 | Delete a document | Documents | ✘ | ✘ | ✘ | ✘ | ✘ | **nobody** — staff only |
| 26 | Timetable, calendar, announcements | Profile | ✔ | ✔ | ✔ | ✔ | ✔ | **any** valid link |
| 27 | Request / view a guardian meeting | Profile | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody` |
| 28 | Edit the child's own record | — | ✘ | ✘ | ✘ | ✘ | ✘ | **nobody** |
| 29 | Edit their **own** guardian contact details | — | ✔ | ✔ | ✔ | ✔ | ✔ | any valid link, own row only; changes to `phone`/`email` require re-verification and are audited |
| 30 | Edit any authorization flag, on any link | — | ✘ | ✘ | ✘ | ✘ | ✘ | **nobody** — Registrar only, §7.6 |
| 31 | See the list of other guardians linked to the child | Profile | ✔ | ✘ | ✘ | ✘ | ✘ | `has_custody`; names and relationship only, never the other guardian's ID number or address |
| 32 | Anything concerning a child they are **not** linked to | — | ✘ | ✘ | ✘ | ✘ | ✘ | **nobody** |

Reading rules that make the table unambiguous:

- **Deny by default.** A capability absent from this table is denied. 00-core §9.2's route-enumeration suite walks every route and Action, asserts a guardian principal is denied, and **fails the build on any route not present in the allow-list keyed to a row number above**. This is the only defence against the route added next sprint.
- **Every grant is additionally conjunctive with:** (a) a **valid** link per §7.3; (b) `Guardian.status = 'active'`; (c) `User.status = 'active'`; (d) the child's `Enrollment.status ∈ (active, suspended, completed, transferred_out)` — a `cancelled` enrollment shows nothing; (e) for anything academic, the relevant `PeriodPublication.status = 'published'` (01-assessment C8) **checked before the flag**.
- **`is_primary` grants nothing on its own.** It selects the default recipient for a single-recipient communication and the default addressee on printed documents. Treating it as an authorization flag is exactly the conflation this table exists to prevent.
- **`has_custody = 0` on every link of a child** is legal and means the school holds only fee-paying sponsors. Row 2, 3, 4, 19–21, 23–24, 27, 31 then resolve to nobody. The guardian list screen flags such children as "no custodial guardian on record" because it is almost always a data-entry omission.
- **Historic access.** A guardian whose link has expired retains **no** portal access, including to periods when the link was valid. Row 16 is the sole exception — their own payment records remain visible, because those are financial records of their own transactions and 04-fees never deletes them.
- **Scope is per-child.** All 32 rows are evaluated against the specific `StudentGuardian` row for the child in the request, resolved from `(portal_user_id → guardian_id, student_id)`. There is no guardian-wide grant.
- **Staff roles are not on this table.** Staff access is 00-core §9.1. A staff user who is also a guardian holds both, evaluated independently; the portal never widens a staff scope and a staff role never widens a portal scope.

`GuardianScopeMatrix::allows(StudentGuardian $link, Capability $c): bool` is a pure `Domain/` class with no Laravel imports, and carries a **truth-table test with one case per cell** — 32 capabilities × the flag combinations that change the answer, both directions. A cell changed without its test changed fails CI.

### 7.6 Changing an authorization flag

`UpdateGuardianAuthorization` requires the `guardians.authorization.manage` permission (Registrar, Administrator; **not** Front Desk, **not** Bursar). It:

1. closes the current link row with `valid_to = business_date()` and a mandatory `revocation_reason`;
2. inserts a successor with `valid_from = business_date() + 1 day` and the new flags;
3. writes `AuditLog` with the full before/after flag set;
4. **revokes the guardian's active portal sessions for that child** immediately — otherwise a custody removal takes effect only at the next login, which in a custody dispute is the whole point of the change.

A custody removal additionally emits `guardian.custody.revoked` after commit; Communication removes them from that child's distribution lists.

### 7.7 Duplicate detection, linking, import

- **Match key** at admission and at import, in order: `id_number_blind_index` (exact) → normalised `phone` (exact) → `last_name` + `first_name` + `date_of_birth` (exact). A match presents "Link to existing guardian *Bela Merceline*?" rather than silently merging. Silent merge on a fuzzy name match across two unrelated families is a data-protection incident.
- **Merge** exists as a permissioned, audited Action that repoints `StudentGuardian`, `GuardianMeeting`, `GuardianCommunication`, `GuardianDocument` and `Payment.payer_guardian_id` (04-fees) to the survivor and archives the loser. It is never automatic and is **irreversible** — it requires typing the surviving guardian's name to confirm.
- **Bulk import** (08-operations) reuses the same match key, previews the link graph, and applies atomically.

### 7.8 GuardianMeeting and GuardianCommunication

Neither appears in v1; both are tabs on the profile mockup.

`GuardianMeeting`: `id · guardian_id FK RESTRICT · student_id FK NULL RESTRICT · scheduled_at · held_at NULL · location · meeting_type ENUM('parent_teacher','disciplinary','financial','admission','other') · requested_by ENUM('school','guardian') · agenda TEXT · attendees (JSON of staff ids + free-text names, with a `MeetingAttendee` child table for staff so the FK is real) · minutes TEXT · decisions TEXT · follow_up_action · follow_up_due_on · follow_up_status ENUM('none','open','done') · status ENUM('scheduled','held','cancelled','no_show') · created_by FK User RESTRICT`.

`GuardianCommunication` is the per-guardian message log: `guardian_id · student_id NULL · channel ENUM('sms','email','whatsapp','push','call','in_person','letter') · direction ENUM('outbound','inbound') · subject · body · sent_at · delivery_status ENUM('queued','sent','delivered','failed','unknown') · provider_reference · failure_reason · related_type/related_id (invoice, report card, discipline case) · actor_id FK User RESTRICT`. Written by the Communication module; **owned for display here**. Retention 12 months (08-operations). On a LAN deployment with no connectivity, `queued` is the normal steady state and the UI says so rather than showing a failure.

---

## 8. Student documents, medical, activity log

### 8.1 StudentDocument

`student_id FK RESTRICT · document_type_id FK RESTRICT · title · file_path · file_hash SHA-256 · mime · size_bytes · issued_on · expires_on NULL · verification_status ENUM('unverified','verified','rejected') · verified_by FK User RESTRICT · verified_at · notes · uploaded_by FK User RESTRICT · created_at`

`DocumentType` is managed reference data (archive-flagged, not soft-deleted): birth certificate, previous report card, transfer certificate, medical certificate, photo, guardian ID, other.

- **Storage:** private disk only, served through a policy-checked controller, **never `storage:link`** (00-core / 08-operations). Test: a logged-out request for a document URL 404s.
- **Deletion** sets `is_archived` and moves the file to a quarantine prefix; a scheduled job removes quarantined files after 30 days. This is what prevents the orphaned-file problem in both directions — a DB row with no file, and a file with no row. A nightly reconciliation reports both counts to the health page.
- `file_hash` UNIQUE per student, so the same scan uploaded twice is caught.

### 8.2 StudentMedicalRecord

`student_id · condition_type ENUM('allergy','chronic_condition','medication','disability','immunisation','incident') · summary VARCHAR(200) · detail TEXT **ENCRYPTED** · is_emergency_relevant BOOLEAN · severity ENUM('low','moderate','high') · recorded_by FK User RESTRICT · recorded_at · reviewed_at`

**Class teachers see `summary` only where `is_emergency_relevant = 1`.** They do not see `detail`, and they do not see non-emergency records at all. The full record is Nurse + Administrator. This narrows v1's "surfaced to class teachers", which exposed every child's full medical picture to twelve staff members.

### 8.3 StudentActivityLog

Distinct from the global `AuditLog` (00-core §14): the audit log answers "who changed this row", the activity log answers "what happened to this child", and is readable by staff without audit-log permission. Append-only, no PII beyond what the event needs.

Event taxonomy (closed set; adding a value requires a migration, which is the point):
`admitted · enrolled · class_transferred · stream_changed · suspended · reinstated · withdrawn · transferred_out · graduated · promoted · repeated · marks_published · report_card_printed · invoice_issued · payment_received · discipline_case_opened · sanction_applied · document_uploaded · document_verified · guardian_linked · guardian_unlinked · medical_record_added · attendance_flagged · matricule_finalised`

Fields: `student_id · enrollment_id NULL · event · summary · related_type/related_id · actor_id FK RESTRICT · actor_name_at_time · occurred_at`. Indexed `(student_id, occurred_at)`. Paginated in the viewer; never loaded unbounded (00-core §6.2 rule 8).

---

## 9. Attendance

### 9.1 The defect being fixed (C5)

v1: "the whole class defaults to present; mark exceptions only." Absence of a row is then indistinguishable from *register never taken*. Attendance % is computed as `1 − absences / school_days`, where `school_days` is a global constant. A class whose teacher never opened the register has zero absences and therefore **100% attendance for all 45 students**, and every one of them satisfies the promotion attendance criterion. The .NET reference does exactly this.

**Fix: the register is a first-class header row. The denominator is the count of registers actually taken, not the count of calendar days.** No register, no denominator, no attendance percentage — and no attendance-based promotion decision.

### 9.2 SchoolCalendarDay

Required for the register to be meaningful. Owned by Academics, specified here because attendance is its only consumer.

`academic_year_id FK RESTRICT · date DATE · day_type ENUM('teaching','weekend','public_holiday','school_holiday','exam','staff_day','closure') · school_section_id NULL FK RESTRICT · label · label_fr`
`UNIQUE(academic_year_id, date, school_section_id)` with a sentinel `school_section_id = 0` for all-sections rows (the MySQL NULL-in-UNIQUE trap called out in 04-fees applies identically here).

Invariant: every date in `[year.starts_on, year.ends_on]` resolves to exactly one row per section, resolving section-specific over all-sections. Seeded by the rollover wizard; public holidays are **entered by the school**, not seeded — the Cameroonian holiday calendar includes movable Islamic and Christian feasts and *NEEDS VERIFICATION* against an official annual decree. A missing calendar blocks register creation with a clear message rather than defaulting to "teaching".

### 9.3 AttendanceRegister (the header)

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT PK | |
| `class_group_id` | FK ClassGroup **RESTRICT** | |
| `academic_year_id` | FK RESTRICT | denormalised for partitioning and query |
| `date` | DATE NOT NULL | `business_date()` at creation |
| `session` | ENUM('morning','afternoon','full_day') | daily mode |
| `timetable_slot_id` | FK TimetableSlot NULL RESTRICT | per-lesson mode |
| `subject_id` | FK Subject NULL RESTRICT | per-lesson mode; denormalised from the slot |
| `mode` | ENUM('daily','per_lesson') | |
| `expected_count` | SMALLINT NOT NULL | roster size resolved at open, §9.5 |
| `present_count`, `absent_count`, `late_count`, `excused_count` | SMALLINT | maintained in the same transaction as the rows |
| `status` | ENUM('open','submitted','amended') | |
| `taken_by` | FK StaffMember **RESTRICT** | |
| `taken_at` | TIMESTAMP | |
| `submitted_at` | TIMESTAMP NULL | |
| `amended_by`, `amended_at`, `amendment_reason` | | |
| `lesson_duration_minutes` | SMALLINT NULL | per-lesson; the source of *heures d'absence* (§9.7) |

```sql
UNIQUE KEY uq_register_daily  (class_group_id, date, session, timetable_slot_id)
```
With `timetable_slot_id` NOT NULL DEFAULT 0 (sentinel) so the daily and per-lesson cases share one index without MySQL's NULL-duplicate semantics defeating it.

A register may only be opened for a date whose `SchoolCalendarDay.day_type` is `teaching` or `exam`. Opening one on a public holiday requires an override permission and a reason — schools do hold Saturday classes, and refusing outright is worse than recording the exception.

### 9.4 AttendanceRecord (the exception rows)

| Column | Type | Notes |
|---|---|---|
| `attendance_register_id` | FK AttendanceRegister **CASCADE** | the only cascade in this document, and only because a record is meaningless without its header and both are written in one transaction. Registers themselves are never deleted once `submitted`; a model observer enforces it. |
| `enrollment_id` | FK Enrollment **RESTRICT** | **C3** — not `student_id` |
| `status` | ENUM('present','absent','late','excused','sick','suspended') | |
| `is_justified` | BOOLEAN NOT NULL DEFAULT 0 | §9.7 — **distinct from `excused`** |
| `justification_type` | ENUM('medical','family','administrative','transport','other') NULL | |
| `justification_document_id` | FK StudentDocument NULL RESTRICT | |
| `justified_by`, `justified_at` | FK User RESTRICT | |
| `minutes_late` | SMALLINT NULL | retards |
| `remark` | VARCHAR(160) NULL | |
| `recorded_by` | FK User RESTRICT | |

```sql
UNIQUE KEY uq_record (attendance_register_id, enrollment_id)
```

**Storage rule:** exception rows only. `present` rows are **not** written for the daily mode — `present_count = expected_count − Σ(non-present)`, and the header's counts are the authority. This keeps the table at ~5–8% of the naive size while remaining sound, *because the denominator now comes from the header rather than from row absence.* That is the entire fix.

### 9.5 Resolving the roster (`expected_count`)

At register open, inside the transaction:

```sql
SELECT e.id
FROM enrollments e
JOIN enrollment_segments s ON s.enrollment_id = e.id
WHERE s.class_group_id = :class_group
  AND s.starts_on <= :date
  AND (s.ends_on IS NULL OR s.ends_on >= :date)
  AND e.status IN ('active','suspended')
  AND e.enrolled_on <= :date
  AND (e.left_on IS NULL OR e.left_on >= :date)
```

This is why segments must be contiguous and gapless (§5.2): the roster on any date is exactly one segment per enrollment. `expected_count` is **frozen on the header at open** — a student enrolled three days later does not retroactively change last Tuesday's denominator. Amending a submitted register recomputes `expected_count` only if the amendment reason is `roster_correction`.

Suspended students count in `expected_count` and are recorded with `status='suspended'`, which is neither present nor a countable absence (§9.6). Excluding them from the denominator would let a suspension inflate a class's attendance rate.

### 9.6 The percentage — one formula, stated once

For a set of registers R matching (enrollment, date range, mode, section filter):

```
sessions_expected  = |{ r ∈ R : the enrollment was on r's roster at open }|
sessions_absent    = |{ records where status ∈ (absent, sick) }|
sessions_excused   = |{ records where status = 'excused' }|
sessions_suspended = |{ records where status = 'suspended' }|
sessions_late      = |{ records where status = 'late' }|      -- late is PRESENT
sessions_present   = sessions_expected − sessions_absent − sessions_excused − sessions_suspended

attendance_rate = sessions_present / (sessions_expected − sessions_suspended)
```

Decisions, each of which someone will otherwise make differently:

- **`late` counts as present.** The mockup shows Present 102 / Late 3 / Absent 3 over 108 days at 94.3%. `102/108 = 94.44%`; `(102+3)/108 = 97.2%`. Neither equals 94.3% — the mockup's number is illustrative and is **not** a specification. We define `attendance_rate` as above, and the profile donut shows the three counts and the rate computed by this formula.
- **`excused` is removed from the numerator but stays in the denominator.** An excused absence is a real absence from instruction. It is distinguished from `absent` in the *justified* split (§9.7), not in the rate.
- **`suspended` is removed from both** — the school excluded the child; counting it against them is double punishment and it is not a measure of the child's attendance.
- **If `sessions_expected = 0`, the rate is NULL, not 0 and not 100.** It is rendered "not recorded", and §10 refuses to evaluate the attendance promotion criterion on it. This is C5's fix expressed in one line.

**Worked counterexample (C5).** Form 3C, 45 students, one term, 62 teaching days. The teacher takes no registers.
- v1: absences = 0; denominator = the global `school_days` constant, 62; rate = 100% for all 45; every student passes an "≥80% attendance" promotion criterion.
- v2: `sessions_expected = 0`; rate = NULL; the promotion run reports **"Attendance criterion cannot be evaluated for 45 students — 0 of 62 registers taken in Form 3C"** and, per `PromotionRun.on_indeterminate` (§10.4), either blocks or routes all 45 to manual review. The failure surfaces as a supervision problem in the principal's office instead of as a silent pass.

A **register-coverage report** (registers taken ÷ teaching days, per class group per period) is a first-class screen, not a diagnostic, precisely because this is the failure mode.

### 9.7 Justified vs excused, and heures d'absence (C6)

Three orthogonal things that v1 collapsed into one enum:

| Concept | Column | Meaning |
|---|---|---|
| Was the student there? | `status` | present / absent / late / excused / sick / suspended |
| Did the school authorise it **in advance**? | `status = 'excused'` | a sanctioned absence (school team, medical appointment notified beforehand) |
| Has a valid justification been **received**? | `is_justified` | a note, certificate or guardian call accepted **after the fact** |

An `absent` record becomes `is_justified = 1` when a guardian produces a justification; the status stays `absent`. An `excused` record is normally also `is_justified = 1` but need not be. The MINESEC bulletin needs *heures d'absence justifiées* vs *non justifiées* — that split is on `is_justified`, across both `absent` and `excused`.

**Hours.** Daily attendance cannot yield hours. For MINESEC frameworks (`AssessmentFramework.requires_absence_hours = 1`, 01-assessment):

```
heures_absence = Σ over per-lesson records where status ∈ (absent, sick, excused)
                   of register.lesson_duration_minutes / 60
```

split by `is_justified`. `retards` = count of `status='late'`. `consignes` and `exclusions` are counts from `DisciplineCase` sanctions of type `detention` and `exclusion` within the period (Welfare module), not from attendance.

**Per-lesson attendance is mandatory, not optional, for any class group whose framework requires absence hours.** Enabling a MINESEC framework on a class group with `attendance_mode = 'daily'` is a configuration error rejected at save, with the message naming the report-card blocks that would print blank.

### 9.8 Volume — stated, budgeted, and made a deliberate choice

For 1,200 students:

| Mode | Registers/year | Records/year (exceptions at ~7%) | Naive full-matrix rows |
|---|---|---|---|
| Daily, 1 session, 40 class groups, 180 teaching days | 7,200 | ~15,000 | 216,000 |
| Per-lesson, 8 slots/day | 57,600 | ~120,000 | **1,728,000** |

Per-lesson is an **8× multiplier** and the exception-row model is what keeps it tractable — 1.7M rows becomes ~120k records plus 58k headers. Even so:

- `attendance_registers`: `INDEX (class_group_id, date)`, `INDEX (academic_year_id, date)`, `INDEX (taken_by, date)`.
- `attendance_records`: `INDEX (enrollment_id, attendance_register_id)`, `INDEX (attendance_register_id, status)`, and `INDEX (enrollment_id, status)` for the justified/unjustified rollup.
- **Per-period attendance summaries are persisted**, not recomputed: `AttendanceSummary(enrollment_id, assessment_period_id, sessions_expected, present, absent, excused, late, suspended, hours_absent_justified, hours_absent_unjustified, retards, computed_at)` with `UNIQUE(enrollment_id, assessment_period_id)`, rebuilt by a queued job on register submit and **frozen into the report-card snapshot** at publication (01-assessment). A published card must reproduce byte-identically; recomputing from live registers cannot guarantee that.
- Switching a section to `per_lesson` is a settings change behind an explicit confirmation that states the projected row count and the storage impact for this school's actual roster size. Enabling it accidentally on a 1,200-student school is a decision, not a default.
- Archival: attendance records older than 3 completed academic years move to `attendance_records_archive` alongside the audit-log partitioning (00-core §14). Summaries are never archived.

### 9.9 Taking the register — the screen contract

- Teacher-assignment gated: a teacher may open a register only for a class group they hold a current `SubjectAllocation` or class-mastership for. Enforced in the Action, not the UI.
- Default all present; the teacher taps only exceptions. Alpine-local state, **one batched save**, ≤1 request per submit, ≤300 ms server time for 45 students.
- Submit is a conditional `UPDATE … WHERE status = 'open'` with an affected-rows check (00-core §10.4) — a double-tapped submit cannot write twice.
- Amendment after submit requires a reason, sets `status='amended'`, writes `AuditLog`, and re-queues the summary rebuild. The original counts are recoverable from the audit before/after payload.
- Offline/LAN: register submission is the highest-frequency write in the product and must never depend on the internet. It does not.

---

## 10. Promotion

### 10.1 The evaluate-then-apply problem

Promotion is a wizard: criteria are evaluated at T0 (the principal reviews a list) and applied at T5 (after a conseil, possibly days later). Marks can be amended, attendance registers submitted and discipline cases closed in between. v1 applied decisions that no longer matched the reasons displayed.

**Fix: persist the evaluation, hash its inputs, re-validate at apply.**

### 10.2 PromotionRun

| Column | Notes |
|---|---|
| `id` | |
| `academic_year_id` | FK RESTRICT — the year being **closed** |
| `class_group_id` | FK RESTRICT |
| `target_academic_year_id` | FK RESTRICT |
| `criteria_set_id` | FK PromotionCriteriaSet RESTRICT |
| `status` | ENUM('evaluating','evaluated','under_review','applying','applied','cancelled') |
| `inputs_hash` | CHAR(64) — §10.3 |
| `evaluated_at`, `evaluated_by` | |
| `applied_at`, `applied_by` | |
| `on_indeterminate` | ENUM('block','manual_review') DEFAULT 'block' |
| `idempotency_key` | VARCHAR(64) UNIQUE |

```sql
UNIQUE KEY uq_promotion_run (class_group_id, academic_year_id)   -- 00-core §10.4
```

00-core is explicit that "apply atomically" prevents *partial* application, not *double* application — the UNIQUE is what prevents the second run. `FOR UPDATE` on the run row for the duration of both evaluate and apply (00-core §11).

### 10.3 The inputs hash

`inputs_hash = SHA-256` over a canonical, ordered serialisation of, per enrollment in the run:

- `enrollment_id`, `version` of every `Mark` row in scope (00-core §10.6);
- the annual average returned by the assessment service, to 3 dp;
- `PeriodPublication.status` and `snapshot_batch_id` for every period in the year;
- `AttendanceSummary.computed_at` and the six counts for every period;
- the id and status of every `DisciplineCase` in the year;
- `criteria_set_id` and the criteria set's own version.

At apply, the hash is recomputed. On mismatch the Action **refuses**, names the enrollments whose inputs changed and what changed, and offers re-evaluation. It does not silently re-evaluate — the principal signed off on a list, and applying a different list is the defect.

### 10.4 PromotionCriteriaSet and criteria

`PromotionCriteriaSet`: `academic_year_id · school_section_id · class_level_id NULL · name · is_active · version` (immutable once referenced by an evaluated run — the 00-core versioning pattern).

`PromotionCriterion`: `criteria_set_id · type · comparator · threshold · weight · is_blocking · sequence`

| `type` | Source | Notes |
|---|---|---|
| `annual_average` | **the same annual-average service the report card calls** (01-assessment) | non-negotiable. v1's ported promotion code used a plain unweighted mean of term percentages and produced a different number from the printed card for the same student. One service, one answer. |
| `subject_minimum` | per-subject annual average vs a floor, for named core subjects | |
| `attendance_rate` | §9.6 | **NULL rate ⇒ indeterminate**, never a pass |
| `unjustified_absence_hours` | §9.7 | |
| `discipline` | count/severity of `DisciplineCase` in the year filtered by `enrollment_id` | |
| `fee_clearance` | 04-fees balance | **advisory only by default.** Whether a school may withhold promotion for unpaid fees is a policy and possibly legal question — `is_blocking` defaults to 0 and enabling it requires an explicit setting with a written warning. *NEEDS VERIFICATION against Cameroonian regulation for accredited private establishments.* |
| `conseil_decision` | 01-assessment `ConseilDecision` | where the framework requires a conseil, it **overrides** every computed criterion |

Σ of criteria is not a weighted score by default; each criterion yields pass/fail/indeterminate and `is_blocking` decides. A weighted-score mode may be configured but is off by default because a school cannot explain it to a parent.

### 10.5 PromotionDecision

`promotion_run_id FK RESTRICT · enrollment_id FK RESTRICT · outcome ENUM('promote','repeat','conditional_promote','graduate','exclude','manual_review','indeterminate') · target_class_level_id NULL · target_class_group_id NULL · computed_outcome · overridden BOOLEAN · override_reason · overridden_by FK User RESTRICT · criteria_results JSON · annual_average DECIMAL(6,3) NULL · attendance_rate DECIMAL(6,3) NULL · applied_enrollment_id FK Enrollment NULL RESTRICT`

`UNIQUE(promotion_run_id, enrollment_id)`.

`criteria_results` stores, per criterion, the value, threshold, comparator and verdict — so the printed promotion list explains itself and an override is visibly an override. `computed_outcome` is retained beside `outcome` so a manual change is never invisible.

### 10.6 Apply

`ApplyPromotionRun` — one transaction, idempotency-keyed, `FOR UPDATE` on the run:

1. Re-validate `inputs_hash`. Refuse on drift.
2. Conditional `UPDATE … WHERE status = 'evaluated'`; 0 rows ⇒ abort.
3. Refuse if any decision is `indeterminate` and `on_indeterminate = 'block'`.
4. Per decision: close the current `EnrollmentSegment` (`ends_on = year.ends_on`), set `Enrollment.status = 'completed'`, `left_on = year.ends_on`, write the transition row.
5. Create the next-year `Enrollment` (`is_repeat = 1` where outcome is `repeat`, `enrollment_type = 'returning'`) and its initial segment. Class **group** assignment may be deferred — `target_class_group_id` NULL creates the enrollment with the level and stream and leaves the group to the rollover wizard (08-operations); the enrollment is `pending` until a group is set.
6. `graduate` creates no new enrollment and drives `Student.status` to `graduated` via §3.2.
7. `Enrollment` UNIQUE (§4.3) is the backstop: a replayed apply hits the constraint and rolls back rather than double-enrolling.
8. Emit `student.promoted` / `student.repeated` after commit.

Cancelling an applied run is **not** supported. The corrective path is a per-student `manual` enrollment correction with a reason, because reversing 45 next-year enrollments after invoicing has already run against them is a data-integrity problem 04-fees cannot absorb.

---

## 11. Screens

### 11.1 Student Management (`student management.png`)

- Five KPI cards: Total, Male, Female, New Admissions (this term), Graduated (this year). Each is a **persisted daily count**, not a live `COUNT(*)` across 1,245 rows plus trend computation on every page load.
- Filters: search (name / student ID / admission no), Class, Status, Gender, Admission Year. Server-side, indexed, paginated (156 pages of 8 in the mockup — the real page size is 25).
- Status tabs: All · Active · Inactive · Graduated · Transferred, mapping to `Student.status` per §3.2.
- Row actions: view, edit, and an overflow menu.
- Seven quick actions: Add New Student · Bulk Import Students · Generate Student ID Cards · Print Student List · Student Promotion · Transfer Students · Export Student Data.
- "Students by Class" donut reads current-year `EnrollmentSegment` counts.
- **Export Student Data is never licence-blocked** (00-core / 08-operations licensing).

### 11.2 Student Profile (`student profie 1.png`) — 8 tabs

Header: photo, name, status badge, Student ID, Admission No, DOB + derived age, gender, blood group *(permissioned — §7.5 row 4 governs the guardian portal; for staff, Nurse/Administrator only; the class-teacher view shows emergency-relevant summary only)*, phone, email, class, house, primary guardian, emergency contact, admission date.

| Tab | Content | Source |
|---|---|---|
| Overview | Academic performance grid (per subject × term × average × grade × position), overall average row, Attendance Overview donut, Fees & Payments summary, Recent Activities, Parent/Guardian card, Documents list | 01, 09, 04, §8.3, §7 |
| Academic Records | per-year enrollments, segments, subjects, coefficients, published averages | 01 |
| Attendance | per-period summary + drill to registers, justified/unjustified split, register-coverage note where coverage is low | §9 |
| Examinations | exam entries, seating, results | 01 |
| Fees & Payments | statement, invoices, receipts, balance | 04 |
| Discipline | cases, sanctions, outcomes — **narrower permission than "sees the profile"**: `students.discipline.view` | Welfare |
| Documents | add / categorise / open / verify / archive | §8.1 |
| Activity Log | §8.3 taxonomy, paginated | §8.3 |

Quick actions: Enroll Student · Edit Profile · Print Report Card · Generate ID Card · Transfer Student.

**Performance budget:** the Overview tab issues ≤6 queries and no lazy loads; `Model::preventLazyLoading()` is on outside production.

### 11.3 Guardian Profile (`Guardian profile.png`)

Left: photo, status badge, name, relationship, contact block, Linked Students preview. Centre: Guardian Details, then the six tabs — Linked Students · Address & Contact · Documents · Meetings · Payments · Communication History. Right: Emergency Contact, Communication Preferences (five toggles per §7.1), Notes with author and date.

The Linked Students table shows Student Name, Admission No, Class, Relationship, Status, and an action. It must **also** show the authorization flags per link — the mockup omits them and an operator cannot otherwise see what a guardian is entitled to. Add a "Permissions" column rendering the granted scopes as chips, with the §7.6 edit behind its own permission.

### 11.4 Admission Wizard

Per §6.2. Photo ≤2 MB, re-encoded server-side. Cancel discards a draft after confirmation; Back never loses entered data.

---

## 12. Actions

All in `app/Modules/{Students,Guardians,Admissions,Attendance}/Actions/`. Every one: authorizes first, runs in a transaction, accepts an `idempotency_key`, exposes a batch signature where it is called cross-module (00-core §6.2).

| Action | Notes |
|---|---|
| `CreateStudent` / `UpdateStudent` | |
| `EnrollStudent` / `EnrollStudents` (batch) | capacity under lock |
| `TransferClassGroup` / `TransferClassGroupBatch` | segment close + open |
| `SuspendEnrollment` / `ReinstateEnrollment` | |
| `WithdrawEnrollment` | emits to 04-fees for `WithdrawalSettlement` |
| `TransferStudentOut` | gates the Transfer Certificate on `financial_clearance` |
| `PromoteMatriculeToOfficial` | §6.4 |
| `PseudonymiseStudent` | 08-operations; irreversible, permissioned |
| `CreateGuardian` / `UpdateGuardian` / `MergeGuardians` | |
| `LinkGuardianToStudent` / `RevokeGuardianLink` | |
| `UpdateGuardianAuthorization` | §7.6 — session revocation |
| `RecordGuardianMeeting` | |
| `SubmitAdmissionApplication` / `DecideApplication` / `ConvertApplicationToEnrollment` | |
| `PurgeExpiredApplications` | scheduled |
| `OpenAttendanceRegister` / `SubmitAttendanceRegister` / `AmendAttendanceRegister` | |
| `JustifyAbsence` | |
| `RebuildAttendanceSummary` | queued, per (enrollment, period) |
| `EvaluatePromotionRun` / `ApplyPromotionRun` | §10 |
| `ImportStudents` / `ImportGuardians` | 08-operations |
| `RecomputeStudentStatus` | §3.2 |

---

## 13. Indexes

```
students            (matricule) U · (admission_no) U · (last_name, first_name) · (status) · (house_id)
                    (national_id_blind_index) U
enrollments         (student_id, academic_year_id) U · (student_id, active_year_key) U
                    (academic_year_id, class_level_id, status) · (class_level_id, stream_id)
enrollment_segments (open_key) U · (class_group_id, starts_on, ends_on) · (enrollment_id, starts_on)
student_guardians   (student_id, guardian_id, valid_from) U · (primary_key_col) U
                    (guardian_id, valid_to) · (student_id, valid_to)
guardians           (id_number_blind_index) U · (phone) · (last_name, first_name)
attendance_registers(class_group_id, date, session, timetable_slot_id) U
                    (academic_year_id, date) · (taken_by, date) · (status, date)
attendance_records  (attendance_register_id, enrollment_id) U
                    (enrollment_id, attendance_register_id) · (enrollment_id, status)
attendance_summaries(enrollment_id, assessment_period_id) U
promotion_runs      (class_group_id, academic_year_id) U
promotion_decisions (promotion_run_id, enrollment_id) U · (enrollment_id)
student_activity_log(student_id, occurred_at)
admission_applications (application_no) U · (converted_student_id) U · (status, purge_due_on)
```

Cross-check against 08-operations' index appendix; `enrollments(class_group_id, academic_year_id, status)` named there is served here by the segment index, since `class_group_id` no longer lives on `Enrollment`. **That substitution must be reflected in 08-operations.**

---

## 14. Acceptance criteria

Blocking for Phase 2 (people & import) and Phase 8 (daily ops).

1. Concurrent duplicate enrollment: two simultaneous `EnrollStudent` calls for one (student, year) — exactly one row, a clean domain error on the other.
2. Mid-year transfer: marks earned before and after the transfer both appear on the term card; the student appears in exactly one class's rank and statistics; both class denominators are correct. §5.3's worked example as a test.
3. **Guardian matrix truth table**: one test per cell of §7.5, both directions, including the conjunctive conditions.
4. **Deny-by-default route enumeration**: every route and Action, guardian principal, denied unless allow-listed against a §7.5 row number. Fails on any unlisted route.
5. Custody revocation kills live sessions for that child within the same request.
6. An expired link grants nothing except row 16.
7. **Zero-register class**: 45 students, 0 registers, 62 teaching days → attendance rate NULL for all 45, promotion criterion indeterminate, run blocked with the count named. This is C5 and it is the single most important test in this document.
8. Attendance denominator excludes suspended sessions and includes excused ones; a property test over random register sets asserts `present + absent + excused + suspended + late-adjustments` reconciles to `expected` exactly.
9. Promotion drift: evaluate, amend one mark, apply → refused, with the enrollment and the changed input named.
10. Promotion replay: apply twice → second is a no-op, no duplicate next-year enrollment.
11. Promotion annual average equals the report card's annual average for the same student, to 3 dp, for a 200-student fixture — asserting the same service is called.
12. Matricule finalisation is single-use under concurrency.
13. Rejected application purge pseudonymises at 12 months, retains the statistics skeleton, removes the files.
14. A logged-out request for any student document or photo URL returns 404.
15. Register submit for 45 students: ≤1 request, ≤300 ms server time on reference hardware.
16. Student list at 1,245 students: first paint ≤1.5 s, no lazy loads, on the reference hardware.

---

## 15. Open items

| # | Item | Status |
|---|---|---|
| 1 | Cameroonian public-holiday calendar for the active year | **NEEDS VERIFICATION** — entered by the school, never seeded |
| 2 | Whether an accredited private establishment may lawfully withhold promotion or the Transfer Certificate for unpaid fees | **NEEDS VERIFICATION** — `fee_clearance.is_blocking` defaults to 0 |
| 3 | Whether MINESEC requires absence hours to be reported per subject or per class | **NEEDS VERIFICATION** — the model supports both (`register.subject_id`); the report-card block in 01-assessment must state which it prints |
| 4 | Cameroonian data-protection review of the guardian matrix, the medical narrowing (§8.2) and the 12-month admission purge | **Blocking gate 10** (00-core §16) |
| 5 | Official region/department reference list for `state_of_origin` | free text until verified |
| 6 | Standard relationship vocabulary expected on official Cameroonian school records | the §7.2 enum is provisional |
