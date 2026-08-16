# Design — a dedicated Accountant dashboard

**Date:** 2026-08-16
**Status:** approved by user, pending implementation plan
**Precedes:** an implementation plan via the writing-plans skill

---

## 1. Why this exists

The Accountant currently shares one generic four-block template with 17 other
roles (`docs/specs/2026-08-16-dashboard-inventory.md`): a KPI strip of bare
integers, a quick-actions grid, a fixed "what's open" panel, and a flat
alerts list. It is capped at **6 panels, enforced in code and under test**
(`Operations\Livewire\Dashboard::rolePanels()` breaks the loop at 6) — there
is no room to make that screen richer without either removing another
role's ability to gain a panel there or fighting the cap itself.

Separately, `/finance/dashboard` already proves a richer pattern works: real
KPI deltas, an inline-SVG trend chart, a donut, bar charts — all hand-rolled
Blade/SVG, no JS charting library anywhere in the project. It reads as
Bursar-facing (collections, cash desk).

**Decision (user, confirmed):** the Accountant gets its **own dedicated
screen**, separate from `/finance/dashboard`, because the two jobs differ —
the Bursar collects money, the Accountant keeps the books correct. The new
screen follows the same hand-rolled SVG/Blade visual language as
`FinanceDashboard` (colours from CSS custom properties, no new dependency)
but with Accountant-specific content.

**Decision (user, confirmed):** when the Accountant opens it, it should give
"a bit of all three" — can I trust the books, what do I need to do today,
how's the money situation — not lead with a single headline number.

**Decision (user, confirmed):** the page is three stacked sections, read top
to bottom like a front page: reassuring/urgent first, detail as you scroll.
This also matches how `FinanceDashboard` is already structured, so the two
finance screens will feel like siblings.

---

## 2. Route and access

- **Route:** `/accounting/dashboard`, named `accounting.dashboard`.
- **Permission gate:** `ledger.view` — the same permission `/ledger/trial-balance`
  already requires. This is permission-based, not role-based, consistent
  with how every other screen in this module is gated; it does not
  special-case the Accountant role by name.
- **The generic `/dashboard` Accountant panel strip is otherwise untouched**
  — still capped at 6, still shows its current 5 panels, no panel content
  changes. The only edit to that screen is one new quick-action tile,
  **"Accounting dashboard,"** pointing at the new route.
  **Implementation must first check how the Bursar currently reaches
  `/finance/dashboard`** from their own generic dashboard (quick action?
  panel link? nav item?) and mirror that exact mechanism, so the two finance
  screens are reached the same way. Do not invent a second pattern.

---

## 3. The three sections

### 3.1 Book health (top strip, 3 tiles)

A quick-glance strip, meant to read as reassuring almost all the time and
only demand attention when something is actually wrong.

| Tile | Shows | Data source | Normal state | Alarm state |
|---|---|---|---|---|
| **Books balanced?** | Whether every journal entry's lines sum to its header total and debit=credit | `Actions\VerifyLedgerIntegrity` (invariant L2) | Green check, "Balanced" | Red, count of entries out of balance. This is enforced by DB constraints/triggers, so expect this to read healthy essentially always — it is a backstop, not a moving metric. |
| **Unposted entries** | Count of journal entries still in draft | `Actions\Review\JournalExceptions` (`status = 'draft'` count) | "All caught up" when 0 (true today — 0 draft rows exist) | The count, linking to `/ledger/journal-entries` filtered to drafts |
| **Uncategorized balances** | Money sitting in a suspense/holding account, not yet properly filed | `Actions\Review\SuspenseBalances` | Green, "None" | The amount, linking to wherever `ControlCentre` already links its own suspense-balance finding |

**Pattern to follow:** the tile/alarm styling already used in
`Livewire\Review\ControlCentre.php` — do not invent a new status-tile visual
language.

**Responsive:** 3 tiles across on desktop. On mobile, 2 columns
(`grid-cols-2`), never an implicit `auto` track — see §6.

### 3.2 Needs your attention today (task list)

A short list of actual to-dos, each line linking straight to where it's
fixed — not another count, this is the list behind the count.

Rows, in this order when present:
1. **Any fiscal year whose status is `closing`** — "Fiscal year {code} is
   being closed," linking to `/accounting/year-end`. Real and true today:
   FY2026 is `closing`.
2. **Each draft journal entry**, one row per entry, linking to
   `/ledger/journal-entries/{id}`. None exist today, so this contributes no
   rows right now.

**Honest scope note:** in the current demo data this section renders with
exactly one row (the FY2026 line). That is correct behaviour — the list
shows what is real rather than padding itself — not a bug to chase during
implementation or verification.

**Pattern to follow:** the "Needs attention" list already used on the
generic `Operations\Livewire\Dashboard` (alert-card-per-row, empty state
when nothing qualifies).

**Quick action added because of this section:** a fifth quick-action button,
**"Continue closing FY2026"** (or whichever fiscal year is currently
`closing`; omitted entirely when none is), linking to `/accounting/year-end`.
This is additive to the existing four (`new_journal_entry`, `trial_balance`,
`tax_dashboard`, `reports`), which are unchanged.

### 3.3 Money (richest section, real data behind it)

1. **Aged receivables, bucketed** — current / 1–30 / 31–60 / 61–90 / 91–180 /
   180+ days overdue, as a bar breakdown. Real total today: **458,000 FCFA**
   overdue across 62 issued invoices.
   **Data source:** `App\Modules\Fees\Actions\AgedBalances` — bucketed,
   per-student, signed. **Not**
   `Operations\Actions\ReadDashboardPanels::agedReceivables()`, which only
   returns one aggregate figure with no buckets and is the wrong source for
   this card.
