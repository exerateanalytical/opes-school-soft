# Inert controls audit — 2026-08-15

## The rule

**Implement if the data exists in another module and is readable via
`DB::table`. Remove the control if the data does not exist yet.**

`Students\Show`'s docblock argued that a disabled tab is more honest than a
plausible empty grid, and it was right in Phase 2. Assessment, Attendance,
Fees, Welfare/Discipline and the activity log have all shipped since. Leaving
those tabs disabled is now the same lie pointing the other way: it tells the
operator the platform cannot show them something it can.

Where a tab is implemented and a child genuinely has no rows, it shows a
**designed empty state naming the reason and offering the action** — never a
blank grid, never a card reading "—".

## How the table names below were obtained

`artisan db:show --counts` was attempted first, as the plan asks. It ran for
over five minutes without producing the schema (and resolved against a
different default database than `.env` names), so the authoritative source
used instead was `information_schema.columns` for schema `opeschool`, read
directly through the MySQL 8.4.3 client. That is the same catalogue
`db:show` reads, without the framework boot.

**The plan's assumed names were wrong for four of the eight tables.** Every
name in the "Confirmed" section below was read out of `information_schema`,
not inferred.

## Student profile tabs

| Tab | Source (VERIFIED) | Verdict | Task |
|---|---|---|---|
| `overview` | Composed from the other tabs' counts | **Implement** | 34 |
| `academic_records` | `report_card_snapshots` + `assessment_periods` | **Implement** | 36 |
| `attendance` | `attendance_records` + `attendance_registers` | **Implement** | 35 |
| `examinations` | **no per-student examination-entry table exists** | **REMOVE** | 36 |
| `fees` | `invoices` + `invoice_lines` + `payment_allocations` | **Implement** | 35 |
| `discipline` | `discipline_cases` + `discipline_categories` | **Implement** | 35 |
| `activity_log` | `student_activity_logs` | **Implement** | 37 |

`activity_log` was excluded originally because "nothing writes to it yet".
`Students\Actions\LogStudentActivity` exists and is called; the tab renders
whatever is there, with an empty state when there is nothing — which is now a
true statement about this child rather than a claim about the platform.

### Why `examinations` is removed rather than implemented

The plan assumed `examination_entries` and `examinations`. **Neither exists.**
What exists is `exams` (a scheduled sitting: exam type, period, subject
allocation, class group, date, room, max score, status) and `exam_seatings`
(`exam_id`, `enrollment_id`, `room_id`, `seat_label`). There is **no result,
grade or outcome column anywhere in that pair** — a candidate's marks live in
`marks`, keyed on assessment period and subject allocation, which is exactly
what the Academic Records tab already reports.

An "Examinations" tab built on `exam_seatings` would show a child's seat
number and nothing else, under a heading that promises results. That is the
plausible-empty-grid failure the rule exists to prevent, so the tab is removed
from the screen entirely rather than shipped hollow. It returns when an
examination-results table does.

## Other inert controls

Enumerated by `grep -rn "cursor-not-allowed\|aria-disabled" resources/views
--include=*.blade.php` — ten files, every match read in context.

| File | Control | Verdict |
|---|---|---|
| `components/pagination.blade.php` (3 matches) | Disabled prev/next at the ends of the range | **Keep** — a correct disabled state on a control that is genuinely unavailable, not a dead button |
| `layouts/app.blade.php` | Sidebar "arrives later" nav items | **Keep** — the shell's documented roadmap treatment; permission-and-route agree by construction |
| `livewire/students/show.blade.php` | Sidebar quick action | **Keep** — same shell roadmap treatment |
| `livewire/students/show.blade.php` | Seven inert tabs | **Six implemented (34–37), one removed** (`examinations`) |
| `livewire/students/show.blade.php` | "Upload document" | **Implement** in Task 38 — the Livewire upload pattern now exists (Phase 1) |
| `livewire/students/index.blade.php` | "Add student" action | **Keep** — verified against `routes/web.php`: there is no `students.create` route, so the alternative is a link to a 404. The data (a creation screen) genuinely does not exist |
| `livewire/students/index.blade.php` | Sidebar quick action | **Keep** — shell roadmap treatment |
| `livewire/guardians/show.blade.php` | `GuardianShow::DISABLED_TABS` (Address & Contact, Documents, Payments) | **Keep** — no `guardian_documents` table exists (confirmed against the table list); Address & Contact duplicates two cards already on the page |
| `livewire/guardians/show.blade.php` | Sidebar quick action | **Keep** — shell roadmap treatment |
| `livewire/users/index.blade.php` | "User Permissions", "Activity Log" quick actions | **Keep** — shell roadmap treatment; no route exists for either |
| `livewire/dashboard.blade.php` | Quick actions whose `route` is not a string | **Keep for now** — superseded by Phase 7, which composes per-role actions |
| `livewire/fees/cashier.blade.php` | `disabled:cursor-not-allowed` on the Collect button | **Keep** — a real disabled state: no invoice selected, or the caller lacks `fee.collect`. Disabling a submit is correct; the control is not dead |
| `livewire/accounting/journal-entries/form.blade.php` | `disabled:cursor-not-allowed` on Post | **Keep** — posts only when the entry balances. Correct disabled state |
| `livewire/academics/settings/academic-settings.blade.php` | Sub-nav items with no screen | **Keep** — sub-nav roadmap treatment, same as the shell |

