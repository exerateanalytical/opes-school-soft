# Handover — session end, 2026-08-15

Written for a **fresh session on a different account**. Assumes no memory of the
work. Read this file and `HANDOVER-2026-08-15.md` (batch detail) before touching
anything.

**Everything is committed and pushed. Nothing is in flight. `main` is at `182b863`.**

---

## 1. State in one screen

| | |
|---|---|
| Branch | `main`, working tree clean (untracked `mobile/*.png` are pre-existing design assets, never committed) |
| Remotes | `origin` (`exerateanalytical/opes-school-soft`) and `fork` (`jiencestonmorningstar/opes-school-soft`) — **both at `182b863`, 0 unpushed** |
| Migrations | applied; `artisan migrate` reports nothing pending |
| Seeder | `RolePermissionSeeder` re-run after the last permission change |
| Tests | `tests/Architecture tests/Unit` → **312 passed / 0 failed**, 4,094 assertions |
| Plan progress | **43 of 45 tasks** in `docs/superpowers/plans/2026-08-15-document-identity-and-assets.md` |
| Commits this session | 44 |

---

## 2. What is NOT done — start here

### Task 44 — screenshots of the role dashboards (NOT STARTED)
### Task 45 — final verification (NOT STARTED)

**The single most important caveat in this handover: no dashboard has ever been
seen rendered.** Tasks 40–43 built the whole role-dashboard system and it is
green under test, but the browser pane was never opened on it. Five batches in a
row have now shipped on DOM assertions and computed styles alone. Given the user's
repeated complaint that the UI "looks like it was made by an amateur," *looking at
it* is the highest-value next action, not more building.

When you do: **`resize_window` to 1440x900 IMMEDIATELY before every screenshot,
never after a navigate or reload.** The pane silently loses its emulated viewport
on reload and renders tiny — I once reported a phantom "55% viewport width" layout
bug that came entirely from this, and DOM measurement contradicted it.

Also still open from earlier phases: the edit⇄preview toggle, form autosave, the
wizard step-bar, QR document signing, and 54 of the 70 document types.

---

## 3. Traps that cost real time — do not rediscover these

**The plan's table and column names are unreliable.** Task 33's mandatory schema
check found that **four of eight assumed tables do not exist**. Verify every name
against `information_schema.columns` before writing a query. Confirmed realities:

| Plan says | Actually |
|---|---|
| `assessment_marks` | `marks` |
| `fee_invoices.total_amount` / `paid_amount` | `invoices` — **no amount columns at all**. Total = `SUM(invoice_lines.amount + tax_amount)`; settled = `SUM(payment_allocations.amount) WHERE reversed_at IS NULL`. Statuses are only `draft`/`issued`/`cancelled` |
| `fee_payments` | `payments` + `payment_allocations` |
| `examinations`, `examination_entries` | **neither exists** — only `exams` and `exam_seatings`, no result column anywhere |
| `locations` | does not exist |
| `discipline_cases.reference/category/severity/summary` | none exist; severity is on `discipline_categories.severity`, text is `description` |
| `student_activity_logs.description/created_at` | `summary` / `occurred_at` |
| `student_documents.mime_type` | `mime`; `file_hash` is NOT NULL |

Do not use `artisan db:show --counts` — it hung >5 min and resolved a *different*
database than `.env` names. Query `information_schema` directly.

**Other traps:**
- PHP is at `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` and is **not on PATH**.
- **Give every concurrent agent its own test database.** A 39-test run died with
  28 "table doesn't exist" errors when another session's `migrate:fresh` dropped
  tables underneath it. This was my orchestration error. Suspect it before
  suspecting the code.
- **Never run two suites at once.** Check for orphaned php processes first.
- Module Livewire components must be registered by name in `AppServiceProvider`
  or they 500 with `ComponentNotFoundException`.
- New permissions do not exist until `RolePermissionSeeder` runs.
- A `@php` inside a Blade `{{-- --}}` comment takes **every page** down with a
  ParseError — Blade compiles directives before stripping comments.
- Semantic colours are split: the plain role is a **fill**, the `-text` variant
  is for **text**. Never `text-warning` on `bg-warning-bg` (2.25:1).
