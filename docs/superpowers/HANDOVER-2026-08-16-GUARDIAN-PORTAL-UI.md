# Handover — Guardian portal mobile UI parity

**Date:** 2026-08-16
**Branch:** `main`, clean, pushed to `origin` at `a5d586c`
**Scope:** bringing the guardian portal's mobile screens to pixel parity with
the 84 reference designs in `mobile/*.png`

---

## The state in one paragraph

The measuring instrument is built and committed; the shell and the dashboard
are done to measured sizes; **the other 29 mapped screens are not started.**
Parity has NOT been achieved and nobody should report that it has. What exists
is a harness that makes the remaining work verifiable instead of arguable, a
type scale and shell that every screen inherits, and one screen — the
dashboard — carried far enough to establish the card, tile and icon vocabulary
the rest will reuse.

---

## The rule this work is held to

**Measure every value out of the reference and reproduce it exactly.** The user
rejected two rounds of "looks about right" in the words *"Images look alike not
the same because of the font size, image or icon size. I want everything exact,
measure and reproduce."*

Recorded in `docs/specs/09-ui.md` §10b and in the assistant memory
`measure-dont-estimate`.

**No section may be omitted.** A screen is not done when it has the right
sections in the right order — it is done when every row inside each section is
present, including the small ones: the relative timestamp under a message, the
location line under an activity, the "Add to Calendar" footer.

**Icons.** If a glyph is missing, build it to match the reference exactly and
draw it in the Lucide idiom so it drops into `x-portal.icon`'s map beside the
rest: 24×24 viewBox, `fill="none"`, `stroke="currentColor"`,
`stroke-width="1.8"`, round caps and joins, no baked-in colour.

---

## The harness — `tools/design-parity/`

```bash
php artisan serve --port=8391
php tools/design-parity/capture.php     # renders authed pages into public/__compare
bash tools/design-parity/run.sh         # screenshots + builds side-by-side sheets
rm -rf public/__compare                 # ALWAYS: these are signed-in pages
```

| File | Does |
|---|---|
| `capture.php` | Renders each portal screen through the real kernel as a real authenticated guardian, writes HTML to `public/__compare/` |
| `run.sh` | Screenshots each at 426×922, builds the sheets |
| `sheet.php` | Composites reference-left / built-right at the same pixel size |
| `locate.php` | **Finds** an element by scanning for its colour, returns its exact bounding box |
| `measure.php` | Cap height → implied font size |

Sheets land in the scratch dir named at the top of `run.sh`.

### Four traps, each of which already cost a cycle

1. **`--force-device-scale-factor=2` does not give Chrome the CSS viewport you
   ask for.** It laid a 426px design out at ~392px and scaled the raster, so
   content that fits in the live browser reads as clipped in the shot. Capture
   at 1× and upscale when compositing. The live DOM said 426 and the
   screenshot behaved like 392 — the screenshot was the liar.
2. **Never seed a measurement with coordinates read off the image by eye.** The
   first attempt did, missed every icon disc, flood-filled the page background
   and reported a "426×825 icon". Search for the element; measure what is found.
3. **Clipping inside an `overflow-x` carousel looks exactly like a page-level
   overflow bug in a screenshot.** `documentElement.scrollWidth` is the
   arbiter. It said 426 — equal to the viewport — the entire time I was
   "fixing" an overflow that did not exist.
4. **Asset URLs.** `capture.php` rewrites `http://localhost/` to same-origin
   because `APP_URL` points at port 80 while the dev server runs elsewhere.
   Without it every page renders as unstyled raw HTML, which looks like a
   catastrophic layout failure rather than a 404.

---

## Done

### Shell — every screen inherits it (`ea4fe36`)

- **Portal type scale.** The platform's 17px root and enlarged `--text-*` are a
  deliberate back-office choice and must not change. The portal now carries
  `portal-root` on its **own `<html>`** element (`layouts/portal.blade.php`),
  dropping to 16px and shadowing the tokens to Tailwind's stock scale. It has
  to be on the root element — `rem` resolves against `:root`, so the same class
  on `<body>` does nothing. No staff screen moves.
