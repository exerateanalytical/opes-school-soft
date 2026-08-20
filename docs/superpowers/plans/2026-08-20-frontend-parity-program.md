# Front-end Parity Program — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:executing-plans`
> to work this plan screen-by-screen. §0 is the resume protocol; §6 is the
> single source of truth for what is done. Steps use `- [ ]` for tracking.

**Goal:** Bring every back-office screen to a measured, verified match with its
reference in `frontend images/`, without deleting or overriding any existing
module.

**Architecture:** A shared shell (sidebar, top bar, canvas) plus a small set of
measured primitives (`x-shell.*`) that every screen composes. Screens are
converted one at a time and each is verified by capturing the REAL page through
the kernel, screenshotting it headlessly at the reference's own viewport, and
diffing the measurements — never by eye.

**Tech stack:** Laravel 13 / Livewire 3 / Tailwind 4 (17px root) / Blade
components / Pest. Measuring is PHP + GD (`tools/design-parity/desktop/`).

---

## 0. Resume protocol

A session picking this up cold does exactly this, in order:

1. Read §6 (Progress ledger). The first row not marked DONE is the next job.
2. Read §2 (the loop). It is the whole method; do not invent another.
3. Read `docs/superpowers/specs/2026-08-20-admin-dashboard-measurements.md`
   for the shell's measured constants — sidebar 258+12, canvas ivory
   `#FBFAF7`, 16px row gap, the type scale.
4. Work ONE screen. Update §6. Commit. Repeat.

**Never** mark a row DONE without a side-by-side sheet having been generated
and looked at. "The markup is right" is not the standard; this program exists
because measured-correct pages still looked wrong.

---

## 1. Ground rules (non-negotiable)

- **Additive only.** No module, route, permission or nav item is removed. New
  visual primitives sit ALONGSIDE the old ones (`x-shell.stat-card` next to
  `x-kpi-card`), and a screen moves over deliberately.
- **Measure, never estimate.** Every size comes out of the reference with
  `probe.php`. A number typed from eye is a guess wearing a number's clothing.
- **Real data or an honest empty state.** Never a fabricated figure to make a
  panel match a mockup. A card with no data shows an em dash, not a zero.
- **Permission-gated.** A panel a reader may not see does not render. It never
  renders as 0.
- **Two icon registers.** Chrome-on-dark is SOLID (`x-shell.icon` glyph map);
  chrome-on-white is OUTLINE (`$outlines`). Screen content keeps the existing
  `x-opes-nav-icon` outline set. Never mix registers inside one control.

---

## 2. The loop (per screen)

```bash
# 1. Measure the reference. Everything is a CSS pixel; the mockups are 1x.
php tools/design-parity/desktop/probe.php "<ref>.png" palette
php tools/design-parity/desktop/probe.php "<ref>.png" hgaps 300 320 0 1023   # row bands
php tools/design-parity/desktop/probe.php "<ref>.png" vgaps <y0> <y1> 270 1535  # column tracks
php tools/design-parity/desktop/probe.php "<ref>.png" darkrows <x0> <x1> <y0> <y1>  # type
php tools/design-parity/desktop/crop.php "frontend images/<ref>.png" X Y W H 4 out.png  # icons

# 2. Build to those numbers.

# 3. Verify. Capture through the kernel (headless Chrome cannot sign in),
#    then screenshot at the reference's own viewport.
npm run build
php artisan view:clear
php tools/design-parity/desktop/capture.php
bash tools/design-parity/desktop/shoot.sh <page> /path/out.png

# 4. Cross-compare, and LOOK at it.
php tools/design-parity/desktop/sheet.php "<ref>.png" /path/out.png /path/sheet.png

# 5. Re-measure the BUILD with the same instrument and diff the numbers.
php tools/design-parity/desktop/probe.php /path/out.png hgaps 300 320 0 1023

# 6. Clean up - these are authenticated pages.
rm -rf public/__compare
```

