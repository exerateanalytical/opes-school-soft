# Guardian Mobile API v1 — P0 Specification

Date: 2026-08-11
Status: proposed
Scope: the API surface the Expo parent app consumes. Depends on
`docs/specs/2026-08-11-parent-mobile-app-gap-analysis.md`.
Authority for authorization: `docs/specs/07-students.md` §7.5 (the 32-row
guardian matrix), transcribed once in
`App\Modules\Guardians\Domain\GuardianScopeMatrix`.

---

## 1. Principles

1. **The API is the product.** The parent app, a future student app and any
   third-party integration consume the same surface. No mobile-only side door.
2. **REST is a thin adapter** over the same module Actions the Livewire screens
   use (00-core §6.1). Controllers filter, paginate, present. They never decide.
3. **Deny by default, three gates, unchanged:** `auth:sanctum` (who) +
   `can:` (may this user) + `abilities:` (was this token issued for it).
   A fourth gate is added for this surface: **per-child capability**.
4. **Guardian scope is evaluated server-side, per child, per link, per request.**
   The app never decides what a parent may see; it renders what it is given.
5. **Every route documented in `docs/api/openapi.yaml`** before merge —
   `tests/Feature/Api/OpenApiCoverageTest.php` fails the build in both directions.
6. **v1 stays additive.** Breaking changes mint `/v2`. Fields are never
   repurposed; removals go through a deprecation header for one minor cycle.

### Non-goals for P0
Online payment initiation (no gateway exists — R18 is specced but returns
`501` until P2), OPES Health ID, activities/events, transcripts, analytics,
2FA/OTP, document upload (R24 — P1).

---

## 2. Authentication

### 2.1 Why a new flow
Tokens today are minted by a staff user from an admin Livewire screen
(`Identity\Livewire\Users\Tokens` → `IssueApiToken`). A parent cannot reach
that screen and must not. Mobile needs a self-service, device-bound token.

### 2.2 Endpoints (unauthenticated, `throttle:auth-mobile` = 5/min/IP+identifier)

```
POST /api/v1/auth/token
POST /api/v1/auth/refresh        (authenticated, current token)
POST /api/v1/auth/logout         (authenticated, revokes current token only)
POST /api/v1/auth/logout-all     (authenticated, revokes every device token)
GET  /api/v1/auth/devices        (authenticated, list this user's device tokens)
DELETE /api/v1/auth/devices/{id} (authenticated, revoke one device)
```

**`POST /v1/auth/token`**
```json
{ "identifier": "…email or phone…", "password": "…",
  "device_name": "Pixel 8", "device_id": "uuid-v4", "platform": "android" }
```
Response `200`:
```json
{ "data": {
    "token": "1|plain-text-once",
    "expires_at": "2026-09-10T21:00:00Z",
    "abilities": ["portal.read","portal.write"],
    "guardian": { "id": 42, "display_name": "Mrs. Ngo Beth", "language": "en" }
} }
```

Rules:
- The user must be `status=active` **and** resolve to an active, non-archived
  `Guardian` via `portal_user_id`. Anything else → `401` with a **generic**
  message. Never disclose whether the identifier exists or the reason.
- Token name = `mobile:{platform}:{device_id}`. Re-authenticating from the same
  `device_id` **revokes the previous token for that device** before issuing.
- Abilities are exactly `portal.read` (+ `portal.write` once P0 writes land).
  A mobile token can never carry `students.view`, `fee.view`, etc.
- `expires_at` = 30 days. `POST /v1/auth/refresh` rotates the token and extends
  by 30 days; a token past expiry is rejected (`401 token_expired`) and the app
  must re-authenticate. Requires `sanctum.expiration` config + a scheduled prune.
- Every issue/refresh/revoke writes an `AuditLog` entry (`Identity` module).
- Rate limit on `token`: 5/min per IP+identifier, then exponential lockout.