- **Header** shows the SCHOOL, not the product. `school.name` was already
  "Heritage Bilingual College" while the header rendered "OPES" — a straight
  bug. Wordmark splits lead-word-large over remainder-small (on one line it
  truncated to "HERITAGE BILINGUAL C…"). Strapline reads `school.strapline`
  with a fallback rather than hardcoding "Learn. Grow. Excel."
- **Search left the header** — never in the designs, and it crowded the only
  row a phone header has. It moved to the account hub, which is the bar's
  "More" destination. It had to go *somewhere*: `PortalRouteWiringTest` fails
  any portal route nothing links to.
- **Bottom bar** is the designs' five in their order — Dashboard, Children,
  Academics, Payments, More — with the gold rule under the active item. It
  stays **dark green**: sampling the nav band across all 76 phone references
  gives **69 green to 5 white**; the dashboard reference is the outlier.
  "Academics" is child-scoped, so it resolves to the first linked child's
  results and falls back to the children index.

### Dashboard — the exemplar (`a5d586c`)

Measured from `parent-dashboard.png` (426×923 CSS):

| Element | Reference | Built |
|---|---|---|
| Overview icon disc | 25px, four at y 319.5 | **25×25** at x 147 / 245 / 342 |
| Overview card pitch | 97.6px (~93 card + ~4.5 gap) | matched |
| Unread bell disc | 27px | **27×27** |
| Page gutter | 20px (content 386px) | matched |
| "Active" chip | 14px tall | matched |
| Safety banner | 384.5 × 61.5 at (21, 791) | present |

Sizes exact, x within 4px. Layout is now four compact tiles across rather than
two large cards per row; the two panels sit side by side at phone width as the
design has them. **Page height 2 278 → 1 037.**

---

## Not done — pick up here

1. **Header height.** Every y on the dashboard sits **~56px lower** than the
   reference. The mockups include a phone status bar a browser does not have;
   the reference header band is CSS 0–117 *including* ~50px of status bar, so
   the web header should be ~67px plus the wave. This is arithmetic, not a
   mystery, and it is the single highest-leverage fix left — it moves every
   screen and would take the dashboard from 1 037 to close to the design's 923.
2. **Assets.** Extract the child photographs and the Heritage crest from the
   references, backgrounds removed. The child cards currently fall back to
   initials. Note: `students.photo_path` IS seeded and the photo route returns
   200 — the initials in the *sheets* are real, because the design's own
   photographs have not been extracted yet.
3. **The other 29 mapped screens**, in reference order, each verified by its
   own sheet. `capture.php` has the reference→route map; extend it as screens
   gain routes.
4. **31 references have no mapped route.** Listed by `capture.php` on every
   run. Several plainly do have routes and simply are not mapped yet
   (`school-announcements`, `security`, `splash-screen`); others may be screens
   that were never built. Those are different findings and must not be blurred.

---

## Watch out for

- **`account.index` (`/account`) returns 200 to a guardian.** Investigated, not
  a leak: `routes/web.php` states in its own comment that the screen carries no
  `can:` by design, because every authenticated user may set their own
  password, and the component reads `auth()->user()` and nothing else. It is
  allow-listed in `GuardianDenyByDefaultRouteEnumerationTest` with that
  reasoning. It **does** render the staff shell to a parent, which is a wart
  worth fixing separately. I could not establish why it changed status during
  this session and did not invent a cause.
- **`public/__compare` must never be committed.** It holds fully rendered
  signed-in pages. Gitignored; delete after each run.
- **104 MB of untracked files** sit in `mobile/` — 82 design PNGs plus
  `cameroon_private_mission_schools_contacts.csv`,
  `cameroon_school_leads_v2.xlsx` and one more CSV. Deliberately **not**
  committed: 104 MB of binaries is effectively permanent in git history, and
  the CSV/XLSX are school **contact lists**, so committing them publishes
  personal data. This needs the user's decision — LFS, plain commit,
  `.gitignore`, or move out of the repo.
- **Two agent worktrees** under `.claude/worktrees/` hold another session's
  uncommitted edits. Left untouched.

---

## Verification status

- `PortalRouteWiringTest` + `LoginTest`: **18 passed**
- Guardian + portal suites after the shell change: **268 passed, 4 skipped**
- The dashboard rebuild has not had the full guardian suite run against it;
  only the wiring and login guards. **Run
  `php artisan test --testsuite=Feature --filter="Portal|Guardian"` before
  building on it.**
