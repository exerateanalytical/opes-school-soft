# Implementation Plan — Phase 12 (Reach: Portals & API) and Phase 13 (Completion: Documents/PDF/Polish)

Prepared read-only from `/home/user/opes-school-soft` (HANDOVER.md, docs/specs/00-core.md §6/§9/§15, 07-students.md §7.5, 10-documents.md, existing Fees/Accounting module patterns).

---

## 0. Ground truth found in the repo

- Modular monolith is real: `app/Modules/{Identity,Academics,Students,Guardians,Fees,Accounting,Reporting,Communication,...}` — **Reporting and Communication exist but are empty skeletons** (no Actions/Models yet).
- All migrations live flat in `database/migrations/` with per-phase timestamp blocks (Phase 6 used `2026_08_08_240001–240013`). Pre-assigning filenames is the established parallel-agent convention.
- Roles `guardian` and `staff_portal` already exist in `app/Modules/Identity/Domain/Role.php`; permissions are an enum (`Identity/Domain/Permission.php`) seeded by `database/seeders/RolePermissionSeeder.php` (iterates enum cases — adding cases is the whole seeding change).
- `guardians.portal_user_id` (nullable, unique, FK to users) already exists in `2026_08_07_210009_create_guardians_table.php`. **`staff_members` has no user link** — Phase 12 needs one.
- `App\Support\Sequence\SequenceAllocator` (row-locked, in-transaction, throws `SequenceOutsideTransactionException`) is the mandatory numbering primitive for `DocumentSeries`.
- `report_card_snapshots` and `receipts` tables exist — the two highest-value snapshot sources for documents.
- Nav placeholder machinery: `Identity\Support\Navigation::items()/placeholderRoutes()`; flipping `built => true` + removing from placeholders + adding gated routes is the established "module goes live" move (see the finance flip).
- Tests: Pest + real MySQL + `RefreshDatabase`, per-agent DBs `opeschool_test_f1..f5`, `function_exists`-guarded unique helpers, PHPStan level 8 zero suppressions, `tests/Architecture/ModuleBoundaryTest.php` absolute.
- **Upstream phases 5, 7–11 are NOT built yet.** Phase 12/13 features that read attendance, timetable, payroll, discipline must degrade (staff portal ships payslips/leave tabs as "scheduled" panels until Phase 11 lands; guardian portal ships Profile/Results/Fees/Documents, which are all backed by built modules).

## ⚠ NEW COMPOSER DEPENDENCIES — FLAG

Nothing PDF/QR/API-token related is installed (`composer.lock` has no dompdf/sanctum/qr/barcode entry; composer cache at `/root/.cache/composer/files` is empty). I verified `https://repo.packagist.org` **is reachable through the sandbox's agent proxy (HTTP 200)**, so `composer require` should work — but these are new dependencies and must be installed as an explicit, up-front step by ONE agent before parallel work starts (lockfile conflicts otherwise):

| Package | For | Fallback if install fails |
|---|---|---|
| `laravel/sanctum` | API personal access tokens (00-core §4) | Portals ship session-only (they're Livewire and don't need Sanctum); API workstream is deferred |
| `dompdf/dompdf` (or `barryvdh/laravel-dompdf`) | PDF engine (00-core gate 11; pure-PHP, installs unattended on a school PC — satisfies the LAN constraint better than headless-Chrome options) | Render print-CSS Blade "print views" (browser print-to-PDF); keep `PdfRenderer` interface so the engine is swappable |
| `bacon/bacon-qr-code` | Vector QR (§17 tokens) | SVG QR is small enough to vendor later; stub renderer behind interface |
| `picqer/php-barcode-generator` | Code 39 (ID/library cards) | Same interface approach |

ECDSA P-256 signing for QR tokens uses PHP's built-in `openssl_*` — no dependency.

---

## Phase 12 — Reach (portals, API hardening, OpenAPI, webhooks)

### 12.1 Migrations (pre-assigned filenames, block `2026_08_09_250xxx`)

| File | Contents |
|---|---|
| `2026_08_09_250001_add_portal_user_id_to_staff_members.php` | nullable unique `portal_user_id` FK→users (mirror of guardians) |
| `2026_08_09_250002_create_personal_access_tokens_table.php` | Sanctum's table (published, adjusted to house style) |
| `2026_08_09_250003_create_portal_invitations_table.php` | admin-issued invitation codes (no SMTP assumption — 00-core §9.3): subject_type/id, code hash, expires_at, used_at, issued_by |
| `2026_08_09_250004_create_outbox_messages_table.php` | Communication outbox: channel enum(sms\|email\|push\|whatsapp), recipient, payload, status enum(queued\|sent\|failed\|disabled), queued_at/sent_at, subject refs — "degrades to a queued outbox, never a blocking error" |
| `2026_08_09_250005_create_message_templates_table.php` | bilingual templates for fee reminders / publication notices |
| `2026_08_09_250006_create_webhook_endpoints_table.php` | url, secret (encrypted), event allow-list JSON, is_active |
| `2026_08_09_250007_create_webhook_deliveries_table.php` | endpoint FK, event, payload, attempt count, status, next_retry_at |

