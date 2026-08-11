# Parent/Guardian Mobile App (Expo) — Screen Inventory & Platform Gap Analysis

Date: 2026-08-11
Source: 81 design screens in `mobile/` (renamed to their on-screen titles).
Target: Expo (React Native) parent app + parent mobile web view, consuming the
platform **API as a product** — no direct DB, no Livewire, no server-rendered
parent screens in the mobile path.

---

## 0. Headline

| | |
|---|---|
| Screens designed | 81 (≈62 unique) |
| Screens with a working platform backend today | ~26 |
| Screens with data models but no parent-facing surface | ~20 |
| Screens with nothing behind them | ~16 |
| Expo app | **does not exist** — `mobile/` contains only PNGs |
| Public API surface | **8 read-only GET routes**, none guardian-scoped |

The platform is deep on the admin/back-office side (23 modules, ~270 models,
~470 actions). The parent-facing surface is a 6-screen Livewire portal
(`/portal`). The mobile app is a **new consumer of an API that mostly does not
exist yet** — that is the real work, not the screens.

---

## 1. What exists today

### 1.1 Guardian portal (`routes/web.php` ~line 760, middleware `auth` + `guardian.portal`)
| Route | Component |
|---|---|
| `/portal` | `Guardians\Livewire\Portal\Dashboard` |
| `/portal/children/{student}/profile` | `Portal\ChildProfile` |
| `/portal/children/{student}/results` | `Portal\Results` |
| `/portal/children/{student}/fees` | `Portal\Fees` |
| `/portal/children/{student}/discipline` | `Portal\Discipline` |
| `/portal/children/{student}/documents` | `Portal\Documents` |

Access control worth preserving verbatim in the API: `StudentGuardian` links with
validity windows, `GuardianCapability` / `GuardianScopeMatrix` evaluated **per
link, per child** — a guardian may see fees for one child and not another.

### 1.2 API (`routes/api.php`) — v1, read-only by design
`GET /v1/students`, `/students/{id}`, `/enrollments`, `/invoices`,
`/invoices/{id}`, `/payments`, `/payments/{id}`, `/results`.

Guarded three ways: `auth:sanctum` + `can:<permission>` + `abilities:<permission>`.
Documented in `docs/api/openapi.yaml`, enforced by
`tests/Feature/Api/OpenApiCoverageTest.php` (build fails on undocumented or
orphaned routes).

**Gaps for mobile:** no token-issuing endpoint (tokens are minted from an admin
Livewire screen, `Identity\Livewire\Users\Tokens`), no guardian-scoped
`/me/children`, no writes at all, no push-subscription endpoint, no permission
mapping for a `guardian` caller.

### 1.3 Modules backing parent features
| Module | Relevant assets |
|---|---|
| Fees | Invoice, InvoiceLine, InvoiceInstallment, Payment, Receipt, InstallmentPlan, FeeStructure, Statement, Cashier |
| Assessment | Mark, PeriodResult, PeriodPublication, ReportCardSnapshot/Config, ConductAssessment, Exam, Assignment, AssignmentSubmission |
| Attendance | AttendanceRecord, Register, Summary (admin-only surface) |
| Welfare | MedicalConsultation, MedicalReferral, DisciplineCase/Sanction, Insurance, Transport, Hostel |
| Students | Student, StudentDocument, StudentMedicalRecord, Enrollment, `EmergencyMedicalSummary` action |
| Communication | MessageThread, Message, Participant, Outbox, Templates (teacher/parent/staff threads — recent) |
| Notifications | Notification, PushSubscription, Bell + `public/sw.js`, `resources/js/push-notifications.js` (**uncommitted, in progress**) |
| Forms | FormDraft + `UnfinishedWork` (draft autosave — maps to mobile offline drafts) |
| Guardians | Guardian, StudentGuardian, PortalInvitation, GuardianMeeting, PtaMeeting/Officer |
| Reporting | DocumentTemplate, IssuedDocument, DocumentSeries, DocumentSigningKey, BulkPrintJob, GlobalSearch, WebhookEndpoint/Delivery |
| Academics | AcademicYear, AssessmentPeriod, ClassGroup, TimetablePeriod, TimetableSlot, Subject |

---

## 2. Screen-by-screen status

Legend: **BUILT** = backend + logic exist, needs API exposure only ·
**PARTIAL** = data model exists, no parent-facing logic/permissions ·
**MISSING** = nothing behind it.

### 2.1 Auth & onboarding (6 screens)
| Screen | Status | Note |
|---|---|---|
| splash-screen, welcome-onboarding | MISSING | pure client-side; add language switch (en/fr already in `lang/`) |
| login-welcome-back (+2) | PARTIAL | web session login exists; **no mobile token login endpoint**, no Google/Apple |
| verify-your-account-otp | MISSING | no OTP/SMS infrastructure |
| forgot-password-reset | MISSING | no password-reset flow in the codebase |

### 2.2 Home & navigation (7)
| Screen | Status | Note |
|---|---|---|
| parent-dashboard (+2) | BUILT | `Portal\Dashboard` — needs aggregate `/me/dashboard` |
| my-children | BUILT | valid-link list already computed |
| notifications | PARTIAL | Notification model + Bell exist; no guardian feed, no read-state API |
| global-search (+2) | PARTIAL | `Reporting\GlobalSearch` is admin-scoped; needs guardian-scoped rewrite |

### 2.3 Child (7)
| Screen | Status |
|---|---|
| child-overview (+2), child-profile | BUILT (`Portal\ChildProfile`) |
| child-documents, child-documents-main | BUILT (`Portal\Documents`, StudentDocument) |
| school-information (+2) | PARTIAL — `SchoolProfile` module is 1 model |
| attendance | PARTIAL — records exist, **no parent scope/route** |
| behaviour-discipline | BUILT (`Portal\Discipline`, ConductAssessment) |