Add the screen to `$map` in `tools/design-parity/desktop/capture.php` before
step 3.

### Traps already paid for — do not re-discover these

| Trap | What happens | Fix |
|---|---|---|
| Interpolating a value into a Tailwind arbitrary class | Tailwind scans for COMPLETE class names at build; the rule is never generated and the layout falls back silently | Use a static class (`repeat(auto-fit,minmax(185px,1fr))`) |
| `{{ ... }}` inside a Blade comment | Blade compiles directives before stripping comments — ParseError | Never write braces or `@` inside a Blade comment |
| Reusing a Chrome `--user-data-dir` | A killed run leaves a lock; every later run hangs forever | `shoot.sh` uses a disposable profile per run |
| `Request::create('/path')` for capture | Host defaults to `localhost` with no port, `@vite` emits absolute URLs, every asset 404s, and the screenshot looks like a layout bug | Pass the full base URL |
| Assuming a bar is white | The reference's top region is the same ivory as the canvas, with no divider | Sample the column before painting |
| Guessing an enum's members | Half the labels render their raw key | `SHOW COLUMNS` first |
| An apostrophe in a French string inside a single-quoted PHP literal | The whole lang file stops parsing and every screen past that line 500s | Double-quote it. `TranslationFilesTest` now lints both files |
| `php -l ... >/dev/null && echo ok` | A parse error is invisible - the `&&` just does not fire and the "ok" never prints, which reads as quiet success | Never redirect a linter's output |

---

## 3. What is already built (do not rebuild)

- `resources/css/app.css` — `--color-shell-*` tokens, measured.
- `resources/views/components/shell/icon.blade.php` — 18 solid group glyphs
  + outline top-bar set (`arrow_right`, `bell`, `mail`, `calendar`, `clock`,
  `refresh`, `campus`, `search`, `modules`, chevrons).
- `resources/views/components/shell/sidebar.blade.php` — 258px field + 12px
  toghu strip, 18 collapsible groups, identity card, session strip.
- `resources/views/components/shell/topbar.blade.php` — greeting block, scope
  selectors, bell/mail, account menu, date/time/refresh.
- `resources/views/components/shell/panel.blade.php` — titled card + footer link.
- `resources/views/components/shell/stat-card.blade.php` — 50px disc KPI card.
- `resources/views/components/shell/toghu-strip.blade.php` — gold ground,
  dark lattice.
- `app/Modules/Identity/Support/Navigation.php` — `groups()` + `groupedItems()`.
- `app/Modules/Operations/Actions/ReadAdminDashboard.php` — the eleven panels.
- `tools/design-parity/desktop/` — `probe.php`, `crop.php`, `capture.php`,
  `shoot.sh`, `sheet.php`.

---

## 4. Known open items on the dashboard

- [ ] Money reads `0 FCFA`; the reference reads `FCFA 45,890,000` (symbol
      first). `Money::format()` is shared with invoices, receipts and
      statements, so this is a PRODUCT-WIDE currency-format decision, not a
      dashboard tweak. **Ask before changing.**
- [ ] The reference's Quick Actions is a 3x3 of nine tiles. Two of its nine —
      "School Calendar" and "Fee Structures" — have no route in this platform.
      Exact tile parity needs those screens BUILT; it must not be faked by
      pointing a tile at an unrelated page.
- [ ] `CollectHealth::handle()` pages the entire `audit_logs` table (9 x 500
      on the demo school) and runs TWICE per dashboard render — once for
      `alerts()`, once for `summary()`. Pre-existing, now on the hot path of
      every login. Worth a memoised call.
- [ ] Reference shows unread badges on bell (12) and mail (5). Real counts are
      0 here, so nothing renders. Correct, but re-check on a school with data.

---

## 4b. The reference set contains THREE design languages — read this first

Triaged 2026-08-20 by contact sheet
(`tools/design-parity/desktop/contact-sheet.php`). The 69 references are not
one design; they are three, and they contradict each other:

| Family | Files | Chrome |
|---|---|---|
| **A — OPES (canonical)** | `super admin dashbaord.png` | 258px dark sidebar + 12px toghu strip, ivory `#FBFAF7` canvas, no top-bar rule, gold active nav pill, 50px solid discs |
| B — Heritage Academy, light | `ChatGPT Image ... 08_05_58` .. `08_12_38` (14 files) | ~150px dark sidebar, WHITE top bar with search, white canvas, pastel KPI circles, "HERITAGE ACADEMY" wordmark |
| C — Heritage Academy, dark rail | `ChatGPT Image ... 08_14_53` .. `08_16_32` (5 files) | full-height dark green rail, different again |

They disagree on sidebar width, canvas colour, top-bar treatment, KPI card
style and even the product name. **No build can match all three**, so
"pixel-perfect against the reference set" is not a well-formed target until
one family is chosen.

**Decision (from the user, 2026-08-20): family A is canonical** — *"Make sure
other follow that stylling."*

So for every remaining screen:

- **CONTENT and LAYOUT** come from that screen's own reference (which panels,
  which columns, which order, which figures).
- **CHROME and VISUAL LANGUAGE** come from family A — the shell, the tokens,
  the `x-shell.*` primitives, the two icon registers.

A screen is DONE when its content matches its own reference and its styling
matches family A. Where a family-B reference shows a white top bar or a pastel
KPI circle, that is NOT reproduced; family A's ivory bar and solid disc are.
Record any such deliberate divergence in the ledger note.

---

## 5. Screen → route map

Screens with no route are FEATURE work, not styling, and are marked so.

| Reference | Route | Note |
|---|---|---|
| `super admin dashbaord.png` | `/dashboard` | done |
| `dashboard.png` | `/dashboard` | second dashboard variant — compare before acting |
| `student management.png`, `students lists.png` | `/students` | |
| `student profile.png`, `student profie 1.png` | `/students/{student}` | |
| `student profile edit view.png` | `/students/{student}` edit | |
| `admission wizard.png`, `student admission wizzard.png` | `/admissions/wizard` | |
| `Class Management.png` | `/classes` | |
| `subject management.png` | `/subjects` | |
| `accademic setting.png` | `/academics/settings` | |
| `school timetable.png`, `timetable.png` | `/timetable` | |
| `Attendance.png` | `/attendance` | |
| `examination schedule.png` | `/examinations` | |
| `Results management.png` | `/results` | |
| `finance dashboard.png` | `/finance/dashboard` | |
| `Library.png`, `libray management.png` | `/library` | |
| `Inventory management.png` | `/inventory` | |
| `Transport Management.png` | `/transport` | |
| `Hostel Management.png` | `/hostel` | |
| `Guardian profile.png` | `/guardians` | |
| `teacher profile.png` | `/staff` | |
| `report an analytics.png`, `reports.png` | `/reports` | |
| `general setting.png` | `/settings` | |
| `flow wizards.png`, `complete product overview.png` | — | overview art, not a screen |
| `Student ID V1.png`, `student ID V2.png` | document | print template, not a screen |
| `Transcript.png`, `statement of results.png`, `certificate of completion.png` | document | print templates |
| `logo.png`, `desktop icon.png` | asset | not screens |
| `ChatGPT ... 08_04_30 / 08_05_03 / 08_05_45` | — | composite grids of small screens: admission + promotion wizards, fee collection, receipt printing, payroll, discipline, medical, visitors, messaging, backup/restore, audit logs, DB maintenance. Mine for CONTENT of those screens; too small to measure |
| `ChatGPT ... 08_05_51` | — | product-overview poster, not a screen |
| `ChatGPT ... 08_05_58 / 08_06_13 / 08_12_24` | `/classes` | Class Management |
| `ChatGPT ... 08_06_34` | `/users` | User Management |
| `ChatGPT ... 08_07_05 / 08_15_41` | `/library` | Library Management |
| `ChatGPT ... 08_07_10` | `/finance/dashboard` | Finance Management |
| `ChatGPT ... 08_07_25 / 08_12_16` | `/examinations` | |
| `ChatGPT ... 08_07_31 / 08_12_20` | `/timetable` | |
| `ChatGPT ... 08_07_37 / 08_08_27` | `/attendance` | |
| `ChatGPT ... 08_08_50 / 08_15_48` | `/finance/dashboard` | Finance Dashboard |
| `ChatGPT ... 08_12_38` | `/students/{student}` | Student Profile |
| `ChatGPT ... 08_14_53 / 08_15_26` | `/settings` | two variants, family C |
| `ChatGPT ... 08_15_36` | `/inventory` | |
| `ChatGPT ... 08_16_32` | — | composite overview poster |
| `ChatGPT ... 08_14_00 / 08_14_05 / 08_14_21 / 08_14_25` | document | transcripts and diplomas — print templates |
| `ChatGPT ... 08_14_40 / 08_14_44` | document | document-template catalogues (forms, slips, certificates) |

