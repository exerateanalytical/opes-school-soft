# Phase 10 (Welfare) Implementation Plan

## 1. Scope and context

Per `docs/specs/00-core.md` §12 row 10 and the design doc §14 (`docs/specs/2026-08-07-opes-school-design.md` lines 600–616), Phase 10 = **Transport, Hostel, Medical, Visitors, Student Insurance**. **Discipline is Phase 8** (00-core §12 row 8) and is explicitly out of scope here. The `app/Modules/Welfare/` skeleton exists (all subdirectories empty). Roles `Nurse`, `WelfareOfficer`, `FrontDesk` already exist in `app/Modules/Identity/Domain/Role.php` with empty default permission sets (line 148).

Key constraints found in the codebase:

- **No ledger writes in Phase 10.** The `PostingEvent` catalogue (`app/Modules/Accounting/Domain/PostingEvent.php`) has no welfare events, and none are needed: insurance premium billing is a `FeeItem` (design §14: "The premium is a FeeItem, so it bills and posts like any other fee"), fuel/maintenance costs flow through Phase 5 supplier invoices, boarding/transport fees flow through Phase 6 audience criteria (`boarding_status`, `transport_status` dimensions already exist in `04-fees.md` §audience). Any agent adding a second posting path is a review-blocking defect.
- **Phases 8/9 are not built** — no `assets`, `attendance`, or `discipline` tables exist. `vehicles.asset_id` and `insurance_policies.asset_id` must be **nullable unsigned bigints with NO foreign key constraint** (FK added by a Phase 9 follow-up migration). Document this in the migration comment.
- Cross-module reads via `DB::table('students')` / `DB::table('enrollments')` only; `TransportAllocation`/`HostelAllocation` are keyed on `enrollment_id` (07-students.md line 39). `tests/Architecture/ModuleBoundaryTest.php` enforces this.
- Medical data is health data about minors: encrypt with the `'encrypted'` cast exactly as `StudentMedicalRecord.php` does (`detail => 'encrypted'`). Chronic conditions/allergies stay in Students' `StudentMedicalRecord`; Welfare adds **consultations/referrals** and reads the conditions via `DB::table('student_medical_records')` only for counts (never decrypting cross-module — surface via a `Students` Action if decrypted detail is needed; recommend a new `Students\Actions\EmergencyMedicalSummary` door).
- Permission enum: two-segment `module.action` values only (test-enforced, values double as translation keys — see comment at `Permission.php:44`). New cases must be appended, never renamed. Labels needed in `lang/en/opes.php` + `lang/fr/opes.php` (`LocalisationTest`).

## 2. Step 0 — Orchestrator pre-commit (before agents fork worktrees)

One small commit on `phase-10-welfare` touching ONLY shared files, so builder agents never touch them concurrently:

1. `app/Modules/Identity/Domain/Permission.php` — add cases:
   - `TransportView = 'transport.view'`, `TransportManage = 'transport.manage'`
   - `HostelView = 'hostel.view'`, `HostelManage = 'hostel.manage'`
   - `MedicalView = 'medical.view'`, `MedicalManage = 'medical.manage'`
   - `VisitorManage = 'visitor.manage'`
   - `InsuranceView = 'insurance.view'`, `InsuranceManage = 'insurance.manage'`
2. `app/Modules/Identity/Domain/Role.php` `defaultPermissions()` — replace the empty arm at line 148:
   - `Nurse` → MedicalView, MedicalManage
   - `WelfareOfficer` → all transport/hostel/insurance cases + MedicalView + VisitorManage
   - `FrontDesk` → VisitorManage
   - `Administrator`/`Principal`/`SuperAdmin` arms gain the view permissions (follow existing arm style in that file)
