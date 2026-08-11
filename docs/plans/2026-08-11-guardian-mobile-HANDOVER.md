# HANDOVER — Guardian Mobile Build

Written: 2026-08-11, end of autonomous build session.
Companion documents (read in this order):

1. **This file** — state, risks, what to do next.
2. `docs/plans/2026-08-11-guardian-mobile-build.md` — §0 resume protocol,
   §10 progress ledger (the source of truth for done/not-done).
3. `docs/specs/2026-08-11-guardian-mobile-api-v1.md` — the contract.
4. `docs/specs/2026-08-11-parent-mobile-app-gap-analysis.md` — screen→feature map.

> This file is **not** the repository's root `HANDOVER.md`. That one belongs to
> the platform build and has deliberately not been touched.

---

## 1. Where the build stands

| Slice | State | Evidence |
|---|---|---|
| **A — Doorway** | **DONE** | 14 tests, `tests/Feature/Api/GuardianDeviceTokenTest.php` |
| **B — Read the child** | **DONE** | `tests/Feature/Api/GuardianPortalReadTest.php` |
| **C — Academics** | **DONE** | `tests/Feature/Api/GuardianAcademicsReadTest.php` |
| **D — Money** | NOT STARTED | — |
| **E — Talk** | NOT STARTED | — |
| **F — Find** | NOT STARTED | — |
| **G — Expo app** | NOT STARTED | 81 renamed PNGs in `mobile/` are the source of truth |

`php vendor\bin\pest tests\Feature\Api` → **71 passed, 264 assertions, 0 failed.**

### Endpoints live now
```
POST   /api/v1/auth/token            POST /api/v1/auth/refresh
POST   /api/v1/auth/logout           POST /api/v1/auth/logout-all
GET    /api/v1/auth/devices          DELETE /api/v1/auth/devices/{token}
GET    /api/v1/me
GET    /api/v1/me/children
GET    /api/v1/me/dashboard
GET    /api/v1/me/children/{student}
GET    /api/v1/me/children/{student}/guardians
GET    /api/v1/me/children/{student}/medical
GET    /api/v1/me/children/{student}/results
GET    /api/v1/me/children/{student}/attendance
GET    /api/v1/me/children/{student}/discipline
GET    /api/v1/me/children/{student}/timetable
```
All documented in `docs/api/openapi.yaml` (`OpenApiCoverageTest` enforces it
both ways).

---

## 2. Design decisions a successor must not undo

1. **Token abilities, not role permissions.** `portal.read`/`portal.write` are
   `Permission` enum cases so `IssueApiToken` can validate them, but they are
   **not** granted to the guardian role. `RoleTest` pins
   `Guardian->defaultPermissions() === [PortalAccess]`; granting would break it.
   The scope lives on the token: `can:portal.access` + `abilities:portal.read`.
2. **One implementation per rule.** `PortalContext::resolveForUserId()` (used by
   both doors), `GuardianScopeMatrix`, `GuardianPortalPolicy`,
   `Support\Portal\PublishedResults`, `ChildFeeStatement`, `ChildDirectory`.
   The Livewire portal screens were refactored to call the same helpers — their
   behaviour is unchanged, and that is what the Guardians test suite proves.
3. **404, not 403, for an unlinked child** (matrix row 32). 403 is only for a
   valid link failing a sibling capability.
4. **Query conjuncts stay in the query**, as the matrix comments demand:
   publication state before any mark, `visibility = 'guardian'` for discipline,
   applied-only for promotion, `payer_guardian_id` for own payments.
5. **Per-token expiry (30 days)**, never global `sanctum.expiration` — that key
   would put a clock on every existing staff integration token.

---

## 3. Open risks — read before the next slice

### 3.1 Pre-existing failures (NOT caused by this build)
Full suite baseline: **2491 tests, 2424 passed, 10 failed**. All ten sit in code
this build never touched. Do not silently "fix" them; do not let them mask a new
break. Two deserve action by their owners:

- **`push.vapid_public_key` returns 200 to a guardian principal.** Verified: it
  is the 29th route walked by
  `GuardianDenyByDefaultRouteEnumerationTest`, and the reason that test fails.
  It comes from the still-uncommitted Notifications/push work. It is a real
  deny-by-default hole — gate it, or add it to that test's justified-open list
  with a written reason.
- **`Communication\Livewire\{Messages,Outbox,Templates}\Index` import
  `Identity\Models\User`** (commit 31c0840), so `ModuleBoundaryTest` fails and
  the boundary rule is currently unenforced for that module.

The rest: `AuditChainTest` (asserts the literal `append-only`; the message now
cites AUDCIF Art. 24), `Admissions\WizardScreenTest` ×2, three `Reporting`
render tests, `Ui\ShellTest`, `Ui\Phase8WiringTest`.

### 3.2 A flaky test that will waste your time
`GuardianPortalFeesTest :: it restricts a link holding only row 16 to
receipts…` asserts the rendered HTML `not->toContain('99')`. Livewire injects a
pseudo-random component id (`lw-765053699-0`) that can itself contain `99`.

**I investigated this as a possible row-17 privacy leak and it is not one.** A
throwaway probe stripped `wire:id/key/snapshot` and the `lw-…` ids and
re-checked: `content_only_has_99 => false`, `content_99_count => 0`,
`has_99000 => false`. The other guardian's 99,000 payment does **not** reach the
render. The assertion is simply too broad. Fixing it (assert on the formatted
amount, not the substring) belongs to the test's owner.

### 3.3 Environmental
Running a subset of `tests/Feature/Guardians` alongside other directories
produced `migrations table doesn't exist` / `already exists` errors in
`GuardianAuthorizationTest`. It passes in isolation. Treat as test-DB state, not
code — but re-check if it recurs.

---

## 4. How to resume (no human input required)

```powershell
$env:Path="C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64;$env:Path"
cd C:\laragon\www\opeschool-cloud
php vendor\bin\pest tests\Feature\Api          # fast gate, must stay 71/71
php vendor\bin\pest                            # full suite before a slice is DONE
```

1. Read the ledger (§10 of the plan). Take the first `TODO` row.
2. Slice D is next: fees/statement (reuse `ChildFeeStatement`), invoice detail,
   payments (row 16 filters on `payer_guardian_id`, row 17 is the other
   guardian's), receipt + document downloads (short-lived signed URLs carrying
   the existing `IssuedDocument` verification code). Payment initiation is
   specced to return `501` until a gateway exists — build the route, not a
   fake gateway.
3. Money in payloads is **minor units + currency**, never a float.
4. Update the ledger in the same turn as the work.

---

## 5. The one thing I could not verify, and you should decide

Slice G asks for a pixel-perfect Expo replica of the 81 screens. I can build to
the locked design tokens exactly — palette, radii, shadows, type scale, 8px
spacing grid, 28–32px nav radius, gold active state, red badge — and lay each
screen out against its PNG (same filename → same component name).

What I cannot do in this environment is **diff rendered output against the
source pixels**: that needs a running simulator and a screenshot comparison.
Without it, "10000% pixel perfect" is a claim I would not be able to
substantiate, and I would rather say so now than report it as done.

Two honest options:
- **(a)** Build to the token system faithfully, and flag every screen where the
  image implies something the tokens do not cover (a shadow, an illustration, a
  font weight). Fidelity is then reviewable by eye, screen by screen.
- **(b)** Stand up Expo + a screenshot harness first, so fidelity becomes
  measurable, then build against it.

(b) costs more up front and is the only one that can actually prove the claim.
