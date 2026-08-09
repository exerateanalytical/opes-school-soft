# Phase 8 Implementation Plan — Attendance, Timetable, Discipline, Promotion

Based on `docs/specs/07-students.md` §9–§10 (attendance, promotion), `docs/specs/09-ui.md` §8.6–8.7 (timetable, attendance screens), the design doc's Discipline outline, and existing module conventions (Actions as cross-module doors, `DB::table` cross-module reads, `tests/Architecture/ModuleBoundaryTest.php`, PHPStan level 8, Pest + real-MySQL RefreshDatabase, navigation flip in `app/Modules/Identity/Support/Navigation.php`).

## 0. Shared decisions (all agents must conform)

- **Migration series**: `2026_08_09_260001` – `2026_08_09_260016`, pre-assigned below. Nobody invents a filename.
- **Key rules from the spec (non-negotiable)**:
  - Attendance keys on `enrollment_id`, never `student_id` (C3). DisciplineCase keys on **both** `student_id NOT NULL` and `enrollment_id NULL`.
  - Register is a first-class header; exception rows only; `present_count = expected_count − Σ(non-present)`; `expected_count` frozen at open (C5).
  - `attendance_rate = present / (expected − suspended)`; `late` is present; `expected = 0 ⇒ rate NULL` (rendered "—", never 0%).
  - Sentinel `timetable_slot_id NOT NULL DEFAULT 0` in the register unique key; sentinel `school_section_id = 0` in calendar unique key (MySQL NULL-in-UNIQUE trap).
  - Timetable conflicts are **UNIQUE constraints**, not app checks: `(class_group_id, day, period_id, academic_year_id)`, `(staff_id, …)`, `(room_id, …)`.
  - Promotion: `UNIQUE(class_group_id, academic_year_id)` on runs; `inputs_hash` SHA-256 re-validated at apply; annual average comes from the **same Assessment service the report card uses** (call `App\Modules\Assessment\Actions\ComputePeriodResults`-adjacent annual-average Action — cross-module door, never re-derive).
  - `business_date()` helper for register dates; conditional `UPDATE … WHERE status='open'` + affected-rows check on submit; draft→submit idempotency keys on all Actions.
- **Cross-module reads** via `DB::table` only (e.g. Promotion reading `attendance_summaries`, Attendance reading `subject_allocations`, Discipline read by Attendance §9.7 consignes/exclusions).
- **Per-agent test DBs**: F1→`opeschool_test_f1` … F5→`opeschool_test_f5`; exact-path `git add`; `function_exists`-guarded globally-unique Pest helpers (prefix helpers `phase8F1…` etc.).
- **Worktrees** under the worktrees dir; never checkout in the live docroot.

## 1. New permissions (added by F5, referenced by all)

Add to `app/Modules/Identity/Domain/Permission.php` (two-segment values) and map in `Role::defaultPermissions()`:

| Case | Value | Roles |
|---|---|---|
| TimetableView | `timetable.view` | Admin, Principal, VP, Teacher, Registrar |
| TimetableManage | `timetable.manage` | Admin, Principal, VP |
| AttendanceView | `attendance.view` | Admin, Principal, VP, Teacher, Discipline Master |
| AttendanceTake | `attendance.take` | Teacher (assignment-gated in Action), VP, Admin |
| AttendanceAmend | `attendance.amend` | Admin, Principal, VP |
| AttendanceJustify | `attendance.justify` | VP, Discipline Master, Admin |
| DisciplineView | `discipline.view` (also gates student-profile Discipline tab, spec `students.discipline.view` semantics) | Admin, Principal, VP, Discipline Master |
| DisciplineManage | `discipline.manage` | VP, Discipline Master, Admin |
| PromotionEvaluate | `promotion.evaluate` | Admin, Principal |
| PromotionApply | `promotion.apply` | Principal, Admin |
| CalendarManage | `calendar.manage` | Admin, Principal |

## 2. Agent scopes (disjoint files)

### Agent F1 — Academics: School Calendar + Timetable
**Migrations (pre-assigned):**
- `2026_08_09_260001_create_school_calendar_days_table.php` — §9.2 columns; `UNIQUE(academic_year_id, date, school_section_id)` with sentinel 0.
- `2026_08_09_260002_create_timetable_periods_table.php` — per-section named periods: `school_section_id, name, sequence, starts_at TIME, ends_at TIME, is_break, duration_minutes`.
- `2026_08_09_260003_create_timetable_slots_table.php` — `class_group_id, academic_year_id, day_of_week TINYINT(1–6), timetable_period_id, subject_id, staff_member_id, room_id NULL, effective_from, effective_to NULL` + the three UNIQUE conflict keys.
- `2026_08_09_260004_add_attendance_mode_to_class_groups.php` — `attendance_mode ENUM('daily','per_lesson') DEFAULT 'daily'` on `class_groups`.