### 12.2 Guardian portal (Livewire, session auth — the main deliverable)

- **`Guardians/Domain/GuardianScopeMatrix.php`** — pure-PHP transcription of 07-students §7.5 (23 cells). Every portal policy calls it; nothing else decides. Unit-test every cell. Include the "publication state checked first" rule (row 8) and the payments-made-by-me rule (row 16).
- Middleware `EnsureGuardianPortal` resolving `Guardian` from `portal_user_id`, and per-child link resolution gated on `valid_from/valid_to` vs `business_date()` evaluated once per request.
- Routes under `Route::prefix('portal')->middleware(['auth','guardian.portal'])`, entirely separate from the staff sidebar shell (own minimal layout — mockups in `frontend images/`):
  - `/portal` — children dashboard (row 1 scope)
  - `/portal/children/{student}/results` — published report cards + marks (rows 5–10; PDF download wired in Phase 13)
  - `/portal/children/{student}/fees` — invoices, statement, receipts (rows 13–17; reuse `Fees\Actions\StudentStatement`)
  - `/portal/children/{student}/profile`, `/discipline` (visibility-gated), `/documents`
- Livewire components in `app/Modules/Guardians/Livewire/Portal/` — thin adapters over existing Actions; cross-module reads via `DB::table` per ModuleBoundaryTest.
- Account provisioning: `Guardians/Actions/IssuePortalInvitation` + `ActivatePortalAccount` (assigns `guardian` role, links `portal_user_id`); admin UI on the guardian record. Same pair for staff.

### 12.3 Staff portal

`app/Modules/HR/Livewire/Portal/` (or Identity if HR module boundary is awkward pre-Phase-11): `/portal/staff` with own timetable / leave / payslips tabs rendered as "scheduled" panels until phases 8/11 exist; profile + password change work now. Role `staff_portal` gates it.

### 12.4 API hardening + OpenAPI + webhooks

- Install Sanctum; `routes/api.php` (new) with `auth:sanctum` + per-route `can:` mirrors of the web gates. v1 read-only API surface: students, enrollments, invoices, payments, published results — controllers in each module's `Http/` as thin adapters over the same Actions/queries (00-core §6.1: REST and Livewire are both adapters).
- Token management screen under `/users/{user}/tokens` (`user.manage`), abilities = permission names.
- Rate limiting: named limiters in `AppServiceProvider` (`api`: 60/min; `verify`: 10/min/IP per 10-documents §17.2 — created now, used in Phase 13).
- OpenAPI: hand-maintained `docs/api/openapi.yaml` + a Pest test asserting every `routes/api.php` route appears in the spec (no network doc generators).
- Webhooks: `Communication/Actions/DispatchWebhook` fired from existing after-commit events (`invoice.issued`, `payment.recorded`, `results.published`), HMAC-SHA256 signature header, queued (database driver) with exponential retry writing `webhook_deliveries`. Deny-by-default: only allow-listed events per endpoint.

### 12.5 Permissions (enum cases to add — seeding is automatic)

`PortalAccess = 'portal.access'`, `PortalManage = 'portal.manage'` (invitations), `ApiTokenManage = 'api.manage_tokens'`, `WebhookManage = 'webhook.manage'`, `CommunicationSend = 'communication.send'`, `CommunicationView = 'communication.view'`. Add default grants in `Role::defaultPermissions()` (guardian → portal.access; administrator → the manage set).

---

## Phase 13 — Completion (document platform, PDFs, polish)

### 13.1 Migrations (block `2026_08_09_260xxx`)