---

## 6. Progress ledger

Status: `DONE` (built + sheet compared), `WIP`, `TODO`, `BLOCKED`.

| # | Screen | Status | Notes |
|---|---|---|---|
| 1 | Shell (sidebar / top bar / canvas) | DONE | measured; 258+12px sidebar, ivory canvas, card row at y119 vs reference y118 |
| 2 | `/dashboard` super admin | DONE | worst row offset 31px, worst height error 20px, 6/6 column tracks |
| 3 | Triage the 31 `ChatGPT Image *.png` | DONE | see §4b - three conflicting design families; family A is canonical |
| 3b | Card language across ALL screens (`x-kpi-card`) | DONE | tone paints a 50px solid disc on a white card; repaints all 42 call sites |
| 3c | Shell applied to all 19 back-office screens | DONE | every one captures 200 |
| 4 | `/students` vs `student management.png` | DONE | #/Gender/Admission Date columns; level donut + real quick actions in the rail |
| 5 | `/students/{student}` | TODO | profile screen, not started |
| 6 | `/classes` vs `Class Management.png` | DONE | 5 KPIs; Class Teacher + Students columns; level donut + utilisation rail |
| 7 | `/subjects` vs `subject management.png` | DONE | 5 KPIs + by-department rail |
| 8 | `/academics/settings` | TODO | |
| 9 | `/timetable` | TODO | renders an honest empty state - no timetable data seeded |
| 10 | `/attendance` | TODO | renders an honest empty state - no register taken today |
| 11 | `/examinations` | PARTIAL | Upcoming Exams rail done. Reference models exam EVENTS, this product models SITTINGS - KPI set and table deliberately not forced |
| 12 | `/results` | TODO | honest empty state - nothing published |
| 13 | `/finance/dashboard` | TODO | already rich; not compared to `finance dashboard.png` |
| 14 | `/library` vs `Library.png` | DONE | 6 KPIs in one row; category donut + Recent Book Loans |
| 15 | `/inventory` vs `Inventory management.png` | DONE | reference's 5 KPIs; stock-status donut + Recent Stock Movements |
| 16 | `/transport` | PARTIAL | rail already matched; duplicate heading removed. Driver/Vehicle columns queued |
| 17 | `/hostel` | PARTIAL | duplicate heading removed; not compared in detail |
| 17b | `/users` vs `ChatGPT ... 08_06_34` | DONE | 5 KPIs, Username column, role labels, role donut. Fixed a filtered Total Users count |
| 18 | `/guardians` | TODO | |
| 19 | `/staff` | TODO | no dedicated reference; `teacher profile.png` is a profile |
| 20 | `/reports` | TODO | |
| 21 | `/settings` | TODO | |
| 22 | `/admissions/wizard` | TODO | |
| 23 | Print templates (ID, transcript, certificate, statement) | TODO | different medium - paper sizes, not viewport |