**Files:** `app/Modules/Academics/Models/{SchoolCalendarDay,TimetablePeriod,TimetableSlot}.php`; Actions `SeedSchoolCalendar`, `SetCalendarDayType`, `DefineTimetablePeriods`, `AssignTimetableSlot` (conflict UNIQUEs surfaced as domain errors), `RemoveTimetableSlot`, `SetClassGroupAttendanceMode` (rejects `daily` when framework `requires_absence_hours` — `DB::table('assessment_frameworks')` read); Livewire `app/Modules/Academics/Livewire/Timetable/Index.php` + Blade (Class/Teacher/Room tabs; Exam tab reads Phase 3 sittings via `DB::table`; Generate button = "not available" notice). Cross-module door for F2: `Academics\Actions\ResolveCalendarDay`.
**Tests:** `tests/Feature/Academics/{CalendarTest,TimetableTest,TimetableConflictTest}.php` — including the concurrent-insert conflict test (09-ui acceptance 7).

### Agent F2 — Attendance module (registers, records, summaries)
**Migrations:**
- `2026_08_09_260005_create_attendance_registers_table.php` — §9.3 exactly, sentinel slot id, all indexes §13.
- `2026_08_09_260006_create_attendance_records_table.php` — §9.4, CASCADE to register only.
- `2026_08_09_260007_create_attendance_summaries_table.php` — §9.8, `UNIQUE(enrollment_id, assessment_period_id)`.