Nine of the fourteen entries are **correct disabled states or the shell's
documented roadmap treatment**, not dead buttons. The plan's framing ("ten
files contain dead controls") overstated it: the real dead controls were the
student tabs and the document-upload button.

## Confirmed table and column names

Read from `information_schema.columns`, schema `opeschool`. **Struck-through
names are what the plan assumed and do not exist.**

| The plan said | Reality |
|---|---|
| ~~`assessment_marks`~~ | **`marks`** — `enrollment_id`, `subject_allocation_id`, `assessment_period_id`, `component_id`, `score`, `state`, `workflow_state` enum(`draft`,`submitted`,`validated`) |
| ~~`fee_invoices`~~ with `total_amount`, `paid_amount`, `issued_on`, `due_on`, status `part_paid`/`overdue` | **`invoices`** — `invoice_no`, `student_id`, `enrollment_id`, `issue_date`, `due_date`, `status` enum(`draft`,`issued`,`cancelled`). **There are no amount columns at all**: the total is `SUM(invoice_lines.amount + invoice_lines.tax_amount)`, and what has been paid is `SUM(payment_allocations.amount)` where `reversed_at IS NULL` |
| ~~`fee_payments`~~ | **`payments`** — `receipt_no`, `student_id`, `amount`, `unallocated_amount`; allocation is `payment_allocations` (`payment_id`, `invoice_id`, `amount`, `reversed_at`) |
| ~~`examination_entries`~~, ~~`examinations`~~ | **do not exist.** `exams` and `exam_seatings` exist; neither carries a result |
| `discipline_cases` with `reference`, `category`, `severity`, `summary` | **`discipline_cases`** exists but has **none of those four columns**. Real: `student_id`, `enrollment_id`, `discipline_category_id`, `occurred_on`, `description`, `status` enum(`open`,`under_investigation`,`resolved`,`dismissed`), `visibility`, `is_positive`. The severity lives on `discipline_categories.severity` (tinyint) and the label on `discipline_categories.name` |
| `student_activity_logs` with `description`, `created_at` | **`student_activity_logs`** exists; the columns are `event` (enum of 24 values), `summary`, `occurred_at`, `actor_name_at_time`, `related_type`, `related_id`. **There is no `created_at` and no `description`** |
| `attendance_records`, `attendance_registers` | **Correct.** `attendance_records.status` enum(`present`,`absent`,`late`,`excused`,`sick`,`suspended`); `attendance_registers.status` enum(`open`,`submitted`,`amended`), `date`, `class_group_id` |
| `report_card_snapshots`, `assessment_periods` | **Correct.** Snapshot has `generation` (not `snapshot_version`), `assessment_period_id`, `enrollment_id`, `issued_at` |
| `student_documents` with `mime_type` | **`student_documents`** — the column is **`mime`**, not `mime_type`, and `file_hash` is NOT NULL |

Two further corrections found while reading the components rather than the
schema:

- `x-status-pill` accepts `status` ∈ `ok|amber|red` **only**, plus an optional
  `label`. Passing a domain status straight through (`:status="$row->status"`)
  silently renders every row as a green "OK" pill. Every new pill below maps
  the domain status to a tone and passes the human label explicitly.
- `LogStudentActivity::handle()` takes a `StudentActivityEvent` **enum**, not
  a string, and its third argument is `$summary`, not `$description`.
