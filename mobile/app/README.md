# Guardian Mobile App (Expo) — Slice G

The parent-facing app for the OPES School platform. Consumes the guardian
surface documented in `docs/specs/2026-08-11-guardian-mobile-api-v1.md` and
`docs/api/openapi.yaml` — Slices A–F, which are complete and tested.

## State of this build — read this first

**All 81 reference screens are built, the project type-checks clean, and it
bundles.** What follows is exact, including what is still missing.

### Verified

```
npm install        773 packages, clean
npx tsc --noEmit   0 errors
npx expo export    1191 modules, 3.02 MB Hermes bundle, android OK
```

That is a real check, not a claim: the bundler resolves every import and
compiles every route, so there are no missing modules, no broken paths and no
syntax errors anywhere in the tree. It is **not** a rendering test — see
"Fidelity" below.

### Complete and reviewable

| Area | Files |
|---|---|
| Design tokens (the locked palette/radii/type/shadow from the 81 PNGs) | `src/theme/` |
| API client — envelope decoding, error taxonomy, read cache | `src/api/client.ts` |
| Typed wire shapes for every documented operation | `src/api/types.ts`, `src/api/endpoints.ts` |
| Token storage in the OS keystore, read cache, offline write outbox | `src/storage/` |
| Session/child selection + the capability rendering contract | `src/state/session.tsx` |
| Bilingual en/fr with money and date formatting | `src/i18n/` |
| Component kit (cards, chips, tiles, rings, rows, forms, states) | `src/components/primitives.tsx` |
| App chrome (green header + gold curve, child strip, tab strip, both bottom navs) | `src/components/chrome.tsx` |
| Loading / denied / not-found / offline handling, written once | `src/components/useScreenData.tsx` |
| Routing + `opes://` deep-link mapping | `src/navigation.ts`, `app/` |

### Screens — 81 files, one per reference PNG

`src/screens/` holds one component per PNG, same filename → same component
name, plus `ClassTimetable` (see below). Verify the mapping with:

```bash
for f in mobile/*.png; do b=$(basename "$f" .png); \
  n=$(echo "$b" | awk -F- '{for(i=1;i<=NF;i++)printf toupper(substr($i,1,1)) substr($i,2)}'); \
  [ -f "mobile/app/src/screens/$n.tsx" ] || echo "MISSING $n"; done
```

Three kinds of file, and the distinction is deliberate:

- **Implementations** (~50). The real screens.
- **Duplicate aliases** (15). The 81 PNGs contain 11 byte-identical groups
  (`md5sum mobile/*.png | sort | uniq -w32 -d`). Those are one screen exported
  twice by the design tool, so they re-export rather than fork — two copies of
  a screen drift apart.
- **Variant aliases** (10). `child-overview-2`, `health-overview-2`,
  `global-search-2` and friends are the *same* screen in a different state
  (scrolled, keyboard up, results populated). Also re-exports, for the same
  reason.

`ClassTimetable` is the one screen with **no** reference PNG. It exists because
`ChildOverview` offers a timetable tile (row 26 is granted on almost every
link) and the endpoint shipped in Slice C — a tile navigating nowhere would be
a worse answer to the missing design than a plain screen built from the kit.

### What the screens honestly cannot do

Four screens are wired to the truth rather than to a plausible fiction, and
each says so on screen:

| Screen | Why |
|---|---|
| `MakePayment`, `PaymentProcessing`, `PaymentMethodSelection` | No gateway exists. They call the real endpoint, get the real `501`, and show the school's real message. A mock would teach parents the app can take their money. |
| `Assignments` | Homework has no guardian endpoint and no matrix row. Shows the timetable, states the gap. |
| `SchoolActivities`, `ExcursionsTrips`, `SportsEvents`, `ActivityDetails` | Activities are a P0 non-goal. Backed by announcements (row 26). Notably **no permission-slip button** — consent is a legal record and there is no write endpoint for one. |
| `BulletinDePaiePayslip` | A payslip is a *staff* record. No matrix row could ever grant a parent one; the PNG is a stray from the staff design set. Rendered as an explicit refusal so nobody wires it to payroll later. |

### Fidelity is faithful, not proven

The tokens were read off the reference PNGs by eye. The project bundles, but
**no screen has ever been rendered and nothing has been diffed against the
PNGs** — that needs a simulator and a screenshot harness this environment does
not have. The previous session's handover raised exactly this and offered two
options; this build took option (a) (build to the token system faithfully)
because the user asked for the screens. So: "pixel-perfect" is *not* a claim
this build can substantiate, and nobody should repeat it upstream until option
(b) exists.

Bundling proves the code is *valid*. It says nothing about whether a card is
16px from the edge or 12.

Two specific places the PNGs imply something the tokens do not cover:

- the **crest** is rendered as a bordered box with an `H`, not the real
  laurel-and-crown artwork — there is no asset for it in `mobile/`;
- the **decorative background illustrations** on the auth screens (the campus,
  the faint subject glyphs) are absent for the same reason.

## Running it

```bash
cd mobile/app && npm install && npx expo start
```

Point the app at your API in `app.json` → `expo.extra.apiBaseUrl`. The default
`http://10.0.2.2:8000/api/v1` is the Android emulator's route to the host's
`php artisan serve`; use your LAN IP for a physical device.

## The rules this app follows

1. **The server decides; the app renders.** `capabilities` on a child is a
   rendering contract — it hides a tile — and never a permission. Every screen's
   data comes from an endpoint that re-checks, so a stale capability list costs
   a wasted request, never a leak.
2. **A 403 is an answer, not an error.** "Your school has not shared this with
   you" is a true statement about how this guardian's link is configured.
   Rendering it as a crash teaches parents to phone the office about a working
   system. `useScreenData` enforces the distinction in one place.
3. **Absent, not zero.** The dashboard omits a tile whose capability is missing.
   A zero would tell a parent their balance is nothing when the truth is that
   fees are not shared with them.
4. **Money is minor units + currency, never a float** — and XAF has no
   centimes, so `formatMoney` does not divide it by 100.
5. **Idempotency keys are stamped when a write is QUEUED, not when it is sent.**
   That is the whole reason the outbox is safe: a message that reached the
   server but whose response was lost does not double-post on retry.
6. **Nothing medical and no document bytes are cached to disk.** The server
   sends `Cache-Control: private, no-store` on those; writing them to
   AsyncStorage anyway would make the header theatre.
7. **The sign-out clears the cache.** A shared family phone is the normal case.
8. **One error message on sign-in.** The server answers every credential
   failure identically so the screen cannot be used to discover whether a
   parent has an account here; the client must not undo that by guessing a
   friendlier message.