**Files:** `app/Modules/Attendance/Models/{AttendanceRegister,AttendanceRecord,AttendanceSummary}.php` (observer blocking deletion of submitted registers); Domain enums (`AttendanceStatus`, `RegisterStatus`, `JustificationType`, `AttendanceMode`, `RegisterSession`); Actions `OpenAttendanceRegister` (roster query §9.5 in-transaction, teacher-assignment gate via `DB::table('subject_allocations')`, calendar-day gate via F1's `ResolveCalendarDay`, holiday override permission), `SubmitAttendanceRegister` (one batched save, conditional UPDATE), `AmendAttendanceRegister`, `JustifyAbsence`, `RebuildAttendanceSummary` (queued job wrapper), read-doors `GetAttendanceSummary` and `GetAttendanceRateForEnrollments` (consumed by F4 and the report card); Livewire `app/Modules/Attendance/Livewire/{TakeRegister,Index,CoverageReport}.php` (Take Attendance, Attendance Management KPIs rendering "—" on no register, register-coverage screen §9.6).
**Tests:** `tests/Feature/Attendance/{RegisterTest,RosterTest,RateFormulaTest,SummaryTest,JustificationTest,AttendanceScreensTest}.php` — must include the C5 counterexample (0 registers ⇒ NULL rate) and the ≤300 ms / one-request submit contract.

### Agent F3 — Welfare: Discipline
**Migrations:**
- `2026_08_09_260008_create_discipline_categories_table.php` — offence catalogue: `name, name_fr, severity TINYINT, default_sanction_type, is_active`.
- `2026_08_09_260009_create_discipline_cases_table.php` — `student_id FK NOT NULL RESTRICT, enrollment_id FK NULL RESTRICT, category_id, occurred_on, reported_by FK User, description, status ENUM('open','under_investigation','resolved','dismissed'), visibility ENUM('internal','guardian'), resolved_at/by, is_positive BOOLEAN` (10-documents DISC-ACTION positive entries). Indexes `(student_id, occurred_on)`, `(enrollment_id, status)`.
- `2026_08_09_260010_create_discipline_sanctions_table.php` — `discipline_case_id FK RESTRICT, type ENUM('warning','detention','consigne','suspension','exclusion','community_service','guardian_summons'), starts_on, ends_on NULL, applied_by, acknowledged_at NULL`. Suspension sanctions call `Students\Actions` doors, never write enrollments directly.

**Files:** `app/Modules/Welfare/Models/{DisciplineCategory,DisciplineCase,DisciplineSanction}.php`; Actions `OpenDisciplineCase`, `ResolveDisciplineCase`, `ApplySanction` (SanctionLadder suggestion advisory-only from prior-case count), `AcknowledgeSanction`; read-door `GetDisciplineCountsForEnrollments` (F4's criterion + §9.7 consignes/exclusions counts for report cards); Livewire `app/Modules/Welfare/Livewire/Discipline/{Index,CaseShow}.php` at `/welfare/discipline` (not in sidebar — reached from within, per 09-ui §2).
**Tests:** `tests/Feature/Welfare/{DisciplineCaseTest,SanctionTest,DisciplineScreensTest}.php`.

### Agent F4 — Students: Promotion engine
**Migrations:**
- `2026_08_09_260011_create_promotion_criteria_tables.php` — `promotion_criteria_sets` (§10.4, version, immutable-once-referenced) + `promotion_criteria`.
- `2026_08_09_260012_create_promotion_runs_table.php` — §10.2 exactly, `UNIQUE(class_group_id, academic_year_id)`, `idempotency_key UNIQUE`.
- `2026_08_09_260013_create_promotion_decisions_table.php` — §10.5, `UNIQUE(promotion_run_id, enrollment_id)`, `criteria_results JSON`.

**Files:** `app/Modules/Students/Models/{PromotionCriteriaSet,PromotionCriterion,PromotionRun,PromotionDecision}.php`; Domain enums (`PromotionOutcome`, `PromotionRunStatus`, `CriterionType`, `Comparator`); `app/Modules/Students/Support/PromotionInputsHasher.php` (canonical ordered serialisation §10.3); Actions `CreateCriteriaSet`, `EvaluatePromotionRun` (`FOR UPDATE` on run; annual average via Assessment door; attendance via F2 door — NULL ⇒ indeterminate; discipline via F3 door; fee balance via `DB::table('invoices')`/Fees door, advisory by default; conseil override), `OverridePromotionDecision`, `ApplyPromotionRun` (§10.6 steps 1–8 verbatim: hash re-validation refusal naming drifted enrollments, conditional UPDATE, segment close, next-year Enrollment with `is_repeat`, deferred group ⇒ `pending`, graduate path, events after commit); Livewire `app/Modules/Students/Livewire/Promotion/Wizard.php` (evaluate → review/override → apply).
**Tests:** `tests/Feature/Students/{PromotionCriteriaTest,PromotionEvaluateTest,PromotionHashDriftTest,PromotionApplyTest,PromotionIndeterminateTest}.php` — hash-drift refusal, double-apply hits the UNIQUE backstop, indeterminate-blocks (C5 §9.6 worked example end-to-end).

### Agent F5 — Wiring: permissions, routes, navigation, dashboard tile
Sole owner of the shared files — **no other agent touches these**:
- `app/Modules/Identity/Domain/Permission.php`, `Role.php` (§1 table) — do this **first** so F1–F4 can `Gate::authorize` against real values (seeder already iterates the enum).
- `routes/web.php`: `/timetable` (can:timetable.view), `/attendance` (can:attendance.view), `/attendance/take` (can:attendance.take), `/attendance/coverage` (can:attendance.view), `/welfare/discipline[/{case}]` (can:discipline.view), `/students/promotion` (can:promotion.evaluate). Route class names fixed by this plan; F5 lands routes **after** F1–F4 merge their Livewire classes.
- `Navigation.php`: flip `timetable` → `built => true, permission => TimetableView`; flip `attendance` → `built => true, permission => AttendanceView`; both keys thereby drop out of `placeholderRoutes()` automatically. Keep `tests/Feature/Ui/ShellTest.php` and PlaceholderRoutesTest green.
- Dashboard "Today's Attendance" KPI (renders `—` on zero registers) via F2's read door; Policies for the four areas; `tests/Feature/Ui/Phase8WiringTest.php`.

## 3. Sequencing

1. **F5 pass 1** (permissions/roles only) — merge to main immediately; F1–F4 branch from it.
2. **F1–F4 in parallel** (disjoint migrations, modules, tests, own test DBs). Hard dependency notes: F2 needs F1's `ResolveCalendarDay` + `timetable_slots` table (F1 merges migrations early or F2 stubs against the pre-assigned schema, since filenames/columns are fixed here); F4 consumes F2/F3 read doors — signatures fixed in this plan: `GetAttendanceRateForEnrollments(int $academicYearId, array $enrollmentIds): array<int, float|null>`, `GetDisciplineCountsForEnrollments(int $academicYearId, array $enrollmentIds): array<int, array{count:int, max_severity:int}>`.
3. **F5 pass 2** (routes + nav flip + dashboard) after F1–F4 merge.
4. Solo full suite on `opeschool_test`, PHPStan 0, `composer deploy`, live verify (demo login → Timetable, Attendance, Discipline, Promotion wizard).

## 4. Risks / gotchas to pre-brief agents
- migrate:fresh is slow (trigger DDL) — not hung. MySQL SUM() returns string — cast. `Carbon::parse()` only. Fresh worktrees need `npm install && npm run build`.
- Six enum states on `AttendanceRecord.status` vs three orthogonal concepts (§9.7) — do not collapse `excused` and `is_justified`.
- No ledger writes anywhere in Phase 8; if a sanction ever has a fee consequence it goes through `Accounting\Actions\PostFromEvent` (none planned for v1).
- `on_indeterminate='block'` default; `fee_clearance` `is_blocking` default 0 with the written-warning setting.

### Critical Files for Implementation
- /home/user/opes-school-soft/docs/specs/07-students.md (§9 attendance, §10 promotion — the authoritative schemas)
- /home/user/opes-school-soft/docs/specs/09-ui.md (§8.6 timetable, §8.7 attendance screen contracts)
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php (placeholder flip for `timetable`/`attendance`)
- /home/user/opes-school-soft/routes/web.php (route wiring, F5-owned)
- /home/user/opes-school-soft/app/Modules/Students/Actions/TransferStudentClass.php (canonical Action pattern: Gate → transaction → lockForUpdate → domain errors)