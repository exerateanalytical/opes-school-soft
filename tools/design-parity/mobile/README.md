# Design parity harness — Expo guardian app

Renders the **Expo app** at the reference designs' exact device size and builds
a side-by-side sheet against `mobile/*.png`, so "does the app match the design"
is answered by looking at two panels rather than from memory.

Sibling of `tools/design-parity/` (which does the same for the Livewire portal)
and it reuses that harness's `sheet.php` compositor unchanged — two sheet
builders would drift on the one thing that must not drift, which is how the
panels are scaled relative to each other.

## Why it works this way

**Geometry.** The reference PNGs are 852×1846 = a 426×922 CSS viewport at DPR 2.
Shots are taken at 426×922 with `deviceScaleFactor: 1` and doubled when
compositing. `--force-device-scale-factor=2` is *not* used: it does not give a
426px CSS viewport, it lays out at ~392px and upscales the raster, so content
that fits in the live browser reads as clipped in the shot.

**The bundle under test is the bundle that ships.** The app's API base URL is
baked in as `http://10.0.2.2:8000` (the Android emulator's route to the host).
Rather than edit `app.json`, Chrome is told `--host-resolver-rules=MAP 10.0.2.2
127.0.0.1`. Nothing is rebuilt differently for the harness.

**Fixtures, not mocks.** `fixture-api.mjs` serves the *content* of the reference
designs — Emmanuel Ngo, Grade 6B, HBC24567, 850,000 / 510,000 / 340,000 FCFA —
through the app's real client, real envelope decoding, real session flow and
real error taxonomy. A screenshot of a spinner proves nothing about spacing, and
a screenshot of invented data proves nothing about the design.

It answers `501` on payment initiation because the real server does. A harness
that faked a gateway would photograph a fiction.

**Session.** `expo-secure-store` has no web backend, so `src/storage/secure.ts`
falls back to `localStorage` on web (and only on web — see the comment there).
`shoot.mjs` seeds the token into exactly the keys that module reads.

## Running it

```bash
cd mobile/app && npx expo export --platform web        # rebuild after app changes
node tools/design-parity/mobile/serve-dist.mjs mobile/app/dist 8390 &
node tools/design-parity/mobile/fixture-api.mjs 8000 &

export PARITY_SCRATCH=/c/Users/.../scratchpad/parity
node tools/design-parity/mobile/shoot.mjs                 # all mapped screens
node tools/design-parity/mobile/shoot.mjs my-children      # or just one
php tools/design-parity/sheet.php my-children
```

Port 8000 is the fixture API — stop `php artisan serve` first if it is there.

`screens.map.json` maps every reference PNG slug to a route. Scroll variants
(`…-2`, `…-3`) map to the same route with a `scrollTo`; byte-identical
duplicates map to the same route, because photographing an alias twice proves
nothing but leaving it out of the map hides which references are covered.

## What it does and does not tell you

It tells you what the two look like side by side. It does not tell you the built
screen is correct underneath — and the portal harness's README already records
two traps paid for on this codebase: computed styles lie about what you see, and
geometry can be right while the material is wrong.

It also cannot tell you a screen is *missing content* if you only skim: read the
reference panel top to bottom and tick off every card, row, icon and footer.
The first run of this harness found exactly that — see the parity log.
