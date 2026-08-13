# UI Restyle — design brief and execution order

**Source of truth:** `frontend images/` (45 references). The instruction is
explicit: **do not copy these layouts** — extract the *design language* and
raise every screen to that standard of polish.

**Status:** steps 1, 3, 4, 5(partial) and 6 done, plus the real cause of the
"empty" complaint. This file exists so the rest is executed systematically
instead of screen-by-screen guesswork.

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

- ✅ **RESOLVED — the emptiness was VERTICAL, not horizontal.** `5c6bb8b`.
  The width finding was wrong and the DOM measurement was right: `<main>` has
  no `max-w` and uses its full 1169.8px. What nobody had measured was HEIGHT.
  The sidebar lists up to 50 modules and was `md:static`, so it grew to its
  own content height — **2585px** on an administrator's `/dashboard` — and as
  a sibling of `<main>` in a flex row it dragged the whole *page* to 2585px.
  Real dashboard content: 911px, against a 900px viewport. Every screen in the
  product therefore carried ~1700px of blank canvas below the fold.
  `overflow-y-auto` was already on the nav and had never fired, because
  nothing constrained its height. Fixed with
  `md:sticky md:top-0 md:h-screen md:self-start`; page height 2585px → 1061px.
  **This is what "dashboards empty" meant.** No amount of content restyling
  could have filled it, which is why it kept reading as unfixable.
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
      Plus `958d690`: a `display` slot so a non-numeric KPI (the dashboard's
      SYSTEM HEALTH pill) stops being hand-rolled as a bare white rectangle.
- [x] **2. Page shell** — was never a width bug. See the resolved finding
      above; fixed as a sidebar height bug in `5c6bb8b`.
- [x] **3. Type scale** — `3ba57f8`. One page-title size platform-wide
      (~30px bold). There were two: 57 h1s at ~22px and 27 at ~25px, and
      against a 22px title the 32px KPI numerals inverted the hierarchy.
- [x] **4. Table treatment** — `a130fd4`. Dark header is now the component
      default, 12px container radius, row hover, non-wrapping headers, and the
      rail cut 306px → 238px to stop it starving the table.
- [x] **5. Forms** — `36350cf`. 916 controls across 98 files, no shared
      component: 10px radius, 40px min height, green focus ring, custom
      chevron, all from one unlayered block in `app.css`.
      **Still outstanding from this step:** the wizard step-bar component, the
      requested **edit⇄preview toggle** and **autosave** (build on the existing
      "unfinished work" hold/resume system).
- [x] **6. Empty states** — `02fe332`. Medallion + optional title + capped
      message + action. **Still outstanding:** populating each role's dashboard
      with real default content, which is per-screen work, not component work.

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
- **When a treatment must beat per-screen utilities, write it UNLAYERED in
  `app.css`.** Tailwind v4 compiles utilities into `@layer utilities`, and
  unlayered CSS outranks every layered rule regardless of specificity. A
  `@layer components` version loses to the very `rounded`/`px-2`/`py-1.5` it
  exists to correct and ships as a no-op that measures fine. Scope it to
  `.opes-app` (staff layout only — auth and the guardian portal have their own
  approved designs) and put every exclusion inside `:where()` so the rules
  weigh equally and **source order** decides. Bare `:not()` chains gave the
  base field rule (0,13,1) against the chevron rule's (0,5,1), so
  `padding-inline` beat `padding-inline-end` and the chevron painted on top of
  the selected value — visible only in a screenshot.
- **The root font-size is 17px**, so Tailwind's spacing names lie: `w-72` is
  306px, not 288; `w-56` is 238px, not 224. Measure, don't read the name.
- **The sidebar "liquid glass" rollout does not transfer as-is.** The login
  screen's recipe works because a photograph sits behind it. The platform
  sidebar sits *beside* content on a flat `bg-sand` canvas, so `backdrop-blur`
  there has nothing to blur: it would degrade to a flat tint while still
  creating the stacking context that produced the pale-ghost icon bug. If this
  is picked up, do it with low-alpha fills, specular top edges and gold
  hairlines — the *look* — and no blur, or give the sidebar a real backdrop
  first. Not attempted here.
