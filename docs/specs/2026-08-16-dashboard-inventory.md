# Dashboard inventory — every surface, every account type

**Date:** 2026-08-16
**Purpose:** the ground truth to design mockups against. Everything below is
read out of the code, not remembered: `RoleDashboard.php` (role → panels),
`ReadDashboardPanels.php` (how each number is computed), `Role.php` (the 20
account types).

---

## 1. There are three dashboard SURFACES, not one

| Surface | Route | Who | Owner |
|---|---|---|---|
| **Staff back-office** | `/dashboard` | 18 of the 20 roles | this document |
| **Guardian portal** | `/portal` | `Guardian` | a separate session — do not edit |
| **Staff self-service portal** | staff portal routes | `StaffPortal` | no dashboard exists |

`Guardian` and `StaffPortal` are portal principals: `Dashboard::mount` aborts
403 for them, and `RoleDashboard::for()` returns an empty profile so the fact
is stated rather than left as dead configuration.

---

## 2. The structural problem, stated plainly

**Eighteen roles share ONE template.** Every staff dashboard renders the same
four blocks in the same order:

1. `OVERVIEW` — a grid of KPI cards
2. `QUICK ACTIONS` — a grid of link cards
3. `WHAT'S OPEN RIGHT NOW` — a fixed six-cell definition list
4. `NEEDS ATTENTION` — a flat list of health alerts

Nothing else varies but the numbers. That is why they read as generic.

**Capability that exists and is never used:** `x-kpi-card` already accepts
`spark` (a sparkline over a series), `delta`, and `trend` (up/down). **No
dashboard passes any of the three.** Every card is a bare integer with a
caption. There is no chart, no time series, no comparison to last week, no
ranked list, and no table on any of the 18 dashboards.

**Thinness.** Panel counts per role: median **4**, minimum **1** (Front Desk),
maximum **6**. Nine of eighteen roles get three panels or fewer.

**Dead configuration:** `roles_configured` (panel) and `receive_goods` (quick
action) are defined and wired to permissions but assigned to no role.

---

## 3. Every account type

"Manages" = the domain the role actually holds rights over, derived from the
permissions its panels and actions require. `P` = panels, `QA` = quick actions.

### Leadership

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **SuperAdmin / Administrator** | the installation itself — users, settings, backups, go-live readiness | 6 | 4 | active users, system health, last backup, go-live blockers, enrolled students, cash position |
| **Principal** (Proviseur) | signs bulletins, answers for roll, money and discipline. No attendance-taking or marks-entry right | 6 | 4 | enrolment, attendance rate, unpaid invoices, open discipline cases, unpublished periods, go-live blockers |
| **Vice-Principal** (Censeur) | the academic operation day to day. No `fee.view`, so money is deliberately absent | 6 | 4 | enrolment, attendance rate, registers today, open discipline cases, marks pending validation, unpublished periods |

### Front office

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **Registrar** | admissions pipeline, enrolment, student documents, activities | 4 | 3 | admissions pipeline, enrolment, documents pending, activities running |
| **Front Desk** | visitors only — holds exactly one permission (`visitor.manage`) | **1** | 1 | visitors today |

### Money

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **Bursar** | the cash desk — collections, invoices, receivables, goods receipts | 5 | 4 | today's collections, cash desk state, unpaid invoices, aged receivables, pending receipts |
| **Accountant** | the ledger — posting, periods, trial balance, tax | 5 | 4 | cash position, unposted entries, open periods, aged receivables, unpaid invoices |

### People

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **HR Officer** | staff records, leave, timesheets. Holds `staff.view` but NOT `payroll.view` | 3 | **1** | staff count, leave requests pending, timesheets pending |
| **Payroll Officer** | the payroll run and statutory declarations | 3 | 2 | payroll run state, declarations due, staff count |

### Academics

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **Exams Officer** | the assessment chain — periods, validation, publication, exam scheduling | 5 | 3 | periods open, unpublished periods, marks pending validation, marks due, exams scheduled |
| **Class Master** (Prof. Principal) | the marks chain and his class roll. No timetable or attendance right | 4 | 2 | marks due, marks pending validation, periods open, enrolment |
| **Teacher** | own classes, own timetable, own registers, own marks | 4 | 3 | my classes, my lessons today, registers outstanding, marks due |
| **Discipline Master** | discipline cases and absence follow-up | 3 | 2 | open discipline cases, unjustified absences, attendance rate |

### Services