- Cross-module reads go through `DB::table` only; importing another module's
  Model fails `tests/Architecture/ModuleBoundaryTest.php`.
- Running architecture+unit+Reporting in one process OOMs dompdf at 512 MB.

**Pushing to `origin` fails intermittently with a 403** and succeeds on retry with
no change in between — Windows Credential Manager appears to alternate credentials.
`GIT_TERMINAL_PROMPT=0` fixes only the *hang*, not the 403. Just retry; `fork` has
never once refused.

---

## 4. Known-failing, and NOT caused by this work

`tests/Feature/Reporting` has 16 failures + 12 errors because `openssl_pkey_new`
cannot generate a P-256 keypair on this PHP build (returns false, "No such
process"). **The QR-signing tests cannot pass on this machine regardless of the
code.** Verified directly, and `git diff` confirms the failing files were never
touched. Two older unrelated failures: `DashboardTest` expecting a removed
`opes:backup:run`, and `ShellTest` expecting "Soon".

**Stale data, not a defect:** the dev database still holds a pre-2026-08-15
palette with red `#D64545`, so that install keeps raising a contrast warning on
the branding screen until the settings row is re-seeded. Shipped defaults are clean.

---

## 5. What was actually built (43 tasks, 6 batches)

- **Settings & branding** — categorised `/settings` hub, shared settings-form
  pattern with sticky save bar and unsaved-changes guard, rebuilt Branding screen
  with synced picker+hex and live preview, seven curated presets.
- **Design tokens** — 50→900 colour ramp, spacing/type/radius/elevation/z-index/
  motion layer, icon contract, WCAG AA enforced by test.
- **Images** — content-hashed storage, data-URI embedding, uploads on both
  settings screens. Documents render the crest, logo, both signatures and the
  stamp; verified no `src="/storage/` or `src="http` survives (dompdf has remote
  fetching disabled, so a URL would render a blank box).
- **Watermarks & labels** — school watermark (text + image + opacity) drawing
  alongside DUPLICATA; asset Code 39 labels, CR80 and A4 stock-take sheet.
- **Documents** — preview that provably burns no serial, 8 front-desk documents
  (catalogue 8→16 of 70), A3 broadsheet.
- **Students** — six formerly-inert profile tabs implemented; a seventh
  (Examinations) **removed** because no exam-result data exists in the schema.
- **RBAC** — all 20 roles on the demo login (was 8), SuperAdmin god-mode via
  `Gate::before`, per-role dashboards.
- **Three new modules** built and merged: Activities, Curriculum, Alumni.

### Reproducibility properties worth not breaking
These are the load-bearing invariants of the document platform, each pinned by test:
- A reprint reproduces the **original** hash and the **original** signature even
  after the signature image is replaced.
- Deleting a frozen file raises `DocumentReproducibilityViolation` rather than
  printing a certificate with a missing signature.
- Watermarks are **output overlays** deliberately excluded from the content hash;
  the clean hashed render never draws them. The plan originally folded the school
  watermark *into* the hashed artefact — that was corrected, and it matters.
- A preview writes no `IssuedDocument`, no print log, and leaves `sequences`
  byte-identical. A preview that logged a print would stamp a school's first
  genuine certificate **DUPLICATA**.

---

## 6. Two claims I made earlier that were wrong

Recorded because the corrections are in the code and the reasoning matters.

**The contrast conclusion was backwards.** I excused `warning` on the grounds it
never carries white text. True, but I then failed to measure it against the
background it *does* render on: `text-warning` on `bg-warning-bg` is **2.25:1**,
worse than its 2.44 against white and below even the 3:1 large-text floor. Twice
I measured a colour against a background it is not used on. Fixed by deriving a
text role from each picked colour — derived, not hard-coded, because the palette
is user-editable and a fixed value would break the moment a school picks a custom
amber.

**I claimed the push problem was solved.** It was not; see §3.

---

## 7. Demo environment

`artisan serve` on port 8940 behind a cloudflared quick tunnel. It must be started
**detached** via PowerShell `Start-Process` — as a harness-managed background
command it is torn down each cycle, and the public URL then serves 502 while the
tunnel itself stays healthy. It is currently **down**; restart it that way if a
demo URL is needed.