### 2.3 New permissions
Add to `Identity\Domain\Permission` (two segments, like every existing case):

| Case | Value | Meaning |
|---|---|---|
| `PortalRead` | `portal.read` | read one's own guardian portal data |
| `PortalWrite` | `portal.write` | the P0 write set (§5) |

Granted by the `guardian_portal` role only. They gate **presence on the
surface**; the matrix gates **content**. Both are required.

### 2.4 Throttling
Named limiter `api-portal`: 120 req/min per token. `auth-mobile`: 5/min.
Both registered in `AppServiceProvider` beside the existing `api` limiter.

---

## 3. Request context — `ApiPortalContext`

`PortalContext` today rebuilds from `auth()->id()` and is bound by
`EnsureGuardianPortal` on the web route group. The API needs the **same object,
same preconditions, same single business-date evaluation** (7.3: "evaluated once
at transaction start … a request spanning midnight cannot see two different
answers"), reached through a Sanctum token instead of a session.

Implementation: a middleware `guardian.api` (`EnsureGuardianApi`) that
1. resolves the Sanctum user,
2. calls `PortalContext::resolveFromAuth()` — **reused verbatim, not copied**,
3. `abort(403)` when null,
4. binds the instance into the container for the request.

No new authorization logic is written. If `PortalContext` needs a
non-session-aware entry point, it is added there and the web path uses it too.

Route group:
```php
Route::middleware(['auth:sanctum', 'abilities:portal.read', 'can:portal.view', 'guardian.api'])
    ->prefix('v1/me')->group(…);
```

### 3.1 Capability enforcement
Every endpoint below names the matrix row it requires. The controller resolves
`$context->linkFor($studentId)` → `authorizationFlags()` →
`GuardianScopeMatrix::allows($flags, $capability)`.

- No link, or `allows() === false` → **`404`**, not `403`, for anything
  concerning a child (row 32: acknowledging existence is itself row 1).
- `403` is reserved for a valid link that fails a *sibling* capability the app
  should not have offered (e.g. fees for a `receives_reports`-only link).
- Query-level conjuncts are **not** delegated to the matrix and must be applied
  by the query, exactly as the matrix comments require:
  - marks: publication state checked **first** (row 8 returns false always),
  - promotion/annual average: only when the decision is `applied` (row 10),
  - discipline narrative: `visibility = 'guardian'` (row 20),
  - own payments: filter by `payer_guardian_id`, not link scope (row 16),
  - rank: the child's own rank + class denominator only (row 9),
  - other guardians: names + relationship only (row 31).

---

## 4. Read endpoints (P0)

All under `/api/v1/me`. All require `portal.read`.

| # | Endpoint | Matrix row(s) | Backs screen |
|---|---|---|---|
| 1 | `GET /` | — | profile bootstrap |
| 2 | `GET /children` | R01 per child | my-children |
| 3 | `GET /dashboard` | R01, R11, R13, R26 | parent-dashboard |
| 4 | `GET /children/{s}` | R01, R02 | child-overview / child-profile |
| 5 | `GET /children/{s}/results` | R05, R07, R09, R10 | results-overview, subject-results, term-sequence-history |
| 6 | `GET /children/{s}/report-cards` | R05 | report-card list |
| 7 | `GET /children/{s}/report-cards/{id}/download` | R06 | report-card-viewer |
| 8 | `GET /children/{s}/attendance` | R11, R12 | attendance |
| 9 | `GET /children/{s}/discipline` | R19, R20 | behaviour-discipline |
| 10 | `GET /children/{s}/documents` | R22, R23 | child-documents |
| 11 | `GET /children/{s}/documents/{id}/download` | R22, R23 | document viewer |
| 12 | `GET /children/{s}/fees` | R13, R14 | fees-dashboard, fee-structure-breakdown, outstanding-balance |
| 13 | `GET /children/{s}/invoices/{id}` | R13 | invoice detail |
| 14 | `GET /payments` | R16, R17 | payment-history |
| 15 | `GET /receipts/{id}/download` | R15 | payment-receipt, official-fees-receipt |
| 16 | `GET /children/{s}/timetable` | R26 | class schedule |
| 17 | `GET /announcements` | R26 | school-announcements |
| 18 | `GET /children/{s}/guardians` | R31 | other guardians (names + relationship) |
| 19 | `GET /children/{s}/emergency-medical` | R03, R04 | health-overview (narrowed vs full) |
| 20 | `GET /notifications` | — (own) | notifications |
| 21 | `GET /threads`, `GET /threads/{id}/messages` | — (participant) | messages-inbox, chat |
| 22 | `GET /search?q=` | per-row, per child | global-search |

### 4.1 Shapes

Envelope, identical to the existing v1 controllers:
```json
{ "data": …, "meta": { "page":1, "per_page":25, "total":0, "last_page":1 } }
```
Collections paginate with `page` / `per_page` (default 25, max 100).
Dates: ISO-8601. Money: **minor units + currency**
(`{"amount": 12500000, "currency": "XAF"}`) — never a float.

**`GET /v1/me`**
```json
{ "data": {
  "guardian": { "id":42, "display_name":"…", "relationship":"mother",
                "phone":"+237…", "email":"…", "language":"en",
                "preferred_contact_method":"whatsapp" },
  "capabilities_global": ["R16","R26","R29"],
  "unread": { "notifications": 3, "messages": 5 },
  "as_of": "2026-08-11"
} }
```

**`GET /v1/me/children`** — one row per currently-valid link:
```json
{ "data": [ {
  "id": 1201, "matricule":"HBC24567", "first_name":"Emmanuel", "last_name":"Ngo",
  "photo_url": "https://…/signed", "class": "Grade 6B", "status": "active",
  "capabilities": ["R01","R02","R05","R07","R11","R13","R19","R22"]
} ] }
```
`capabilities` is the **rendering contract**: the app hides a tile when the row
is absent. It is a convenience, never the control — every endpoint re-checks.

**`GET /v1/me/children/{s}/fees`**
```json
{ "data": {
  "currency":"XAF",
  "totals": { "billed":…, "paid":…, "outstanding":…, "next_due_on":"2026-05-31" },
  "structure": [ { "fee_item":"Tuition", "period":"Term 1", "amount":… } ],
  "invoices": [ { "id":…, "number":"INV-2024-067", "status":"partially_paid",
                  "issued_on":"…", "due_on":"…", "total":…, "balance":… } ],
  "installments": [ { "due_on":"…", "amount":…, "status":"due_soon" } ]
} }
```

**Downloads (7, 11, 15).** Never a raw storage path. Return
`302` to a **short-lived signed URL** (5 min, single-use) or stream with
`Content-Disposition`. Report cards and receipts already carry an
`IssuedDocument` verification code — include it as `verification_code` so the
app can render the QR that resolves to the public verify page.

### 4.2 Caching
`ETag` + `If-None-Match` on 2, 5, 6, 12, 16, 17 (→ `304`).
`Cache-Control: private, no-store` on 19 (medical) and every download.

---

## 5. Write endpoints (P0) — the first writes in v1

Require `portal.write`. Every write goes through an existing module **Action**,
never a controller-local mutation, and is audited.

| Endpoint | Rule |
|---|---|
| `POST /v1/me/notifications/{id}/read` | own notification only |
| `POST /v1/me/notifications/read-all` | own only |
| `POST /v1/me/threads/{id}/messages` | must be a thread participant; body ≤ 4000 chars; `Communication\Actions\QueueMessage` |
| `POST /v1/me/threads` | recipient must be staff the guardian may contact (R26 scope) |
| `PATCH /v1/me/profile` | **R29 own row only** — contact details only. Any attempt to touch authorization flags is R30 = nobody → `403`, audited as a security event |
| `POST /v1/me/devices/push` | register/replace a `PushSubscription` for this device |
| `DELETE /v1/me/devices/push` | unregister |
| `POST /v1/me/children/{s}/meetings` | R27, `has_custody`; creates a `GuardianMeeting` request |
| `POST /v1/me/children/{s}/sanctions/{id}/ack` | R21, `has_custody` |
| `POST /v1/me/children/{s}/payments` | **R18 (`is_fee_payer` alone)** — specced now, returns `501 not_implemented` until a gateway exists (P2). The route and its ability exist so the app ships against a stable contract. |

**Idempotency.** Every `POST` accepts `Idempotency-Key`. Keys are stored for 24h
with the response; a repeat returns the original response. Mandatory on
messages, meetings, acknowledgements and payments — a flaky mobile network must
not double-post.

**Validation errors** use `422` with the envelope in §6.

---

## 6. Errors

```json
{ "error": { "code": "capability_denied", "message": "…", "details": {} } }
```

| HTTP | code | when |
|---|---|---|
| 401 | `unauthenticated` / `token_expired` / `invalid_credentials` | generic, never disclosing |
| 403 | `capability_denied` / `not_a_guardian` | valid link, wrong capability |
| 404 | `not_found` | unlinked child, or any row-32 situation |
| 409 | `conflict` | idempotency key reuse with a different body |
| 422 | `validation_failed` (+ `details` per field) | |
| 429 | `rate_limited` (+ `Retry-After`) | |
| 501 | `not_implemented` | payment initiation, pre-gateway |

The message is user-safe and localised via `lang/{en,fr}/opes.php` using the
guardian's `language`, honouring `Accept-Language` when present.

---

## 7. Push notifications

`PushSubscription` + `public/sw.js` + `resources/js/push-notifications.js` are
already in flight (uncommitted). P0 adds:
- device registration through `POST /v1/me/devices/push` (Expo push token or
  Web Push keys, discriminated by `platform`),
- fan-out from the `Notifications` module to the guardian's registered devices,
- payload carries `{ type, title, body, deep_link }`; `deep_link` maps to an app
  route (e.g. `opes://children/1201/results`),
- a delivery record so a failed token is pruned after N failures.

Notification **preferences** (which types, quiet hours) are P1.

---

## 8. Enforcement / definition of done

A P0 endpoint is not done until:
1. It appears in `docs/api/openapi.yaml` (build gate already exists).
2. A feature test proves the **negative** path per matrix row: a link missing
   the flag gets `404`/`403`, an expired link gets `404`, an unpublished mark is
   invisible even when the caller has R07.
3. A test proves a **staff** token cannot use `/v1/me` and a **mobile** token
   cannot use `/v1/students`.
4. Money is asserted in minor units; no float appears in any payload.
5. The audit log contains the write.
6. `tests/Architecture/ModuleBoundaryTest.php` still passes — API controllers
   live in their owning module's `Http\Api`, and `Guardians` is never imported
   by a module that must not know about it.

---

## 9. Delivery slices

| Slice | Contents |
|---|---|
| **A — Doorway** | `portal.read`/`portal.write` permissions, `guardian_portal` role wiring, `/v1/auth/*`, `EnsureGuardianApi` + `ApiPortalContext` reuse, limiters, error envelope, OpenAPI scaffolding. Nothing else can start. |
| **B — Read the child** | endpoints 1–4, 18, 19 + capability projection. Unblocks the app shell, my-children, child overview/profile. |
| **C — Academics** | 5, 6, 7, 8, 9, 16 (results, report cards, attendance, discipline, timetable). |
| **D — Money** | 12, 13, 14, 15, 10, 11 (fees, invoices, payments, receipts, documents). |
| **E — Talk** | 17, 20, 21 + the write set + push registration and fan-out. |
| **F — Find** | 22, guardian-scoped search over the slices already shipped. |

Slices B–F are independent of each other and can run in parallel once A lands.
