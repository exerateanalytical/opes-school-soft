# UI Restyle — design brief and execution order

**Source of truth:** `frontend images/` (45 references). The instruction is
explicit: **do not copy these layouts** — extract the *design language* and
raise every screen to that standard of polish.

**Status:** step 1 of 6 done (`a0fe5fc`). This file exists so the rest is
executed systematically instead of screen-by-screen guesswork.

---

## What actually makes the references look "built" (observed, not guessed)

Read from `student management.png`, `admission wizard.png`, `finance dashboard.png`:

1. **Nothing is flat white.** Stat cards carry a low-saturation wash with a
   solid same-hue icon badge. Five white rectangles read as one grey slab.
2. **Generous radii and padding.** ~12px corners, ~14px vertical padding.
   Ours were 4px corners and tight padding — the single biggest "cramped" cue.
3. **Strong type hierarchy.** Page title ~30px bold; stat numerals ~30px bold;
   labels ~11px uppercase tracked, muted. Ours ran ~2 steps smaller throughout,
   which is why everything read as one undifferentiated size.
4. **Dark table headers.** Deep green header row, white text — turns a data
   dump into a designed table.
5. **Pill badges carry state.** Class/status render as tinted pills, not text.
6. **Every page has a right rail** — donut/legend, quick actions. It is what
   fills the width instead of leaving a void.
7. **Breadcrumb + icon + title block**, then right-aligned actions with one
   filled primary and outlined secondaries.
8. **Footer chrome** — user/role/session left, version/status right.

## What I saw wrong in ours (screenshots at 1440×900)

- ⚠️ **"Content occupies ~55% of the viewport" — DISPUTED, re-verify before acting.**
  Read off a screenshot of `/dashboard` and `/finance/invoices`. A later DOM
  measurement at the same 1440×900 viewport **contradicts it**: `main` measured
  1169.8px of 1440 (sidebar ~255) and the KPI row 1118.8px — i.e. the width is
  being used correctly, and `layouts/app.blade.php`'s `<main>` carries no
  `max-w` at all (`min-w-0 flex-1 … px-4 py-6 sm:px-6`).
  One of the two observations is wrong and it was not resolved — the browser
  pane stopped compositing before a confirming screenshot could be taken.
  **Do not start step 2 on the width premise until a working screenshot at
  1440×900 settles it.** If the measurement is right, step 2 is not a width
  fix at all; the perceived emptiness is then density/hierarchy (small type,
  flat surfaces, sparse content), which is steps 3–4 and 6.
- **Accountant `/dashboard` shows no stat cards at all** (all permission-gated
  away) and falls back to "Nothing needs your attention right now." An empty
  dashboard is the "amateur" complaint. Every role needs a populated default.
- Type scale uniformly small; hairline borders; flat white surfaces.
- Tables dense, tiny, light-headed.

---

## Execution order (highest leverage first)

Shared components first — the platform has 42 screens on `x-kpi-card` and many
on `x-list-screen`, so component-level work lifts everything at once. Screen-by-
screen restyling would be the wrong order and 10× the work.

- [x] **1. Stat card** — tints, radius, numeral, sub-label, sparkline. `a0fe5fc`.
- [ ] **2. Page shell / width** — fix the ~55% content width, add the right-rail
      slot to `x-list-screen`, footer chrome. *Biggest visible win.*
- [ ] **3. Type scale + surface tokens** — lift heading/label/body one step;
      replace hairline borders with the reference's softer border+shadow.
- [ ] **4. Table treatment** — dark green header, row rhythm, pill badges,
      action-icon cluster, pagination styling. Applies to every list screen.
- [ ] **5. Forms** — label/asterisk/height/radius pass; wizard step-bar
      component; the requested **edit⇄preview toggle** and **autosave**
      (build on the existing "unfinished work" hold/resume system).
- [ ] **6. Empty states** — every dashboard populated per role; no bare
      "nothing to show".

Then the separately-requested work: **missing routes/detail pages** (audit and
enumerate first — profile setup and school settings named explicitly), and the
**71 unbuilt document types** (see `docs/specs/10-documents.md`; §2.2/§3 are
compliance-binding — no ministry seals, no certifying national credentials).

## Rules for whoever continues

- **Look at every change.** Computed styles and measurements pass while a page
  looks wrong; the login-icon bug was invisible to CSS inspection and obvious in
  a screenshot. Screenshot at 1440×900 **and** 375px.
- Work at the component layer. Touching one screen is almost always the wrong
  altitude here.
- Keep `icon-bg`-style escape hatches so existing callers never break.
- Tailwind v4 scans blade literally — build class strings as literals in
  `match()` arms, never by string concatenation.
