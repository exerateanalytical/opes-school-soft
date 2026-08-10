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

## 5. What's NOT done — in priority order for the next session

1. **Refunds / write-offs.** No schema exists. Touches live financial posting — build carefully, one Action at a time, test the ledger effect against `postedLedger()` before trusting it.
2. **Revenue recognition.** Not started. Also touches financial posting.
3. **Remaining ~44 of 58 documents** (`docs/specs/10-documents.md` §20 has the full catalogue and provenance table).
4. **Full platform-wide popup-form rollout.** The infrastructure (`AutosavesDraft`, `<x-opes-modal-form>`) is proven on two forms. Every other form in the platform is still its pre-existing self. This is a form-by-form conversion, not a single task — pick one module at a time.
5. **Livre d'inventaire** (4th statutory book) — genuinely blocked until year-end close is built; do not half-build it.
6. **DSF line mapping** (0 of 94 accounts) — genuinely blocked on real DGI numbers; do not invent them (rule #11).
7. **9 of 12 blocking gates** in the readiness console — same block as #6, needs real external numbers (MINESEC specimens, CNPS rates, IRPP brackets).

## 6. Known-safe things to build unattended, and things that need a human

**Safe for autonomous/unattended work** (no live-money posting, low blast radius, easy to verify): documents catalogue expansion, more tests for existing code, bug fixes surfaced by tests, UI polish, further popup-form conversions on read-heavy forms, accessibility passes.

**Needs a human in the loop before shipping:** anything in §5 items 1–2 (refunds/write-offs, revenue recognition — real money movement), anything that changes `PostFromEvent` or a posting rule, anything that would need `OPENSSL_CONF` set (rule #10 — cannot even be tested without the user's OS-level change), and obviously: `git push`.