| Role | Manages | P | QA | Current panels |
|---|---|---|---|---|
| **Librarian** | loans, overdues, fines | 3 | **1** | books on loan, overdue loans, fines outstanding |
| **Store Keeper** | stock, requisitions, assets, maintenance | 4 | 2 | stock below reorder, open requisitions, assets in service, maintenance open |
| **Nurse** | the sick bay — consultations and referrals | **2** | **1** | consultations today, referrals to follow up |
| **Welfare Officer** | boarding, transport, insurance, the gate. NOT the sick bay, NOT discipline | 4 | 3 | hostel occupancy, transport allocations, insurance policies, visitors today |

### No dashboard

| Role | Note |
|---|---|
| **Guardian** | uses `/portal` — a different surface entirely |
| **Staff Portal** | 403s on `/dashboard`; has no dashboard of its own |

---

## 4. The full panel catalogue (47 defined, 46 in use)

Grouped by domain, with the permission each requires.

- **Identity / operations** — active_users, roles_configured *(unused)*, system_health, last_backup, go_live_blockers
- **Students / admissions** — enrolment_count, admissions_pipeline, documents_pending, activities_running
- **Teaching** — my_classes, my_timetable_today, registers_not_taken, registers_today, attendance_rate, unjustified_absences, marks_due
- **Assessment** — periods_open, unpublished_periods, marks_pending_validation, exams_scheduled
- **Money** — todays_collections, unpaid_invoices, aged_receivables, cash_desk_state, cash_position, unposted_entries, open_periods
- **Procurement / stores** — stock_below_reorder, open_requisitions, pending_receipts
- **HR / payroll** — staff_count, leave_requests_pending, timesheets_pending, payroll_run_state, declarations_due
- **Welfare** — open_discipline_cases, todays_consultations, open_referrals, hostel_occupancy, transport_allocations_active, insurance_policies_active, visitors_today
- **Library** — books_on_loan, overdue_loans, fines_due
- **Assets** — assets_in_service, maintenance_open

## 5. Quick actions (29 defined, 28 in use)

take_register, attendance_overview, enter_marks, results, my_timetable,
collect_fees, invoices, record_expense, new_journal_entry, trial_balance,
tax_dashboard, new_admission, find_student, requisitions,
receive_goods *(unused)*, stock_levels, run_payroll, staff_directory,
log_consultation, log_discipline_case, hostel_desk, transport_desk,
visitor_log, library_desk, asset_register, add_user, go_live_setup, settings,
reports

---

## 6. What each dashboard is missing

The pattern is the same everywhere: a count with no trend, no breakdown, and
no list of the things being counted. Candidates, per role:

- **Administrator** — sign-in activity over time, storage/disk trend, failed jobs, audit-log tail, per-role user split
- **Principal** — enrolment by class/level, attendance trend, collection rate vs target, results distribution, staff on leave today
- **Vice-Principal** — registers outstanding BY CLASS (a list, not a count), timetable clashes, marks-entry progress bar per subject
- **Registrar** — admissions funnel by stage, documents expiring, intake vs capacity per class
- **Front Desk** — visitors on site NOW, expected today, gate log tail. One card is not a dashboard
- **Bursar** — collections vs target trend, receivables ageing buckets (30/60/90), payment-method mix, top debtors list
- **Accountant** — trial-balance imbalance, period-close checklist, unposted by age, expense vs budget
- **HR Officer** — headcount by department, contracts expiring, leave calendar, absence rate
- **Payroll Officer** — payroll cost trend, declaration deadlines calendar, variance vs last run
- **Exams Officer** — marks-entry completion per subject/class, publication readiness checklist, exam timetable
- **Class Master** — his class roll, per-student marks status, attendance for his class
- **Teacher** — today's timetable as a timeline, per-class register status, homework due
- **Discipline Master** — cases by severity, repeat offenders, absence follow-up queue
- **Librarian** — overdues by borrower, most-borrowed titles, stock by category
- **Store Keeper** — stock movement trend, reorder list, maintenance schedule
- **Nurse** — consultations trend, common complaints, students currently in the sick bay, immunisation gaps
- **Welfare Officer** — hostel occupancy by block, route manifests, policies expiring

---

## 7. Layout decisions taken

- **Mobile is 2 columns**, not 1. Whatever the count, the grid must use
  `minmax(0, 1fr)` tracks (Tailwind's `grid-cols-N`) — an implicit `auto`
  track is floored by min-content, and `truncate`'s `white-space: nowrap`
  then sizes the track to the longest sub-line on the page. That is the bug
  fixed in `4e77f64`; it is the precondition for any fixed column count, not
  an alternative to one.

## 8. Next step

Produce mockup screens per dashboard from §6, then implement. Nothing in §6
has been designed or built yet.