### 2.4 Academics (9)
| Screen | Status |
|---|---|
| results-overview, subject-results | BUILT (`Portal\Results`, PeriodResult, publication gating) |
| term-sequence-history | BUILT (AssessmentPeriod + PeriodResult) |
| report-card-viewer | BUILT (ReportCardSnapshot + Reporting templates) |
| transcript-viewer | MISSING — only 3 incidental references, no transcript builder |
| academic-performance-analytics (+2,+3) | MISSING — no trend/benchmark/projection engine |
| assignments | PARTIAL — Assignment/Submission exist (teacher side); no parent view |

### 2.5 Fees & payments (14)
| Screen | Status |
|---|---|
| fees-dashboard, fee-structure-breakdown, outstanding-balance | BUILT (`Portal\Fees`, Statement) |
| payment-history-receipts (+2,+3), payment-receipt (+2,+3,+4), official-fees-receipt | BUILT (Payment, Receipt, IssuedDocument w/ verification code) |
| make-payment, payment-method-selection, payment-processing | **MISSING** — `PaymentMethod` enum documents a hard v1 decision: payments are recorded manually at the cash desk, **no MoMo/bank gateway**. Online payment is a new capability, not an exposure. |

### 2.6 Health (6)
| Screen | Status |
|---|---|
| health-overview (+2) | PARTIAL — StudentMedicalRecord + `EmergencyMedicalSummary` |
| medical-history (+2) | PARTIAL — MedicalConsultation/Referral, staff-only |
| immunization-vaccination-records (+2) | MISSING — no immunization model |
| medical-documents | PARTIAL — StudentDocument can carry them; no medical category surface |
| health-id, opes-health-id | MISSING — OPES Health ID (OHID) identity, card, consultations/prescriptions/lab-results tabs |

### 2.7 Communication (4)
| Screen | Status |
|---|---|
| messages-inbox, message-chat-class-teacher (+2) | BUILT (threads/participants; guardian participation exists) — needs API + realtime |
| school-announcements | PARTIAL — announcements are thin (5 references) |
| teacher-school-contact | PARTIAL — staff directory exists in HR; no parent-facing contact scope |

### 2.8 Activities (5)
school-activities, activity-details, excursions-trips, sports-events (+2) — **MISSING**.
No Activity/Event/Excursion/Registration models anywhere. `Operations` is
backup/licence/rollover, not school events.

### 2.9 Account & identity (11)
| Screen | Status |
|---|---|
| parent-profile | PARTIAL (Guardian model; no self-service edit) |
| account-settings | MISSING |
| notification-preferences | MISSING (PushSubscription exists, no preference matrix) |
| security | MISSING — **no 2FA/TOTP anywhere**; `RecoveryCredential` exists |
| help-support (+2) | MISSING |
| digital-school-id-child-id (+ -secure) | MISSING — DocumentSeries/signing keys exist, no ID-card template/QR verify endpoint |
| emergency-important-contacts (+2,+3) | PARTIAL — guardian contacts exist, no screen/API |

### 2.10 Official documents (5)
bulletin-scolaire-report-card (+2,+3) — **BUILT** (report-card snapshot + template + verification code `RC-…`).
bulletin-de-paie-payslip — BUILT in Payroll, **not a parent artifact** (staff payslip; belongs to a staff app, exclude from the parent scope).

---

## 3. What must be built, in dependency order

**P0 — API as a product foundation (blocks everything)**
1. Mobile auth: token issue/refresh/revoke endpoint, device binding, guardian-scoped abilities.
2. `/v1/me`, `/v1/me/children` — the guardian graph with per-link scope evaluation, reusing `GuardianScopeMatrix` (never re-implement the rule in the app).
3. Guardian-scoped read endpoints for every BUILT screen: dashboard aggregate, child profile, results, report-card, fees/statement, payments/receipts, discipline, documents, attendance.
4. Write endpoints (first ever): mark-notification-read, message send, draft save.
5. OpenAPI entries for each (build fails otherwise) + versioning/deprecation policy + per-token rate limits.
6. Push: subscription registration endpoint + fan-out from `Notifications`.

**P1 — Expose what exists**
Notifications feed, guardian-scoped search, attendance parent view, assignments parent view, staff/contact directory, announcements, emergency contacts, parent profile self-service.

**P2 — New capabilities (real product work)**
Online payments (MoMo/bank gateway, callbacks, reconciliation into `Payment` + Accounting), OPES Health ID module (+ immunization), activities/events/excursions module, transcripts, academic analytics, digital ID cards with QR verification, 2FA + password reset + OTP, notification preferences, help/support.

**P3 — Expo app**
Design tokens from the master visual system (locked to `#013C1F` / `#064A2B` / `#0B5A32` / `#D9A829` / `#FFFFFF` / `#F5F7F6`), navigation shell, offline cache + draft sync (`Forms\FormDraft` is the server counterpart), i18n en/fr.

---

## 4. Architectural rules for this build
- The mobile app talks **only** to `/v1`. No new Livewire parent screens.
- Every endpoint carries the same triple gate as today: `auth:sanctum` + `can:` + `abilities:`. Hiding a screen is presentation, never a control.
- Guardian scope is evaluated per child, per link, on the server. The app never decides what a parent may see.
- Every route documented in `docs/api/openapi.yaml` before merge.
- The API is the product: the parent app, a future student app, and third-party integrations consume the same surface.
