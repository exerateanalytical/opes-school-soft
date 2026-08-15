# Paper size coverage — 2026-08-15

`App\Modules\Reporting\Domain\PaperSize` defines six sizes. Before this work
three were used by the registered templates.

| Size | Status | Used by |
|---|---|---|
| A4 | In use | Most of the catalogue |
| A5 | In use | Compact receipts / vouchers |
| POS80 | In use | `FEE-RECEIPT-POS`, the 80 mm thermal receipt |
| **CR80** | **Now in use** | `ASSET-LABEL` (Phase 3) — the ID-card blank is exactly what a stick-on asset label is |
| **A3** | **Now in use** | `BROADSHEET` (Phase 6) — `docs/specs/10-documents.md` §6.3 specifies A3 landscape explicitly |
| LETTER | **Retained, deliberately unused** | — |

## Why A3 was wired

`grep -n "A3" docs/specs/10-documents.md` returns six documents specified as
A3 landscape: §6.3 the per-sequence broadsheet (line 285), §7 the seating plan
(346), the admission register (396), the class register (416), an HR staff
list (535), and §9.1's configurable tabular report (662). The broadsheet is the
one whose data exists today, so it is the one registered; the other five adopt
the size without a further migration when their modules reach them.

A3 is not cosmetic here. A francophone secondary class carries 12–16 subjects
plus coefficients; on A4 those columns fall below the width at which a printed
figure can be read.

**Deviation from the plan, recorded:** the plan's migration comment called the
broadsheet unambiguously live. §6.3 actually specifies it as *snapshot-backed
once the period is published, live and watermarked PROVISOIRE before*. It is
registered **live**, because the snapshot half needs an Assessment Action that
assembles a broadsheet payload from a published `ReportCardSnapshot` set and no
such Action exists. Registering it live is the honest half — the working sheet
a class master reprints while marks change, carrying §4.2's "Generated on … by
…" footer that says exactly that. The archival record of a period's marks
remains the report card snapshot (§6.1), which is snapshot-backed and already
registered.

## Why LETTER stays, unused

LETTER appears in `10-documents.md` **only** in the `paper_size` enum
declaration (line 101) and in **no document specification at all** — verified
by grep, not assumed. That is consistent with the product's market: Cameroon is
an ISO-216 (A-series) country, and every statutory document this platform
prints — the ministry header block, the tax attestations, the bulletins — is
specified against A-series sizes.

It is retained rather than removed because:

1. The `document_templates.paper_size` MySQL enum already contains it
   (confirmed against `information_schema`:
   `enum('A4','A5','A3','CR80','LETTER','POS80')`). Dropping a value from a
   MySQL enum is a table rebuild, on a column every document render reads, to
   remove something that costs nothing.
2. It is the escape hatch for a deployment outside the A-series world (a
   partner school, a US-accredited international section). A school that needs
   it changes one column; without the case it would need a migration and a
   deploy.

`tests/Feature/Reporting/PaperSizeCoverageTest.php` pins this: LETTER is the
**only** size permitted to be defined-but-unused. Any other size that becomes
unused fails that test, which forces the question to be answered rather than
accumulated.