2. **Top debtors** — a ranked list of the largest individual amounts owed
   (student/family name, amount, days overdue), same `AgedBalances` source,
   sorted descending, capped at a small N (suggest 5–8; implementation may
   tune based on how it reads in the browser).
3. **Collection trend** — a line/area chart of money collected over recent
   months. **Honest caveat, stated on the page if the series is short:**
   only ~3 months of real payment history exist in the current data
   (Jul/Sep/Oct 2026), so this chart will look thin, not a full year. That
   is the demo data's limit, not a design defect.
   **Pattern to follow:** `FinanceDashboard`'s existing monthly-trend SVG
   block (`<path>` area + line, `<circle>` points with `<title>` tooltips,
   axis `<line>`s, colours via CSS custom properties) — reused, not
   reinvented.

**Targeted refactor, in scope because both screens need the identical
computation:** if `FinanceDashboard`'s monthly-trend calculation is inline
in `Livewire\FinanceDashboard.php` rather than its own Action, extract it
into a shared Action both dashboards call. This is the one refactor this
design requires — it exists only because two screens now need the same
number computed the same way, not as general cleanup. If it is already
extracted, this step is a no-op.

### 3.4 Quick actions (bottom, unchanged + 1)

`new_journal_entry`, `trial_balance`, `tax_dashboard`, `reports` stay
exactly as they are today. `continue_closing_fiscal_year` is added per §3.2,
shown only when a fiscal year is currently `closing`.

---

## 4. Explicitly out of scope

- **The `cash_position` bug on the generic dashboard.**
  `ReadDashboardPanels::cashPosition()` filters `status = 'posted'` only,
  which the module's own docblocks elsewhere warn silently flips sign on
  reversed entries — the rest of Accounting uses `JournalEntry::postedLedger()`
  (posted + reversed). Recomputed correctly the figure is 5,794,000 FCFA,
  not the 222,000 FCFA currently shown. This new screen does not show a raw
  cash/treasury figure, so the bug does not enter its scope — but it is a
  real, separate defect worth its own fix. Flagged to the user; not
  actioned here.
- **`budgets`/`expenses`-based cards** (expense-vs-budget). Both tables are
  0 rows in the current data; a card here would be 100% empty-state with
  nothing to demonstrate. Not included in this round. `Actions\BudgetVsActual`
  already exists correctly if this is picked up later.
- **Tax-declaration cards.** `tax_declarations` is 0 rows. Same reasoning.
- Any change to `PostFromEvent` or posting/ledger-writing logic. This design
  only reads.
- Any change to `/finance/dashboard` beyond the one extraction in §3.3, if
  needed.

---

## 5. Error handling / empty states

Every section already has a stated empty/sparse state above (§3.1–3.3) —
restated once here as a principle: **the codebase's own convention is a
deliberate empty state, never a lying zero or a hidden section.** A tile
reading "0" without explanation is not acceptable per the existing
`x-kpi-card` convention (`opes.ui.no_data`); every count on this page must
read as a sentence a human would say ("All caught up," "None," a real
number), matching the pattern already used on the generic dashboard's
`last_backup` tile.

---

## 6. Responsive layout

The page itself is naturally single-column on mobile (it is already a
vertical stack of sections). The one place a grid appears is §3.1's 3-tile
health strip: it must use an explicit `grid-cols-2` (or similar
`minmax(0,1fr)`-based) track below `sm`, never an implicit `auto` track.
This is not a stylistic preference — it is the exact bug fixed in `4e77f64`
on the generic dashboard (a long line of text sized an implicit track wider
than its container, clipping every card on every phone screen). Any new
grid on this page must be built with that fix's reasoning applied from the
start, not discovered again by testing.

---

## 7. Testing / verification

- **Feature test**, modelled on the existing `DemoRoleLoginTest` pattern:
  the route returns 200 for a principal holding `ledger.view` and is denied
  otherwise; each section's expected content is asserted against the known
  demo-data facts recorded in this doc (e.g. the FY2026-closing row is
  present, the 458,000 FCFA aged figure appears, "All caught up" appears
  for unposted entries).
- **Browser verification**, both roles, both breakpoints — sign in via the
  demo panel as Accountant (and re-verify Bursar's `/finance/dashboard` is
  unchanged if the §3.3 extraction touches its component). Desktop
  (1440×900) and mobile: **a true 375px viewport, not
  `--window-size=375` in headless Chrome**, which clamps to a ~504px
  minimum window on this machine and silently renders a wider layout
  cropped to 375 — this was already discovered and worked around this
  session (see the iframe technique used for Task 44's mobile verification).
- **Regression:** run `tests/Feature` filtered to `Accounting|Dashboard|Portal`
  before considering this done, per the standing project note that the
  guardian-portal session's territory must not be touched and that test-DB
  contention with any concurrent session must be checked first.

---

## 8. What this design does not answer yet (for the implementation plan)

- Exact top-N for the "top debtors" list (5 vs 8 vs 10) — tune by eye in
  the browser against real data, not decided in the abstract here.
- Whether `FinanceDashboard`'s trend calculation is already an extracted
  Action or inline — verify at implementation time; §3.3 states the
  decision either way.
- The precise mechanism the Bursar dashboard uses to reach
  `/finance/dashboard` from `/dashboard` — verify and mirror, per §2.
- That `/accounting/dashboard` / `accounting.dashboard` doesn't collide with
  an existing route or name — this doc proposes it, implementation should
  confirm against `routes/web.php` before wiring it in.
