# OPES Gap-Closing — Resumable State

> **Read this file FIRST, before doing anything else, at the start of every session — whether started by a human or by a scheduled/cron invocation.** It is the durable memory this work depends on. Conversation history is not durable; this file is (it lives in git). If you are picking this work up cold with no other context, this file plus `git log` is everything you need.

**Last updated:** 2026-08-10, mid-session (see `git log` for the exact commit this was current as of).

**Repo:** `C:\laragon\www\opeschool-cloud` — Laravel 13.24 / Livewire 4.3 / Tailwind 4 / MySQL 8.4.3 / PHP 8.3.30 (Laragon toolchain).

**PHP binary:** `/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe` — the system `php` is NOT this toolchain. Always use the full path.

**Demo:** originally scoped for "Tuesday." Treat the `opeschool` database as live presentation data — see hard rules below.

---

## 1. Hard rules — violating any of these has caused real damage this session

1. **NEVER run `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `db:wipe` against the `opeschool` database.** It holds the demo data. Tests run with `DB_DATABASE=opeschool_test`.
2. **NEVER run two test suites concurrently against `opeschool_test`.** `RefreshDatabase` truncates the whole schema per run; two overlapping runs corrupt each other and produce phantom failures that look like real bugs but aren't. Before starting a new test run, confirm no earlier one is still in flight. If in doubt, run `powershell -Command "Get-Process php -ErrorAction SilentlyContinue"` and check nothing is still alive from a prior command.
3. **Never trust a "failed" test result without checking the actual error.** Several apparent failures this session were schema-collision artifacts from rule #2, not real defects. Read the full error before concluding anything is broken.
4. **Livewire::test() does NOT prove a route works.** It instantiates the component directly and never resolves the alias registered in `AppServiceProvider`. A missing alias renders fine under `Livewire::test()` and answers 500 in a browser. `tests/Feature/Shell/RouteSmokeTest.php` is the guard — it walks every Livewire-backed GET route over real HTTP. Run it after wiring any new screen.
5. **Every screen needs five-part wiring, always, no exceptions:** route in `routes/web.php` (check ordering against `{param}` catch-all siblings — a route like `/students/import` MUST be registered before `/students/{student}`, which has no numeric constraint and will swallow it) + `Livewire::component()` alias in `AppServiceProvider.php` + nav entry in `app/Modules/Identity/Support/Navigation.php` + lang keys in **both** `lang/en/opes.php` and `lang/fr/opes.php` (verify parity with the snippet in §4 below — zero drift, always) + nav icon in `resources/views/components/opes-nav-icon.blade.php`.
6. **Cross-module code may only import another module's `Actions`, `Domain`, or `Concerns` — never its `Models`.** `tests/Architecture/ModuleBoundaryTest.php` enforces this. A plain string literal referencing another module's FQCN (e.g. for a polymorphic `imported_record_type` column) is fine; a `use` import of the actual class is not.
7. **The ledger's only write path is `PostFromEvent`.** Never write `journal_entries`/`journal_entry_lines` directly from a new feature.
8. **`JournalEntry::query()->postedLedger()`, never `where('status', 'posted')`.** The scope is `posted` + `reversed`. Filtering to `posted` alone drops the original half of a reversal pair and silently flips the sign of the transaction while it still balances — this produced a real, serious bug earlier this session (every FY2026 treasury float read zero).
9. **A modal form must gate DOM presence with Blade's own `@if($open)`, not bare `x-show="$wire.property"` or `@entangle` on the SHOW/HIDE state of a REUSABLE Blade component's root.** Verified broken in a real browser session twice (both the bare `$wire.` shorthand and `@entangle` failed to reliably re-fire Alpine's effect across a Livewire morph in that specific nested-component structure). `resources/views/components/opes-modal-form.blade.php` is the known-working pattern — copy it, don't reinvent it. (`@entangle` works fine for a component's OWN direct view, e.g. the notification bell dropdown — the failure was specific to a separate, reusable `<x-... />` Blade component file.)
10. **This PHP build has no `openssl.cnf` configured (`OPENSSL_CONF` unset).** Every EC key operation (`openssl_pkey_new` with `OPENSSL_KEYTYPE_EC`) fails closed. This blocks: QR document signing, VAPID key generation, and therefore real Web Push delivery. It CANNOT be fixed from inside the application — PHP's openssl extension reads the env var at module init, before any app code runs. It needs `setx OPENSSL_CONF "C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/extras/ssl/openssl.cnf"` at the OS level, then a PHP/Laragon restart. This is the user's call, not something to attempt from inside a session.
11. **A `NEEDS VERIFICATION` value in the specs (`docs/specs/*.md`) is never invented.** 00-core §16: "a wrong seeded value is more dangerous than an empty field, because it looks authoritative." If a real number is needed (DSF codes, CNPS rates, IRPP brackets, MINESEC specimens) and isn't in the specs, leave it unset and say so — don't guess.
12. **Before any destructive git operation, `git status` first.** This session found a `mobile/` directory of the user's own in-progress mockup images sitting untracked in the repo root — never added, never touched. Always check for unfamiliar untracked content before a broad `git add`.
13. **Never `git push`.** Commit locally only, unless explicitly told to push in that exact message.
14. **Never pass `--env=testing` (or any `--env=`) to an artisan command in this repo.** There is no `.env.testing` file. Laravel does NOT error when the named env file is missing — it silently falls back to the real `.env`, whose `DB_DATABASE` is `opeschool`, the live demo database. On 2026-08-10 an agent ran `migrate:fresh --env=testing --force` to rebuild a corrupted test schema and it wiped the live `opeschool` DB instead (recovered from `storage/opes-backups/`, see git log around this date). The ONLY correct way to target the test database is the explicit env var prefix: `DB_DATABASE=opeschool_test php artisan ...`. Never use `--env=` for this, ever.
15. **After ANY edit to `resources/css/app.css`, `resources/js/*`, or `vite.config.js`, run `npm run build` before checking the result in a browser.** This repo has no CSS/JS hot-reload in the preview setup used this session — the server only serves the compiled `public/build/*` assets, and Vite is not running as a separate dev-mode watcher. A style change that "does nothing" almost always just needs a rebuild, not a code fix. Verified 2026-08-11: `--color-chrome` did not update in the browser until `npm run build` ran.
    **`npm run build` alone is NOT enough.** Vite emits a NEW content-hashed filename each build (`app-m9fw80qT.css` → `app-BQh3fuQo.css`), but Laravel's compiled Blade views cache the old `@vite` manifest reference and keep serving the previous hash. The full incantation after any asset edit is:
    ```
    npm run build && php artisan view:clear && php artisan config:clear
    ```
    then hard-navigate the browser. Diagnose this by checking the served filename — `Array.from(document.querySelectorAll('link[rel=stylesheet]')).map(l=>l.href)` — against the hash `npm run build` just printed. If they differ, it's the view cache, not your CSS.
16. **A screenshot requires the Browser pane to be visibly displayed.** If it's hidden/minimised the page stops compositing frames and `computer{action:"screenshot"}` times out after 5s. This is an environment limitation, not a page error — do NOT "fix" working code in response to it. Fall back to `javascript_tool` computed-style assertions (`getComputedStyle(el).fontSize` etc.), which verify the real rendered result, and say plainly in the commit/report that no screenshot was captured and why.

---

## 2. Standard build loop (the process every item below follows)

For each item: **build → migrate → wire (rule #5) → write tests → run them SOLO against `opeschool_test`, waiting for completion before starting another → fix any real bug found → verify end-to-end against the real `opeschool` demo data (not just unit tests) → browser-verify if it's a UI-visible change → clean up any test/demo rows the verification script itself created (unless they're genuinely good demo content, in which case say so and leave them) → commit with an honest message that states what was verified and any bug found along the way → update this file.**

Every phase this session has found at least one real bug this way — trust the process, don't skip steps to go faster.

---

## 3. What's done (see `git log` for exact commits/messages — this is a summary, not the full record)

| # | Item | Status |
|---|---|---|
| 1 | Statutory books (livre-journal, grand livre, balance générale) | ✅ Generating real numbers against demo ledger |
| 2 | Data import suite (students, guardians, staff) | ✅ All three commit through real domain Actions; guardian→student linking by matricule |
| 3 | Go-live readiness console | ✅ Read-only, evaluates live state, no invented values |
| 4 | MINESEC conduct block | ✅ |
| 5 | Parent portal login | ✅ + fixed a platform-wide bug: all 134 guardian↔student links were future-dated |
| 6 | In-platform messaging | ✅ |
| 7 | Homework/assignments | ✅ Flagship for the popup-form pattern |
| 8 | PTA (officers + general meetings) | ✅ |
| 9 | Universal popup form + autosave + hold/resume | ✅ Infrastructure proven on the homework form and the webhooks form (two independent proofs) |
| 10 | Notification engine + Web Push | ✅ In-app fully working. Push send code-complete, RFC-reviewed, **unverified end-to-end** (rule #10) |
| 11 | 10-year retention observer (AUDCIF Art. 24) | ✅ All 28 named models |
| 12 | Documentation du système comptable (§14.4) | ✅ |
| 13 | Outbound webhooks | ✅ Sign/deliver/retry/exhaust, HMAC verified byte-for-byte in tests |
| 14 | Global search | ✅ Replaced the honest disabled placeholder; RBAC-scoped per source |

## 4. Locale parity check (run after ANY lang file edit)

```bash
cd /c/laragon/www/opeschool-cloud && /c/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe -r '$en=require "lang/en/opes.php"; $fr=require "lang/fr/opes.php"; function flat($a,$p=""){ $o=[]; foreach($a as $k=>$v){ $key=$p===""?$k:"$p.$k"; if(is_array($v)) $o=array_merge($o,flat($v,$key)); else $o[]=$key; } return $o; } $e=flat($en);$f=flat($fr); printf("en=%d fr=%d diff=%d\n",count($e),count($f),count(array_diff($e,$f))+count(array_diff($f,$e)));'
```
Expected: `diff=0`, always. Last known-good count: en=1788 fr=1788 (will drift upward as more is built — the diff is what matters, not the absolute count).

## 4a. Heritage Design System rollout (2026-08-11) — do this FIRST, ahead of everything in §5

The user supplied a full design spec: Heritage Deep Green `#013C1F`/`#002D17` + Forest Green `#064A2B` + Green `#0B5A32` + Gold `#D9A829` + White/Soft-Surface, Inter typeface, 16-18px body text, flat cards with thin borders (not neumorphic), 8px spacing grid, a defined corner-radius scale (4/8/10/12/16/20/24/28px), and a defined shadow scale. The full token spec (colors, semantic states, radii, shadows, typography scale) is now in `resources/css/app.css` under `@theme` and the additive `--color-heritage-*`/`--color-gold-*`/`--color-surface-*`/`--color-text-*`/`--shadow-heritage-*` tokens — read that file, it's the source of truth, don't re-derive values from memory.

**Phase 1 — tokens (DONE, commit `932dd59`):** remapped the existing 8 core token names (`color-chrome`, `color-chrome-light`, `color-primary`, `color-heritage-red`, `color-heritage-yellow`, `color-ivory`, `color-sand`, `color-charcoal`) to Heritage values, so every screen already consuming them re-skinned automatically. Added the full additive Heritage scale for granular use. Switched the bundled font from Instrument Sans to Inter (`vite.config.js`, bunny fonts plugin — must run `npm run build` after any app.css/vite.config.js edit, there is no CSS hot-reload in this setup, verified in a real browser against the dashboard and login screens).

**Phase 2 — systematic sweep, one module/screen at a time (NOT done, this is the overnight work):**
1. **Typography audit.** Grep each module's Blade views for `text-xs`/`text-sm` used on primary body content (not captions/labels, those can stay smaller) and bump to `text-base`/`text-lg` so body text reads at the 16-18px the user asked for. Page titles should be visually strong (22-26px/700 per the spec) — check `<h1>`/page-title components use a weight/size that matches, not a default browser heading.
2. **Card/surface consistency.** Every card should be `bg-white` (or `--color-surface-white`) with a `1px` `--color-border-primary` border and the `--shadow-heritage-sm` shadow — NOT heavy drop shadows, NOT floating/neumorphic cards. Grep for inline `shadow-lg`/`shadow-xl`/`shadow-2xl` Tailwind utilities on cards and replace with the Heritage shadow scale.
3. **Corner radius consistency.** Standard controls/inputs `~12px`, cards `~16px`, large/hero cards `~20-24px`, nav/pill elements can go higher. Sweep for inconsistent `rounded-md`/`rounded-lg`/`rounded-xl` usage that doesn't match this scale.
4. **Detail-page completeness.** The user explicitly wants "every detail page truly a detail page" — for each module's Show/detail screen, compare against its spec section (docs/specs/*.md) and confirm every field/relationship/action the spec describes is actually rendered, not just a subset. This is a content-completeness audit, not just a style pass — flag any detail page that's thinner than its spec before restyling it, note the gap in this file rather than silently leaving it incomplete.
5. **Responsiveness.** After restyling a screen, verify it at desktop breakpoints (the user emphasized "every desktop screen" — check at minimum ~1280px and ~1440px+ widths) using `resize_window` in the Claude Browser tools; the sidebar (`~240-260px`) and content grid should reflow sensibly, not just clip.
6. **Gold discipline.** Per the spec, gold is an accent (crest, premium indicators, active nav, certificate borders) — never a large fill. When restyling a screen, don't over-apply `--color-gold-500`/`--color-heritage-yellow` to backgrounds; keep it to accents, active states, and icons, same discipline the old red/yellow rule already enforced.

Work through modules in the SAME priority order as §5 below (cheap nav fixes first, since those screens get touched anyway) but treat the visual/typography/completeness pass as part of "wiring a screen properly," not a separate task — when you touch a screen for any reason, bring it fully up to the Heritage system rather than leaving it half-migrated. Log progress per module in the night log (§6a): `- <module>: N of M screens migrated, gaps found: <list>`.

## 5. What's NOT done — in priority order for the next session

> Revised 2026-08-11 after a full 4-agent platform audit (Students/Guardians/Academics/Assessment; Fees/Accounting/Tax/Procurement; Identity/HR/Payroll/Welfare; Communication/Notifications/Documents/Reporting). Items 1-5 below are new/reordered findings from that audit — do these FIRST, they're cheap and safe. Items 6+ are the pre-existing backlog.

1. **Verify and likely fix: HR staff self-service portal may 500.** Route `/portal/staff` → `HR\Livewire\Portal\Show` — no confirmed `Livewire::component()` registration found in `AppServiceProvider.php` during the audit. Register it if missing, then confirm with `RouteSmokeTest`.
2. **Nav-reachability gaps — feature-complete screens invisible in the sidebar.** Add `Navigation.php` entries for: Students Promotion wizard (route exists, no `Livewire::component()` registration either — check that too), Academic Reports (`reports.academic`, already registered in ASP, just missing nav), Assessment Reports (`reports.assessment`, same), Welfare Medical/Visitors screens (routed and registered, no confirmed nav entry). Each is a ~5-minute fix per the 5-part wiring rule (§1 rule 5) but must include the lang-parity check.
3. **Document catalogue: 8 of 58 built.** Next five highest-value, in this order: `ADM-FORM` (Admission Form, spec §7.1), `TRANSFER-CERT` (§7.6), `LEAVING-CERT` (§7.7), `ID-STU` (Student ID Card, §12.1 — note the mockup deviation clause in spec §3, must not ship as literally drawn), `LEAVE-APP` (§11.3) or `GATE-PASS` (§12.4). Follow the existing pattern: seed a `document_templates` row + Blade view under `resources/views/documents/`, wire through the existing `RenderDocument` action — do not build a parallel rendering path.
4. **Popup-form rollout is narrow** — only ~2 Livewire consumers use `AutosavesDraft`/`<x-opes-modal-form>` so far (homework, marks entry). Pick one more form per run, per the existing rollout item below.
5. **Accounting module has no discoverable test files**, despite being the ledger's core and the only caller of `PostFromEvent`. Add tests for existing behavior (chart of accounts, journal entries, trial balance, statutory books) — do NOT modify `PostFromEvent` or any posting logic while doing this, tests only.
6. **Refunds / write-offs.** Confirmed still read-only (reporting screens read `refunds`/`write_off_lines` tables, no creation Action or UI exists). Touches live financial posting — **do not build this autonomously**, it's in §6 "needs a human" below.
7. **Revenue recognition.** Not started. Also touches financial posting — needs a human.
8. **Remaining documents** beyond the top-5 in item 3 (`docs/specs/10-documents.md` §20 has the full catalogue and provenance table).
9. **Livre d'inventaire** (4th statutory book) — genuinely blocked until year-end close is built; do not half-build it.
10. **DSF line mapping** (0 of 94 accounts) — genuinely blocked on real DGI numbers; do not invent them (rule #11).
11. **9 of 12 blocking gates** in the readiness console — same block as #10, needs real external numbers (MINESEC specimens, CNPS rates, IRPP brackets).

## 6a. Night log (autonomous scheduled runs)

> Each unattended run appends ONE line here: `- YYYY-MM-DD HH:MM — <what happened>`. Read the last few entries first so consecutive runs don't repeat each other's work.

- 2026-08-11 00:20 — Heritage Phase 1: token remap + Inter + 17px root (`932dd59`). Browser-verified on login + dashboard.
- 2026-08-11 00:40 — Heritage type/radius/shadow scales (`5775439`). text-sm 14.88→15.94px, rounded-lg 8.5→12.75px, stock shadows remapped to green-tinted. Found rule 15's second half: `npm run build` alone is not enough, Laravel serves a stale manifest until `view:clear`.
- 2026-08-11 01:00 — Heritage shell (`c71d9a6`): canvas moved to #F5F7F6 so white cards lift off it, gold active-nav indicator + gold active icon, top-bar border to #DCE5DF, and the missing Promotion sidebar entry (five-part wired, parity diff=0). **Audit correction:** 3 of the 4 "nav-unreachable" screens the earlier audit flagged were FALSE POSITIVES — medical and visitors are already in Navigation.php, academic/assessment reports live behind the /reports hub by design, and the HR staff-portal route is fine (11 tests pass). Only Promotion was genuinely missing. Verify audit claims individually before acting on them.
- 2026-08-11 01:05 — Renamed `border-sand`→`border-border-primary` and `divide-sand`→`divide-border-primary` across 120 view files (1 793 + 80 occurrences). This was a regression I introduced in Phase 1: remapping `--color-sand` to the #F5F7F6 surface made every card border nearly invisible against white. Borders now render #DCE5DF. **Watch for this class of bug** when remapping a token that serves two roles — `bg-sand` (275 uses) legitimately wants the light surface, `border-sand` did not.
- 2026-08-11 01:10 — Welfare detail pages enriched (room/vehicle/policy/discipline-case): occupants, beds, inspections, routes-served, route manifest, driver history, running costs, billing linkage, claim totals, guardian parties, student history, lifecycle timeline. All four browser-verified rendering without error. Responsiveness checked at 1280/1440/1920 — no horizontal overflow, tables wrapped in overflow-x:auto, sidebar 255px.
- **Schema gaps found while enriching Welfare (NOT faked on-page, stated as unavailable — need a human decision):** (1) no hostel fee/billing linkage — no `fee_item_id` on `hostels`/`hostel_rooms`/`hostel_allocations`; (2) no vehicle→route FK, so a vehicle's assigned students can only be derived through the trip log; (3) no per-case guardian NOTIFICATION record — only sanction `acknowledged_at`, so the page shows visibility+acknowledgement rather than "notified"; (4) `vehicle_drivers.licence_no` is an `encrypted` cast and unreadable via the `DB::table` reads those components use.

## 6. Known-safe things to build unattended, and things that need a human

**Safe for autonomous/unattended work** (no live-money posting, low blast radius, easy to verify): nav-reachability fixes (§5 items 1-2), documents catalogue expansion, more tests for existing code (including closing the Accounting test gap, §5 item 5 — tests only, never touch `PostFromEvent`), bug fixes surfaced by tests, UI polish, further popup-form conversions on read-heavy forms, accessibility passes.

**Needs a human in the loop before shipping:** anything in §5 items 6–7 (refunds/write-offs, revenue recognition — real money movement), anything that changes `PostFromEvent` or a posting rule, anything that would need `OPENSSL_CONF` set (rule #10 — cannot even be tested without the user's OS-level change), and obviously: `git push`.

**Leave alone — parked for human review, not autonomous work:** the two stray worktrees under `.claude/worktrees/` from 2026-08-10 (`agent-a746d99bcdcd921e2` — Guardian popup-form conversion, has a couple of stray debug artifacts to eyeball before any merge; `agent-a22b256b841ecf39d` — webhook/notification tests, uncommitted, this is the agent that caused the `--env=testing` incident). Do not merge or build on top of either without the user reviewing them first. Build fresh on `main` instead.