3. `lang/en/opes.php` + `lang/fr/opes.php` permission labels.
4. Empty-shell test dirs `tests/Feature/Welfare/` (nothing else — no routes, no Navigation change; those reference classes that don't exist yet and would fatal).

`RolePermissionSeeder` needs no edit (it iterates the enums).

## 3. Migrations — pre-assigned filenames (series `2026_08_09_250001–250013`)

Next free series after Fees' `2026_08_08_2400xx`. All tables `id` bigint PK, timestamps, standard engine; follow the Fees migrations as templates (e.g. `2026_08_08_240001_create_fee_categories_and_third_party_funds_tables.php`).

| # | Filename | Tables / contents | Agent |
|---|----------|-------------------|-------|
| 1 | `2026_08_09_250001_create_transport_routes_and_stops_tables.php` | `transport_routes` (name, code unique, description, is_active), `transport_stops` (route_id FK, name, sequence, pickup_time, dropoff_time; `UNIQUE(route_id, sequence)`) | W1 |
| 2 | `2026_08_09_250002_create_vehicles_and_drivers_tables.php` | `vehicles` (registration_no unique, make/model, capacity, asset_id nullable **no FK**, status enum, insurance_expires_on, inspection_expires_on), `vehicle_drivers` (vehicle_id, name, licence_no **encrypted-at-model**, phone, user_id nullable, active period) | W1 |
| 3 | `2026_08_09_250003_create_transport_allocations_table.php` | `transport_allocations` (enrollment_id FK→enrollments, route_id, stop_id, direction enum both/pickup/dropoff, starts_on, ends_on nullable, status enum active/ended, allocated_by). Enforce **one active per enrollment** via generated column `active_key` = enrollment_id when status='active' else NULL, `UNIQUE(active_key)` — same NULL-unique trick mandated by the HANDOVER invoice-idempotency fix | W1 |
| 4 | `2026_08_09_250004_create_trip_maintenance_fuel_logs_tables.php` | `vehicle_trip_logs` (vehicle_id, route_id, driver_id, date, odometer_start/end, notes), `vehicle_maintenance_logs` (vehicle_id, date, type, description, cost_amount, supplier ref nullable), `vehicle_fuel_logs` (vehicle_id, date, litres, cost_amount, odometer) — operational records only, no ledger posting | W1 |
| 5 | `2026_08_09_250005_create_hostels_rooms_beds_tables.php` | `hostels` (name, code unique, gender enum, warden_user_id nullable, is_active), `hostel_rooms` (hostel_id, name, capacity; `UNIQUE(hostel_id,name)`), `hostel_beds` (room_id, label, is_active; `UNIQUE(room_id,label)`) | W2 |
| 6 | `2026_08_09_250006_create_hostel_allocations_table.php` | `hostel_allocations` (enrollment_id FK, bed_id FK, starts_on, ends_on nullable, status, allocated_by). Two invariants via generated NULL-unique columns: one active per enrollment AND one active per bed | W2 |
| 7 | `2026_08_09_250007_create_hostel_inspections_table.php` | `hostel_inspections` (hostel_id, room_id nullable, inspected_on, inspected_by, rating enum, findings text, resolved_at nullable) | W2 |
| 8 | `2026_08_09_250008_create_medical_consultations_table.php` | `medical_consultations` (student_id FK→students, enrollment_id nullable, visited_at, presenting_complaint TEXT, diagnosis TEXT, treatment TEXT — all three encrypted at model, severity enum reusing pattern of `MedicalSeverity`, outcome enum returned_to_class/sent_home/referred/admitted, recorded_by) | W3 |
| 9 | `2026_08_09_250009_create_medical_referrals_table.php` | `medical_referrals` (consultation_id FK, referred_to, reason TEXT encrypted, referred_on, followed_up_at nullable, notes encrypted) | W3 |
| 10 | `2026_08_09_250010_create_visitor_logs_table.php` | `visitor_logs` (visitor_name, phone, id_document_ref nullable encrypted, purpose, host_type enum staff/student/office, host_id nullable, badge_no, checked_in_at, checked_out_at nullable, gate_pass_no nullable, logged_by; index on checked_in_at) | W4 |
| 11 | `2026_08_09_250011_create_insurance_policies_table.php` | `insurance_policies` (provider, policy_no unique, cover_type enum student/asset, premium_per_student, coverage_start, coverage_end, academic_year_id FK, asset_id nullable **no FK**, fee_item_id nullable FK→fee_items, status) | W5 |
| 12 | `2026_08_09_250012_create_student_insurances_table.php` | `student_insurances` (enrollment_id FK, policy_id FK, enrolled_on, certificate_no, status enum active/lapsed/cancelled; `UNIQUE(enrollment_id, policy_id)`) | W5 |
| 13 | `2026_08_09_250013_create_insurance_claims_table.php` | `insurance_claims` (policy_id FK, student_insurance_id nullable FK, incident_date, description, amount_claimed, amount_settled nullable, status enum draft/submitted/settled/rejected, settled_on nullable) — claim settlement cash receipt deferred to treasury (add to tracked debts) | W5 |

## 4. Models, Domain enums, Actions (per sub-domain)

All under `app/Modules/Welfare/`. Models follow `StudentMedicalRecord.php` style (`declare(strict_types=1)`, `final`, typed `casts()`, no cross-module Model relations — Welfare models may relate to each other only; enrollment/student data via `DB::table` inside Actions).

**Domain enums** (`Welfare/Domain/`): `AllocationStatus`, `TransportDirection`, `VehicleStatus`, `HostelGender`, `InspectionRating`, `ConsultationSeverity`, `ConsultationOutcome`, `VisitorHostType`, `InsuranceCoverType`, `InsuranceStatus`, `ClaimStatus`.

**Actions** (the only cross-module doors):

- W1: `CreateRoute`, `SaveVehicle`, `AllocateTransport` (validates enrollment active via `DB::table('enrollments')`, ends any prior active allocation atomically), `EndTransportAllocation`, `RecordTripLog`, `RecordFuelLog`, `RecordMaintenanceLog`, `TransportRosterReport` (per-route student list via DB::table join)
- W2: `SaveHostel`, `SaveRoom`, `SaveBeds`, `AllocateBed` (both one-active invariants, capacity check), `EndHostelAllocation`, `RecordInspection`, `OccupancyReport`
- W3: `RecordConsultation`, `RecordReferral`, `CloseReferral`, `MedicalDashboardStats` (feeds 09-ui §Medical dashboard: today's visits, active treatments, referrals)
- W4: `CheckInVisitor`, `CheckOutVisitor` (rejects double check-out; badge_no uniqueness among currently-checked-in)
- W5: `SavePolicy`, `EnrollStudentsInPolicy` (bulk, idempotent on the unique key), `RecordClaim`, `SettleClaim`, `UninsuredStudentsReport` (active enrollments minus active `student_insurances` for the year, via DB::table)

All Actions authorize via `$actor`/`Gate` against the new Permission cases (rule 17: enforced in Actions, not menus) and write the audit log the way Fees Actions do.

## 5. Livewire screens, routes, navigation (integration agent W5, LAST)

Screens (`Welfare/Livewire/`), replicating mockups in `frontend images/`, modelled on `Fees/Livewire/Invoices/Index.php` + `Cashier.php`:

- `Transport\Index` — tabs: Routes/Stops, Vehicles, Allocations, Logs → `/transport`
- `Hostel\Index` — tabs: Hostels/Rooms/Beds, Allocations, Inspections, Occupancy → `/hostel`
- `Medical\Index` (consultation log + record form + dashboard cards) → `/welfare/medical`
- `Visitors\Index` (check-in/out desk) → `/welfare/visitors`
- `Insurance\Index` (policies, enrollment, claims, uninsured report) → `/welfare/insurance`

`routes/web.php` (after line ~148, before the placeholder loop):

- `/transport` → `can:transport.view`; `/hostel` → `can:hostel.view`; `/welfare/medical` → `can:medical.view`; `/welfare/visitors` → `can:visitor.manage`; `/welfare/insurance` → `can:insurance.view` (09-ui.md line 61: medical/visitors/insurance are NOT sidebar items).

`Navigation.php`: flip lines 72–73 to `'permission' => Permission::TransportView / Permission::HostelView, 'built' => true` — `placeholderRoutes()` drops them automatically by construction. `tests/Feature/Ui/ShellTest.php` and `PlaceholderRoutesTest` must stay green after the flip (the exact trap HANDOVER item 5 describes for Phase 6).

## 6. Test list (Pest, real MySQL + RefreshDatabase, `function_exists`-guarded unique helpers)

`tests/Feature/Welfare/`:

| File | Covers | Agent/DB |
|---|---|---|
| `TransportSetupTest.php` | routes/stops/vehicles CRUD, stop sequence uniqueness, permission denials | W1 / `opeschool_test_f1` |
| `TransportAllocationTest.php` | one-active-per-enrollment invariant (DB-level), re-allocation ends prior, inactive-enrollment rejection, roster report | W1 / f1 |
| `VehicleLogsTest.php` | trip/fuel/maintenance logs, odometer sanity | W1 / f1 |
| `HostelSetupTest.php` | hostel/room/bed CRUD, capacity, gender | W2 / `opeschool_test_f2` |
| `HostelAllocationTest.php` | one-active-per-enrollment AND one-active-per-bed, occupancy report, inspections | W2 / f2 |
| `MedicalConsultationTest.php` | record/refer/close, encryption asserted at DB layer (raw column ≠ plaintext), Nurse-only manage, dashboard stats | W3 / `opeschool_test_f3` |
| `VisitorLogTest.php` | check-in/out, double-checkout rejection, FrontDesk permission | W4 / `opeschool_test_f4` |
| `InsuranceTest.php` | policy CRUD, bulk enroll idempotency, claims lifecycle, uninsured report, fee_item_id link | W5 / `opeschool_test_f5` |
| `WelfareScreensTest.php` + Shell/Placeholder regression | all five screens render, nav flip, 403s for unauthorised roles | W5 / f5 |

Plus: `ModuleBoundaryTest` and `LocalisationTest` must stay green (run in each worktree). PHPStan level 8, zero suppressions — type `DB::table` row reads with object-shape `@var` (the exact fix class HANDOVER item 3 required).

## 7. Agent scopes (disjoint; worktrees; exact-path `git add`)

| Agent | Scope | Migrations | Test DB | Shared-file touches |
|---|---|---|---|---|
| W1 Transport | Welfare Transport models/enums/actions/screen(view only, no route) | 250001–250004 | f1 | none |
| W2 Hostel | Hostel models/enums/actions/screen | 250005–250007 | f2 | none |
| W3 Medical | Consultations/referrals + optional `Students\Actions\EmergencyMedicalSummary` (only agent touching Students/) | 250008–250009 | f3 | none |
| W4 Visitors | Visitor log + screen | 250010 | f4 | none |
| W5 Insurance + integration | Insurance domain; then AFTER W1–W4 merge: `routes/web.php`, `Navigation.php`, screen route wiring, Shell regression | 250011–250013 | f5 | routes/web.php, Navigation.php (exclusive) |

Sequencing: Step 0 pre-commit → W1–W4 fully parallel + W5 insurance-domain part parallel → merge all → W5 integration pass → SOLO full suite on `opeschool_test`, PHPStan 0, `composer deploy`, push, verify live at opeschool.test (rules 1, 5, 6). New enum states in Welfare must not require touching Fees; if a boarding-status change needs billing, the operator path is the existing supplementary-invoice/credit-note flow (`04-fees.md` timing rule H) — no Welfare→Fees automation in this phase.

**Tracked debts to append**: asset_id FKs when Phase 9 lands; insurance claim settlement treasury receipt; medical-record retention period N (08-operations line 1511 flags it "NEEDS VERIFICATION — Phase 10", so surface it as a config setting defaulting to 5 years, decision still open); `StudentObligationSource` implementation for welfare damage charges (`04-fees.md` line 1279).

### Critical Files for Implementation
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php
- /home/user/opes-school-soft/app/Modules/Identity/Domain/Permission.php
- /home/user/opes-school-soft/app/Modules/Identity/Domain/Role.php
- /home/user/opes-school-soft/routes/web.php
- /home/user/opes-school-soft/app/Modules/Students/Models/StudentMedicalRecord.php (encryption/model template)