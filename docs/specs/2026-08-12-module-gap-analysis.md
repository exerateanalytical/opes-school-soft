# OPES — Gap Analysis Across 17 Proposed Module Designs

Date: 2026-08-12
Status: findings
Method: each proposed design audited against the working tree on `feat/accounting-review` @ `e708bbd`. Every "BUILT" claim below was verified by listing models, actions, screens or migrations — not inferred.

---

## 0. Why this document exists

Seventeen module designs were proposed for OPES: accounting, admissions, school setup, fees, student lifecycle, academic/class, curriculum, staff, timetable, attendance, assignments, examinations, results, promotion, parent/family, communication, discipline, health, documents, ID/matricule, transport, library, activities.

Together they describe on the order of **1,200 individual features**. Audited against the codebase, the large majority already exist — frequently implemented more rigorously than proposed, with database-trigger enforcement, regulatory citations and versioned audit trails.

**This document exists so that the small set of genuine gaps is not lost inside the large set of already-built features.** Building from the proposals directly would mean re-implementing working subsystems, and in at least one case would have actively broken a statutory one.

---

## 1. The single most important finding

**One proposal was not merely redundant — it was wrong for this jurisdiction, and implementing it would have corrupted the statutory books.**

The finance design supplied an illustrative chart of accounts (`101100 Main Bank`, `401100 Student Receivables`, `401200 Supplier Payables`, `411100 School Fees`) drawn from Anglo-American practice. This system is **SYSCOHADA révisé** (OHADA), where class 1 is capital, class 40 is *fournisseurs*, class 41 is *clients*, and revenue is class 7. Those codes feed **Journal → Grand livre → Balance → états financiers → Notes → DSF**. A wrong classification at the root is wrong statutory reporting, not a cosmetic issue.

It also proposed "Statement of Changes in Equity" as a core statement; the SYSCOHADA système normal set is Bilan, Compte de résultat, Tableau des flux de trésorerie, Notes annexes.

Full detail and the rejected-artifact table: `2026-08-12-accounting-finance-architecture.md` §1.2.

**Rule adopted as a result** (§1.1 of that spec, now enforced by test): *no invented statutory account codes, tax mappings, DSF mappings, financial-statement mappings or regulatory fields.*

---

## 2. Audit results

### 2.1 Already built — do not rebuild

| Proposed module | Verified evidence |
|---|---|
| **Accounting engine** | SYSCOHADA CoA with trigger-enforced hierarchy, `JournalEntry`/`Line` with Σdebit=Σcredit by trigger, `PostingRule` (+versioning), `Lettering`, `AnalyticAxis`/`Value`, period two-stage lock, `YearEndChecklist`, `ResultAppropriation`, reconciliation sessions, 10-year immutability |
| **Fees & payments** | 21 models incl. `PaymentAllocation`, `PaymentVoid`, `CashDeskSession`, `CreditNote`, `FeeAdjustment`, `InstallmentPlan`, `ThirdPartyFund`; `AgedBalances` (6 buckets, instalment-due-date axis) |
| **Student lifecycle** | `Student` + `Enrollment` + `EnrollmentSegment`; `StudentStatus`/`StatusTransition`/`DeriveStudentStatus`; `PromotionCriteriaSet`/`Criterion`/`Run`/`Decision` with `Evaluate`/`Apply`/`Override`; `PromoteMatriculeToOfficial`; `ImportBatch`/`Row` |
| **Academic / class structure** | `AcademicYear`(+Status), `AssessmentPeriod`(+Type — terms *or* sequences), `ClassLevel`, `Stream`, `ClassGroup`, `House`, `Room`, `SchoolSection`, `SubSystem` and `Track` enums |
| **Timetable** | `TimetablePeriod`, `TimetableSlot`, `SubjectAllocation` |
| **Staff / HR** | 19 models — `Position`, `SalaryGrade`, `LeaveType`/`Request`/`Accrual`, `StaffAppraisal`(+Criterion), `CollectiveAgreement` |
| **Assignments, exams, results** | 19 Assessment models — `AssessmentFramework`, `AssessmentComponent`, `Assignment`, `AssignmentSubmission`, `ConductAssessment`/`Scale`, `ClassStatistic`, plus `Mark`, `PeriodResult`, `PeriodPublication`, `ReportCardSnapshot`, `Exam` |
| **Promotion / rollover** | Above, plus `Operations\RolloverRun`/`Artifact`/`BalanceCarry`, `ArchiveLeaversStep`, `PreviewStep` |
| **Parent / family** | `Guardian`, `StudentGuardian` with validity windows, `GuardianCapability`, `GuardianScopeMatrix` (32 rows, per-link per-child), `PortalInvitation` |
| **Communication** | `MessageThread`, `Message`, `Participant`, `MessageTemplate`, `OutboxMessage`; `Notification`, `PushSubscription` |
| **Discipline** | `DisciplineCase`, `DisciplineCategory`, `DisciplineSanction`, `ConductAssessment` |
| **Documents & verification** | `DocumentTemplate`, `DocumentSeries`, `DocumentSigningKey`, `IssuedDocument` (verification codes), `BulkPrintJob`, `DocumentPrintLog` |
| **Transport** | `Vehicle`, transport routes/stops/allocations, vehicle driver/fuel/maintenance/trip logs |
| **Library** | 12 models — `Book`, `BookCopy`, `BookCategory`, `BookAcquisition`, `LibraryMember`, `LibraryIssue`, `LibraryRenewal`, `LibraryFine` |
| **Hostel / boarding** | `Hostel`, `HostelRoom`, `HostelBed`, `HostelAllocation`, `HostelInspection` |
| **Inventory / assets** | 15 Inventory models; Assets with full depreciation lifecycle (`RunDepreciation`, `Approve`/`PostDepreciationRun`, `Revalue`, `Impair`, `Dispose`, subsidy clawback) |