| File | Contents |
|---|---|
| `2026_08_09_260001_create_document_templates_table.php` | 10-documents §4.1 exactly (code `utf8mb4_0900_as_cs`, signature_roles JSON, version) |
| `2026_08_09_260002_create_document_series_table.php` | §4.3; seed the series catalogue table rows (RCPT, INV, CN, BUL, TRANS, COM, TC, LC, CHAR, BON, CARD, LIBC, VIS, GP, ...) via seeder, counters through `sequences` |
| `2026_08_09_260003_create_issued_documents_table.php` | §4.4 incl. `content_hash`, status enum, superseded_by, `UNIQUE(document_template_id, subject_type, subject_id, snapshot_id)` |
| `2026_08_09_260004_create_document_print_logs_table.php` | §4.4; never cascade-deleted; indexes as specified |
| `2026_08_09_260005_create_bulk_print_jobs_table.php` | §18.2 |
| `2026_08_09_260006_create_document_signing_keys_table.php` | per-instance ECDSA P-256 keypair (private encrypted), key id — §17.1 |
| `2026_08_09_260007_add_document_fields_to_school_profile.php` | state_header fields, fiscal identity (NIU, RCCM…) if not already present, document_language, bilingual_documents, branding slots restricted to the §2.3 allow-list |

### 13.2 Reporting module core (the only PDF path)

- `Reporting/Domain/`: `PdfRenderer` interface, `AmountInWords` (fr Cameroonian quatre-vingts + en short-scale, golden tables from §4.6), `AdmissionNumber::barcodePayload()/fromBarcodePayload()` (round-trip property test), `QrToken` sign/verify (openssl ECDSA P-256, `OPES1.<payload>.<sig>` format).
- `Reporting/Actions/RenderDocument` — §4.8 pipeline verbatim: authorize → resolve template+version → resolve language (request → SchoolSection → SchoolProfile) → snapshot-or-live load → series allocation via `SequenceAllocator` inside the transaction → render (dompdf) → SHA-256 hash → `IssuedDocument` + `DocumentPrintLog` → bytes. Accepts `idempotency_key`; exposes batch `forSubjects(array $ids)`; failed render consumes no number. `is_duplicate` derived from print-log count in-transaction; DUPLICATA watermark; `content_hash` mismatch throws `DocumentReproducibilityViolation`.
- Shared Blade blocks under `resources/views/documents/blocks/`: `state_header`, `school_header` (blocks render if fiscal identity incomplete on money docs), `subject_identity`, `signature_block` (allow-list validated at template save; `minister` etc. denied with §13.2 message), `document_footer`, `qr_block`, `watermark`. All strings in lang files — arch test: no literal in a document Blade.
- Storage: `storage/documents/{yyyy}/{module}/{template}/{serial|uuid}.pdf`, sanitized filenames.

### 13.3 Document catalogue — v1 subset, prioritized by backing data that exists

Wave A (backed by built modules): `FEE-RECEIPT`, `FEE-INVOICE`, `FEE-STATEMENT`, `CREDIT-NOTE`, `RPT-CARD` (from `report_card_snapshots`), `MARK-SHEET`, `BROADSHEET`, `CLASS-LIST`, `STU-INFO`, `ADM-FORM`, `BONAFIDE`, `STMT-RES` (⚠ deviation D1 — internal results only, school stamp only), `CERT-COMP` (⚠ D2 — no examination_type field), `ID-STU` (⚠ D3 — school crest only, canonical admission number, Code 39 unpunctuated payload), `TRANSFER-CERT`/`LEAVING-CERT` (clearance gate + `documents.override_gate`), `CHAR-CERT`, `TESTIMONIAL`, `TABULAR` engine. Wave B (blocked on phases 8–11): timetable prints, attendance sheets, payslips — register templates now with `is_active=false`.

The three mockup deviations D1–D3 are release blockers if implemented as drawn; the forbidden-strings golden-file sweep test (§2.3) ships with the first certificate.

### 13.4 Bulk Prints + QR verify + polish

- `/documents/bulk-prints` Livewire screen (§18.1) + queued `BulkPrintJob` (database queue), per-subject transactions, resumable `unprinted` mode, merged output PDF. Performance assertion deferred until the 1,200-student fixture debt is paid (keep the tracked debt).
- In-app verification screen `/documents/verify` (paste/scan token → VALID/REVOKED/SUPERSEDED/NOT FOUND); optional public `GET /verify/{token}` behind the `verify` rate limiter, noindex, generic failures.
- Wire guardian portal downloads (rows 6, 15, 22 of the scope matrix) through `RenderDocument` — a guardian render is a print-log row like any other.
- Nav flip: `reports` item `built => true` → `/reports` (Reports catalogue over `TABULAR`), add `documents` routes; remove from `placeholderRoutes()`; `ShellTest` stays green.
- Polish pass: responsive audit of existing screens vs `frontend images/`, accessibility (labels/contrast/focus), `Model::preventLazyLoading()` already active — fix anything it flags under document batch rendering.

### 13.5 Permissions (enum additions)