### Test state at the end of 2026-08-20

`tests/Feature/Ui` is **128/128**. It was 117/128 at the start of the session.
Of the eleven: two were regressions I introduced (dashboard footer links that
403'd), five were the product having lost its Today's Attendance tile in an
earlier refactor, one was a real branding bug (the sign-in page ignored an
uploaded school logo), and three were tests pinned to states the product had
grown out of. All eleven are closed.

---|---|---|---|
| 1 | Shell (sidebar / top bar / canvas) | DONE | measured; sidebar 270px, canvas ivory, card row at y118 matches reference to 1px |
| 2 | `/dashboard` super admin | DONE | 11 panels, real data, permission-gated; open items in §4 |
| 3 | Triage the 31 `ChatGPT Image *.png` | DONE | see §4b — three conflicting design families; family A is canonical |
| 3b | Card language across ALL screens (`x-kpi-card`) | DONE | tone now paints a 50px solid disc on a WHITE card, not a pastel wash; sentence-case 13px label, 26px numeral. Repaints all 42 call sites from one change. `icon-bg` demoted to a hue hint so no screen keeps an off-palette disc |
| 3c | Shell applied to all 19 back-office screens | DONE | every one captures 200 with the new sidebar, top bar and ivory canvas; contact sheet verified |
| 4 | `/students` vs `student management.png` | DONE | table gained #, Gender, Admission Date; rail rebuilt as a level donut + real quick actions; header wired to admissions.wizard + students.import. Divergences recorded below |
| 5 | `/students/{student}` | TODO | |
| 6 | `/classes` vs `Class Management.png` | DONE | 5 KPIs (classes, students, teachers, average, rooms); table gained Class Teacher + Students; rail is a level donut + classroom utilisation. Rooms reads 0 because none are configured - honest, not a gap |
| 7 | `/subjects` vs `subject management.png` | DONE | 5 KPIs (total, core, elective, unallocated, teachers) + a by-department rail. Core/elective derive from allocations because `subjects` has no such column; "practical" has no counterpart and is not invented |
| 8 | `/academics/settings` | TODO | |
| 9 | `/timetable` | TODO | |
| 10 | `/attendance` | TODO | |
| 11 | `/examinations` vs `ChatGPT ... 08_12_16` | PARTIAL | Upcoming Exams rail done (date block, subject, class, days-left chip). The reference models exam EVENTS with terms and date ranges where this product models exam SITTINGS - the KPI set and table are a different shape and are NOT forced to match |
| 12 | `/results` | TODO | |
| 13 | `/finance/dashboard` | TODO | |
| 14 | `/library` vs `Library.png` | DONE | added Active Members + New Titles KPIs (six now fit one row); rail is a copies-by-category donut + Recent Book Loans with overdue colouring. Only one book category exists in the demo, so the donut is one slice - correct, not broken |
| 15 | `/inventory` vs `Inventory management.png` | DONE | KPI strip is now the reference's five (Total Items, Below Reorder, Stock Value, Out of Stock, Categories); rail is a stock-status donut + Recent Stock Movements with signed quantities |
| 16 | `/transport` | TODO | |
| 17 | `/hostel` | TODO | |
| 17b | `/users` vs `ChatGPT ... 08_06_34` | DONE | 5 KPIs, Username column, role labels instead of raw slugs, role-distribution donut. Fixed a real bug: Total Users showed the FILTERED paginator total |
| 18 | `/guardians` | TODO | |
| 19 | `/staff` | TODO | |
| 20 | `/reports` | TODO | |
| 21 | `/settings` | TODO | |
| 22 | `/admissions/wizard` | TODO | |
| 23 | Print templates (ID, transcript, certificate, statement) | TODO | different medium — paper sizes, not viewport |

---

## 6b. Deliberate divergences from a reference, and why

These are NOT unfinished work. Each is a place where reproducing the picture
would have shipped something false or dead, and the reason is recorded so it
is not "fixed" later by someone reading only the image.

| Screen | Reference shows | Built instead | Why |
|---|---|---|---|
| `/students` | select-all checkbox column | absent | No bulk operation exists on this screen to select FOR. Same rule that keeps unbuilt modules out of the nav. Returns with the first bulk action. |
| `/students` | photo thumbnails | initials avatar | `photo_path` is a private-disk path served through a policy-checked controller, and no student-photo controller exists. There is nothing safe to point an `<img>` at. |
| `/students` | "Export Students" button | absent | No export route. A button that does nothing is worse than an absent one. |
| `/students` | rail: Print Student List, Transfer Students, Export Student Data | absent | No routes. The five that DO exist are wired. |
| `/classes` | select-all checkbox, Export Classes, Section/Level/Status/Year filters, status tabs | absent | Same rules: no bulk action, no export route. The extra filters are real feature work and are queued, not faked. |
| `/users` | KPI "Students" | "Guardians" | This product does not give a pupil a back-office login - their guardian gets one. Labelling a guardian count "Students" on the screen whose whole job is who can sign in would be a plain untruth. |
| `/users` | photo thumbnails, select-all, Department column, Export | absent | No user-photo controller, no bulk action, no department on users, no export route. |
| `/subjects` | KPI "Practical Subjects" | "Unallocated" | `subjects` records code, name, department and is_active - there is no practical/theory attribute anywhere in the schema. Its place is taken by the count of subjects on no timetable, which the reference does not show and which somebody actually has to act on. |
| `/subjects` | rail "Subject Categories" | "Subjects by Department" | Same reason: no category column exists. Department is this schema's real grouping. Currently reads "no subject has been given a department", which is true of the demo data. |
| `/inventory` | KPIs "Movements This Month", "Pending Requisitions" | removed | Both are already tab counts a few pixels below ("Stock Movements 14", "Requisitions 0"). The strip was spending two of seven tiles restating the row under it, and seven tiles wrap. Nothing became unreachable. |
| `/inventory` | table columns Location, Reserved, Available, Unit Price, Total Value | absent | Real work, queued. `stock_balances` carries all five, so this is wiring rather than a data gap. |
| `/library` | "Library Reports" / "Export Data" buttons | absent | Export has no route. Reports exists at `/reports/library` and is reachable from the nav; a duplicate header button is queued, not urgent. |
| `/library` | KPI "New Books (This Term)" | "New Titles (This academic year)" | Books carry an acquisition date, not a term. The label states the window it actually measures instead of borrowing a word the figure cannot support. |
| `/classes` | per-row Room | absent | `rooms` is empty and `class_groups.room_id` is unset on every row; a column of em dashes is noise. |
| `/students` | KPI "New Admissions (This Term)" | absent | Enrolments carry a YEAR, not a term; naming a term needs the assessment-period calendar. Pre-existing decision, kept. |
| `/students` | trend line under every KPI | absent | Needs a persisted daily snapshot table, which does not exist. A trend from the only number we have would be invented. |
| dashboard | Quick Actions: School Calendar, Fee Structures | absent | No routes. Blocks exact 3x3 parity until those screens are built. |
| dashboard | money as `FCFA 45,890,000` | `45,890,000 FCFA` | `Money::format()` also renders every invoice, receipt and statement. Symbol position is a product-wide decision, not a dashboard tweak. **Open question for the user.** |
| all | table body 13px | reference sets it larger | The OPES sidebar is 270px against the reference shell's 168px, so the content area is ~105px narrower for the same table. `/students` ACTIONS hung off the edge until the body was set down. |

---

## 7. Verification before any row is marked DONE

- [ ] Sheet generated and looked at.
- [ ] Build re-measured with `probe.php`; row bands and column tracks within
      2px of the reference, or the difference explained in the ledger note.
- [ ] `php artisan test tests/Feature/Ui tests/Feature/Shell` green.
- [ ] `rm -rf public/__compare`.
- [ ] Committed.