Two architectural principles the proposals stressed are **already honoured**:

- *"Never put `student.class_id` and call it the student's class."* — `Student` carries **no** permanent class foreign key. History lives in `Enrollment`/`EnrollmentSegment`.
- *"MINSEC matricule, internal ID and admission number must never be conflated."* — they are separate, with `PromoteMatriculeToOfficial` as a distinct transition.

### 2.2 Genuine gaps — the actual backlog

| # | Gap | Evidence of absence | Notes |
|---:|---|---|---|
| 1 | **Activities / Clubs / Sports / Events / Excursions** | No `Activity`, `Club`, `SportsTeam`, `Event` or `Excursion` model anywhere | Entirely absent. The largest true greenfield item. ~5 designed screens depend on it |
| 2 | **Curriculum framework** | No `Curriculum`, `Syllabus` or `Competency` model | `Subject` and `SubjectAllocation` exist, but there is no curriculum entity, no **versioning**, no Unit/Topic hierarchy, no competency model, and no official MINESEC programme registry |
| 3 | **Alumni** | No `Alumni` model | Graduation is reachable via `PromotionOutcome`; the alumni relationship afterwards does not exist |
| 4 | ~~**Accounting Review & Control Centre**~~ | — | **DELIVERED 2026-08-13.** Control matrix, gate register, journal worklist, suspense, traceability spine, six architecture guards. AR/AP computed; seven controls report NotConfigured pending a verified subledger source |
| 5 | **Admissions depth** | 3 models, 2 screens (`Index`, `Wizard`) | Missing: interview, entrance examination, decision workflow, admission letter, and the **applicant → student conversion**, which is unenforced today |
| 6 | **School setup wizard** | `SchoolProfile` is a single `Setting` model | The structures it would configure already exist; this is a guided wizard over them, not new subsystems |
| 7 | **Receipt format family & payment state machine** | Receipt exists; format variants do not | A4 / A5 / half-A4 / 80mm / 58mm as **separate templates**, plus explicit payment states rather than paid yes/no |
| 8 | **Recurring journals** | No model or action | Only genuine Layer-1 accounting gap |
| 9 | **Boarding roll call** | No `RollCall` model | `Hostel`/`Room`/`Bed`/`Allocation`/`Inspection` all exist; the recurring residence roll call — and its unaccounted-student alert — does not |
| 10 | **Student leave / off-campus permission** | No `StudentLeave` or `HostelLeave` | Staff leave is fully built (`LeaveType`/`Request`/`Accrual`); the *student* boarding equivalent, with parent authorisation and late-return alerting, is absent |
| 11 | **Meal management** | No `Meal` model | Meal counts, dietary flags, dining attendance |

| 12 | **Cross-module analytics / KPI engine** | No `Kpi`, `ReportDefinition`, `SavedReport` or `ScheduledReport` | Per-module dashboards exist (`FinanceDashboard`, `PayablesDashboard`, `TaxDashboard`). A configurable KPI engine, report builder, saved/scheduled reports and a cross-module exception centre do not |
| 13 | **Two-factor authentication** | No TOTP/2FA anywhere in `app/` or `config/` | `RecoveryCredential` exists; a second factor does not. Module 28 rightly wants MFA compulsory for privileged accounts — that cannot be configured today |
| 14 | **Access review / privilege-creep detection** | No `AccessReview` model | Roles, granular permissions, scoped authorisation and an immutable hash-chained audit trail (`AuditLog` + `AuditChainAnchor`) are all built. Periodic recertification of who still needs what is not |

| 15 | **Consent management** | No `Consent` model | Trip/media/medical/activity consent, with versioned parent decisions and an audit record |
| 16 | **Parent requests centre** | No `DocumentRequest` or `ParentRequest` | A tracked queue for transcript requests, attendance disputes, school letters — status, owner, resolution |