`DocumentsPrint`, `DocumentsReprint`, `DocumentsReprintFinancial`, `DocumentsBulkPrint`, `DocumentsRevoke`, `DocumentsTemplateManage`, `DocumentsOverrideGate` — exactly 10-documents §19; role grants: bursar/accountant get `reprint.financial`, principal gets `override_gate`.

---

## Test list

Phase 12: `tests/Feature/Guardians/GuardianScopeMatrixTest` (all 23 cells, both grant and deny), `PortalInvitationTest`, `GuardianPortalResultsTest` (published-only, no other student's rank), `GuardianPortalFeesTest`, `GuardianDenyByDefaultRouteEnumerationTest` (walk the route table; guardian denied unless allow-listed — 00-core §9.2), `StaffPortalTest`, `tests/Feature/Api/TokenAuthTest`, `ApiStudentsTest`, `ApiFeesTest`, `ApiRateLimitTest`, `OpenApiCoverageTest`, `tests/Feature/Communication/OutboxTest` (offline → queued, never error), `WebhookDispatchTest` (after-commit, HMAC, retry).

Phase 13: `tests/Feature/Reporting/`: `SeriesAllocationTest` (gaps permitted, no number on failed render, no max()+1), `IssuedDocumentTest` (original vs DUPLICATA derivation, revoke/supersede), `SnapshotByteIdenticalTest` (issue → mutate underlying rows → re-render → identical hash), `LiveDocumentFooterTest`, `AmountInWordsTest` (golden tables fr/en), `AdmissionNumberRoundTripTest`, `QrTokenTest` (sign/verify/tamper/no-PII), `ForbiddenStringsSweepTest` (§2.3), `FiscalIdentityBlockTest` (receipt refuses without NIU), `ReceiptRenderTest`/`InvoiceRenderTest`/`ReportCardRenderTest` per language (text-layer golden, not raw bytes), `TransferCertClearanceGateTest`, `BulkPrintJobTest` (partial/resume/per-subject tx), `VerifyScreenTest`, updated `ShellTest`/`PlaceholderRoutesTest`, architecture tests (no literals in document blades; RenderDocument is the only dompdf call site).

## Parallel agent scopes (disjoint; per-agent DBs)

Sequencing: **Agent P0 runs alone first** — `composer require` of the four packages + Sanctum publish migration + Permission/Role enum additions + all 14 pre-assigned migration stubs, committed to main so no one else touches `composer.lock`, `Permission.php`, `Role.php`, `Navigation.php`, `routes/web.php` concurrently (those five files are P0/serial-only territory; later route/nav flips are done by P5/D5 with `class_exists` guards per the F5 convention).

| Agent | DB | Scope (exact paths) |
|---|---|---|
| P1 | `opeschool_test_f1` | GuardianScopeMatrix + guardian portal middleware/policies + portal invitations (Guardians module only) |
| P2 | `opeschool_test_f2` | Guardian portal Livewire screens + blades + deny-by-default enumeration test |
| P3 | `opeschool_test_f3` | API: Sanctum wiring, `routes/api.php`, resources/controllers, OpenAPI + coverage test |
| P4 | `opeschool_test_f4` | Communication outbox + webhooks + staff portal shell |
| D1 | `opeschool_test_f1` | Reporting core: RenderDocument, series, IssuedDocument/PrintLog models, PdfRenderer, shared blocks |
| D2 | `opeschool_test_f2` | Domain utilities: AmountInWords, AdmissionNumber, QrToken, signing keys, verify screen |
| D3 | `opeschool_test_f3` | Money documents (receipt/invoice/statement/credit note) + fiscal-identity gate |
| D4 | `opeschool_test_f4` | Academic documents (report card, broadsheet, mark sheet, STMT-RES, certificates, ID card) + forbidden-strings sweep |
| D5 | `opeschool_test_f5` | Bulk prints screen/job, Reports catalogue (`TABULAR`), nav/route flips, polish pass |

D1+D2 must merge before D3/D4/D5 start (they consume RenderDocument). Standing rules apply throughout: exact-path `git add`, function_exists-guarded unique helpers, solo full-suite run + PHPStan 0 + `composer deploy` at each phase close.

### Critical Files for Implementation

- /home/user/opes-school-soft/docs/specs/10-documents.md (binding contract for every Phase 13 entity and invariant)
- /home/user/opes-school-soft/docs/specs/07-students.md (§7.5 guardian authorization matrix — the Phase 12 core)
- /home/user/opes-school-soft/app/Support/Sequence/SequenceAllocator.php (series numbering primitive for RenderDocument)
- /home/user/opes-school-soft/app/Modules/Identity/Domain/Permission.php (enum additions drive seeding and route gates)
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php (nav/route flip contract; plus routes/web.php)