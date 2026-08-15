# Design token decisions — 2026-08-15

Algorithms from the `product-skills:ui-design-system` skill
(`references/token-generation.md`, `references/component-architecture.md`,
`references/responsive-calculations.md`).

## Generated vs. picked

| Layer | Source | Where it lives |
|---|---|---|
| The six brand colours | Picked by the school on `/settings/branding` | settings key `branding.palette` (JSON) |
| The 50→900 ramp per colour | Derived, HSV algorithm | `App\Support\Branding\ColorScale` |
| Shell CSS variables | Derived from the palette | `BrandTokens::toCssVariables()`, emitted unlayered in the layout head |
| Spacing / radius / shadow / z-index / motion | Fixed, not brandable | `@theme` in `resources/css/app.css` |

The skill's `scripts/design_token_generator.py` produced the reference ramp
that `tests/Unit/Support/ColorScaleTest.php` pins the PHP implementation to.
It is an authoring-time tool only: the palette is chosen at runtime, so the
generator cannot be in the request path.

### One deliberate divergence from the generator

The generator re-derives step 500 through the same saturation scaling as every
other step — for `#0B5A32` it emits `#235A3E`, which is visibly not the colour
that was typed in — and parks the original under a separate `DEFAULT` key.
`ColorScale` returns step 500 **exactly as supplied**, so the colour a school
picked is the colour it gets. Every other step matches the generator
byte-for-byte, including its channel **truncation** (`int(c * 255)`, not
rounding).

## The 17px trap

The root font-size is **17px**, not the 16px every published 8pt-grid and
modular-scale table assumes.

- **Spacing tokens are declared in `px`.** In rem against a 17px root an
  "8pt" grid is an 8.5pt grid — 6% off, compounding at the larger steps.
- **No new type tokens were issued.** A `--text-*` scale tuned against the
  17px root already ships and is on every screen; re-declaring it inside the
  same `@theme` block would silently resize the whole product.
  `DesignTokenTest` asserts each `--text-*` name is declared exactly once.
- **Tailwind's spacing names lie**: `w-72` is 306px, `w-56` is 238px.
  Measure; never infer a pixel size from a utility name.

## The Tailwind 4 layering trap

Utilities compile into `@layer utilities`, and **unlayered CSS outranks every
layered rule regardless of specificity.**

| Kind of rule | Where it goes | Why |
|---|---|---|
| Token declarations | `@theme` / unlayered `:root` | The runtime `<style>` override MUST be unlayered to beat utilities that read them |
| Runtime brand override | Unlayered `<style>` in the layout head, after `@vite` | Must beat the compiled defaults |
| Treatment that must beat a utility | Unlayered in `app.css` | e.g. the `.opes-app` form-control treatment |
| Treatment a utility should override | `@layer components` | So `class="rounded-none"` still wins |

A `@layer components` version of a treatment that needs to win ships as a
**silent no-op that measures correctly in devtools**. This has already
happened once in this codebase.

## Icons

The existing inline-SVG set (`x-opes-nav-icon`) is the only staff icon
source. Lucide is not adopted; new glyphs are traced from the lucide glyph of
the same name. Rationale in that component's header comment.

## Component variant matrix (ui-design-system Workflow 2)

Sizes, at the 17px root — heights are px, not utility names:

| Size | Height | Padding X | Font |
|---|---|---|---|
| sm | 32px | 12px | `--text-sm` |
| md | 40px | 16px | `--text-base` |
| lg | 48px | 20px | `--text-lg` |

Variants: `primary` (fill `--color-primary`, white text), `secondary`
(surface `--color-sand`, charcoal text, `--color-border-primary` border),
`ghost` (transparent, charcoal text, hover `--color-sand`). Icon-only
controls get `min-width`/`min-height` of `--tap-target` (44px) regardless of
visual size.