| 17 | **Student portal** | No student-facing surface | 39 `portal.*` routes exist but all are **guardian**-scoped. A student has no login of their own — timetable, assignments, own results, own attendance |
| 18 | **Graduation cohort & clearance** | No `GraduationCohort` or `Certificate` | `PromotionOutcome` reaches *graduated* and `enrollments.financial_clearance` exists, so the pieces are there; the cohort, the multi-department clearance workflow, the ceremony and the certificate are not |
| 19 | **Government compliance engine** | No reporting-schema, mapping or submission model | The matricule is modelled and `PromoteMatriculeToOfficial` exists. A versioned reporting schema, field mapping, validation, submission register and resubmission history do not |
| 20 | **Multi-campus** | **No `campus_id` anywhere in migrations** | The schema is single-school throughout. This is the largest item on the list by far: retrofitting a campus dimension touches every table, every query and every authorisation scope. It is an architectural change, not a feature |
| 21 | **API client & integration registry** | No `ApiClient` or `Integration` model | Sanctum tokens and abilities exist; a registry of external integrations with scopes, rate limits, credential rotation, sync state and conflict handling does not. `WebhookEndpoint`/`WebhookDelivery` exist as a partial start |

**Roughly twenty-one real items out of ~2,900 proposed across 29 module designs.**

Modules 30–34 added rows 17–21. Notably **module 34 was already substantially built**: `Backup`, `RestoreDrill` (recovery testing — the spec rightly insists a backup is worthless unless a restore is proven), `Licence`, `WebhookEndpoint`/`Delivery`, plus the audit and permission infrastructure from module 28.

### A note on gap 20

Multi-campus deserves separating from the rest of this list. Every other item is additive — a new model, a new screen, a new workflow. Multi-campus is **retroactive**: it adds a dimension that every existing table, query, index and authorisation scope would need to respect, in a codebase where authorisation is already enforced per-route and per-record. Attempting it late is materially harder than the other twenty items combined, and attempting it *carelessly* creates exactly the cross-tenant leak module 33 §93 warns about. If a school network is a real near-term requirement, it should be planned before more single-school surface area is added.

Module 29 (parent mobile app) was the **best-covered of all** and added only rows 15–16. Its backend is complete — nine guardian API controllers (`Me`, `Children`, `Academics`, `Fees`, `Communication`, `Documents`, `Engagement`, `Profile`, `Search`), scoped per-child per-link by `GuardianScopeMatrix`, and the Expo application exists at `mobile/app`. Even the item that spec flags as critical — payment idempotency so a parent tapping *Pay* twice on a slow network cannot double-charge — is already built as `Identity\Http\Middleware\EnforceIdempotency` (24-hour key retention, replays 2xx, 409 on same-key-different-body).

Online payment initiation remains deliberately unbuilt: `04-fees.md` records that v1 payments are taken at the cash desk, and the API returns `501` until a gateway exists. That is a product decision, not an oversight.

Modules 23–26 added rows 9–11; modules 27–28 added rows 12–14. Everything else in them was already built: `VisitorLog`, `HostelInspection`, `StockTake`, asset maintenance, `SalaryGrade`, `PayrollRun`, the accounting engine, and — for module 28 specifically — the identity/role/permission model, per-route `can:` enforcement, scoped authorisation via `GuardianScopeMatrix`, and a **hash-chained immutable audit trail**, which is stronger than the module asked for.

### 2.3 Deliberately not gaps

- **Notes annexes count.** Sources vary (36 vs 46 tables). Correctly held as an unverified, versioned mapping rather than a constant — `02-accounting.md` §22 gate 13.
- **The 19 accounting configuration gates.** They ship empty *by design*. Closing them is a sourcing task for a qualified accountant, not engineering. Gap #4 makes them visible; it must not fill them.
- **Health clinical records.** The proposal's own instruction — do not duplicate the medical record inside the school ERP — matches the existing boundary. `StudentMedicalRecord` + `EmergencyMedicalSummary` hold the minimal school-side set.

---

## 3. Recommended order

1. **Finish Accounting Review** (gap 4) — in flight, half complete.
2. **Admissions depth** (gap 5) — the student's point of origin; everything downstream assumes a correctly created student, and the applicant→student conversion is unenforced today.
3. **Activities** (gap 1) — largest greenfield, self-contained, no statutory risk.
4. **Curriculum framework** (gap 2) — needed before curriculum-linked assignments and competency assessment mean anything.
5. **Alumni** (gap 3) — small, closes the lifecycle.
6. **Receipt formats + payment states** (gap 7), **recurring journals** (gap 8), **setup wizard** (gap 6) — smaller, independent.

---

## 4. Method note for whoever reads this next

Every row above was verified by listing models, actions, screens or migrations. Three specific corrections arose during this audit that a reader should take as a caution:

1. A grep of **model files** for `journal_entry_id` produced a false positive on `PurchaseOrder` — the only match was a comment stating it deliberately has no such column. **Verify against migrations, not model files.**
2. The accounting spec's test table claims an auxiliary-reconciliation test exists with 1,200 student balances. **It does not** — `ReconcileAuxiliaryBalances` had no test at all. A spec asserting its own coverage is not evidence of that coverage.
3. An earlier draft of the traceability design assumed `journal_entries.source_type`/`source_id` identified source documents. They do not: `source_type` is always the literal `'posting_event'` and `source_id` is never populated. **Check that a column is populated, not merely present.**
