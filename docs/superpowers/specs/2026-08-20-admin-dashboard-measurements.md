# Admin / Super Admin dashboard — measured reference

**Reference:** `frontend images/super admin dashbaord.png`, 1536 × 1024, 1× (so
every number below is already a CSS pixel).
**Instrument:** `tools/design-parity/desktop/probe.php`. Every value was found by
scanning the image — gutters by hue classification against the ivory ground,
discs by connected-region bounds, type by cap height at the stated 0.72 ratio.
No coordinate in this file was read off the image by eye.

## Reproduce any of it

```bash
php tools/design-parity/desktop/probe.php "super admin dashbaord.png" vgaps 545 556 270 1535
```

Commands: `palette`, `row`, `col`, `at`, `cards`, `box`, `vgaps`, `hgaps`,
`lightrows`, `darkrows`.

---

## 1. Page frame

| Region | Measured | Use |
|---|---|---|
| Sidebar, dark green field | x 0..257 | **258px** |
| Toghu gold strip on sidebar's right edge | x 258..269 | **12px** |
| Sidebar block total | | **270px** |
| Content gutter, left | card left edge x 289 | **19px** |
| Content gutter, right | card right edge x 1518 | **18px** |
| Content width | 289..1518 | **1230px** |
| Top bar | y 0..116 | **117px** |
| Row gap between card rows | 14–16px of clear ground | **16px** |

## 2. Row bands (y)

| Row | Panels y | Height |
|---|---|---|
| KPI strip | 117..242 | 126 |
| Quick Actions / Notifications / Upcoming Events | 258..560 | 303 |
| Financial Overview / Top Fee Balances / Student Strength | 570..760 | 191 |
| Recent Activities / System Alerts | 770..972 | 203 |
| Footer | 995.. | — |

## 3. Column tracks

Measured panel left/right edges, per row:

- **KPI (6):** 289..495, 510..715, 727..931, 946..1145, 1155..1360, 1370..1518
  → widths 207, 206, 205, 200, 206, **149**
- **Row 2 (3):** 289..659, 669..1062, 1073..1517 → **371, 394, 445**
- **Row 3 (3):** 289..659, 667..1061, 1070..1518 → **371, 395, 449**
- **Row 4 (2):** 289..840, 852..1518 → **552, 667**

### The one place the reference contradicts itself

Rows 2 and 3 agree to within 4px on a **non-equal** three-column track
(≈371 : 394 : 445), twice. That repetition is evidence of intent, so it is
reproduced as measured.

The KPI row does not agree with itself: six cards at the measured widths plus
the measured gutters sum to ~1286px inside a 1230px content area. Its sixth
card also measures 149px against ~205px for its five identical-looking
siblings. A render cannot be reproduced pixel-for-pixel where its own columns
do not sum to its own width, so the KPI row is **normalised to six equal
columns**; every other track is taken as measured.

## 4. Type scale (cap height ÷ 0.72)

| Element | Cap | Font |
|---|---|---|
| Top bar eyebrow "Welcome back," | 10 | 14 |
| Top bar subline | 12 | 16 |
| Nav label | 11 | 15 |
| Panel title ("Quick Actions") | 12 | 17 |
| KPI label ("Total Students") | 9 | 13 |
| KPI value ("2,458") | 19 | 26 |
| KPI footer link | 8 | 12 |
| Notification row | 11 | 15 |

## 5. Components

| Element | Measured |
|---|---|
| KPI icon disc | **50 × 50** (x 308..357, y 140..189 — bounding box of a filled circle IS its diameter) |
| KPI card padding, left | 289 → 308 = **19px** |
| KPI card padding, top | 117 → 140 = **23px** |
| Nav item pitch | **34px** (18 consecutive labels, 33–35 measured) |
| Nav active pill | y 125..161 = **37px** tall, x 12..246 |

## 6. Colours (sampled, not guessed)

| Role | Value |
|---|---|
| Sidebar field | `#00261C` |
| Page ground (ivory) | `#FBFAF7` |
| Card surface | `#FEFEFE` |
| Nav active pill (gold, gradient centre) | `#F1D28A` |
| KPI disc, green | `#064512` |
| KPI disc, gold | `#D19C14` |
| Alert red | `#EE3A34` |
| Divider | `#E8E9EB` |
| Danger tint (alert badge) | `#F0A091` |

These are **close to but measurably different from** the existing Heritage
tokens in `resources/css/app.css` (`--color-chrome` is `#002D17`,
`--color-heritage-800` is `#064A2B`). Same situation as the guardian-portal
palette already documented in that file: the new values go in as their own
token set so no existing screen is restyled as a side effect.
