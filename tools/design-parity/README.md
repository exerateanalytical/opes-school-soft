# Design parity harness

Renders every guardian-portal screen at the reference designs' exact device
size and builds a side-by-side sheet against `mobile/*.png`, so "does it match
the design" is answered by looking at two panels rather than by memory.

## Why it works this way

The reference PNGs are **852×1846**. That is a 426×922 CSS viewport at
`devicePixelRatio` 2, and Chrome at those settings produces **852×1844** — so
the two panels are comparable pixel for pixel, not merely "about the same
shape". Nothing here guesses a scale factor.

The portal is behind auth and headless Chrome driven from the CLI cannot log
in — it has no way to click the demo button or carry a session cookie. So
`capture.php` renders each page **through the real kernel with a real
authenticated principal** and writes the HTML into `public/__compare/`, where
the browser loads it same-origin. Same origin matters: the markup references
`/build/app.css` and the Livewire bundle by absolute path, and a `file://`
page resolves neither. `capture.php` also rewrites `http://localhost/` asset
URLs to same-origin, because `APP_URL` points at port 80 while the dev server
runs elsewhere — without that every page renders as unstyled raw HTML, which
looks like a catastrophic layout failure rather than a 404.

## Running it

```bash
php artisan serve --port=8391
php tools/design-parity/capture.php
bash tools/design-parity/run.sh
```

Sheets land in the scratch directory named at the top of `run.sh`.

## `public/__compare` is not for keeps

It holds **fully rendered signed-in pages**. It is gitignored, and it should
be deleted once a comparison run is finished:

```bash
rm -rf public/__compare
```

## Reading a sheet

Reference left, built right, magenta gutter between them. The gutter is
deliberately garish: a neutral one blends into whichever panel is lighter and
hides exactly the edge you are trying to judge.

## What it does not tell you

It compares what the two look like, not whether the built page is correct
underneath. Two known traps, both already paid for on this codebase:

- **Computed styles lie about what you see.** Icons over a `backdrop-blur`
  surface reported the correct colour while rendering as pale ghosts, because
  the blurred sibling painted over them. Only the pixels showed it.
- **Geometry can be right while the material is wrong.** Panels measured to
  within a few pixels of the design and still read as a different page,
  because the design's surfaces are frosted glass and the build's were opaque.
