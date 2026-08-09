# Phase 11 — HR & Payroll (CNPS/IRPP) Implementation Plan

Source of truth: `/home/user/opes-school-soft/docs/specs/05-hr-payroll.md` (v2.0), bound by `docs/specs/00-core.md`. All statutory rates SHIP EMPTY (§0); preflight blocks runs until configured. Reference values (§2.3) appear only in `@statutory-reference` test fixtures, never in seeders.

## Conventions confirmed from the codebase

- Rates: `App\Support\Rate\Rate` uses `SCALE = 100_000` (4.2% = 4200). The spec's `/1,000,000` prose is superseded by 00-core §7.2 + existing `Rate` helper — **use `Rate::SCALE` everywhere**; do not introduce a second scale.
- Money splits: `App\Support\Money\Allocator::allocate` (largest remainder, exact-sum guarantee) for `StaffCostAllocation`.
- All ledger writes via `App\Modules\Accounting\Actions\PostFromEvent`; the `PostingEvent` enum already contains `payroll.approved`, `payroll.paid`, `payroll.reversed`, `payroll.leave_provision(.reversed)`, `payroll.settlement.final` — Phase 11 only adds `PostingRule` seeds/config and payloads, no new posting path.
- Formula grammar (§5.4): reuse `app/Support/Expression` (Lexer/Parser/Evaluator with `min`/`max` `CallNode`) with a Payroll-specific fixed variable whitelist wrapper.
- Modules `app/Modules/HR` and `app/Modules/Payroll` exist as skeletons (`.gitkeep` in Actions/Models/Livewire/etc.); `HR\Models\StaffMember` is a thin directory record that must be extended (encrypted IDs, blind indexes, CNPS fields) — extend via migration + model rewrite, keeping `staff_no` and existing tests (`tests/Feature/HR/StaffMemberTest.php`) green.
- Permission enum: `app/Modules/Identity/Domain/Permission.php`; nav flip in `app/Modules/Identity/Support/Navigation.php` (`staff` key currently `built => false`; remove from `placeholderRoutes()` when flipping). Routes pattern per `/finance/*` block in `routes/web.php`.
- Cross-module reads by `DB::table` only (ModuleBoundaryTest); Actions are the only cross-module doors.
- Migration filenames pre-assigned; Phase-11 series is `2026_08_09_250001..2500xx` (accounting used `2300xx`, fees `2400xx`).

## Migrations (pre-assigned filenames, `database/migrations/`)

| # | File | Contents |
|---|---|---|
| 250001 | `2026_08_09_250001_extend_staff_members_table.php` | §3.3: encrypted `national_id_number`/`cnps_number`/bank columns + BINARY(32) blind indexes (unique), `cnps_registration_status/registered_on/registration_deadline`, `niu`, `marital_status`, next-of-kin, `photo_document_id`, `is_archived`, `version`; drop nothing that existing tests use |
| 250002 | `2026_08_09_250002_create_departments_positions_salary_grades_tables.php` | `departments`, `positions`, `salary_grades`, `communes` (TDL payee), `collective_agreements` (empty per §2.4) |
| 250003 | `2026_08_09_250003_create_employer_profiles_table.php` | §3.1 incl. overlap-checked effective dating, NOT NULL `cnps_notification_document_id`, nullable-blocking `proration_basis`, `ceiling_prorates_partial_month`, `irpp_mode` default `ytd_cumulative` |
| 250004 | `2026_08_09_250004_create_staff_contracts_tables.php` | `staff_contracts` (§3.4 with `active_role_key` STORED GENERATED + `uq_active_contract_role`, CHECKs), `staff_contract_exemptions` (§3.5), `staff_dependants` (§3.6) |
| 250005 | `2026_08_09_250005_create_staff_assignment_tables.php` | `staff_assignments`, `staff_appraisals` (+criteria rows), `staff_discipline_cases`, `staff_cost_allocations` (§3.7) |
| 250006 | `2026_08_09_250006_create_statutory_rates_table.php` | §4.2 full schema, CHECKs 1–8 (incl. `code<>'RP' OR ceiling IS NULL`), BEFORE UPDATE/DELETE triggers enforcing the `locked` append-only rule (§4.4) |
| 250007 | `2026_08_09_250007_seed_statutory_rate_shells.php` | Shell rows for PVID/PF(×3 regimes)/RP(×3 classes)/IRPP/CAC/CFC/FNE/RAV/TDL with **NULL amount columns**, `is_verified=false`, `source_citation` metadata only |
| 250008 | `2026_08_09_250008_create_payroll_components_table.php` | §5.2 + seed the `is_system` component set (§5.3) with `calculation_order`, accounts NULL until configured |
| 250009 | `2026_08_09_250009_create_staff_compensations_and_hourly_tables.php` | `staff_compensations` (§5.1), `hourly_rates` (§5.5 XOR scope), `payroll_component_tests` (stored formula unit tests, §5.4) |
| 250010 | `2026_08_09_250010_create_timesheet_tables.php` | `teaching_hours_logs`, `timesheets` (§5.5) |
| 250011 | `2026_08_09_250011_create_payroll_runs_table.php` | §8.1 incl. generated-column active-unique `(payroll_month, run_type, employer_profile_id)`, `inputs_hash`, `reverses_run_id` UNIQUE, `idempotency_key` |
| 250012 | `2026_08_09_250012_create_payroll_items_and_lines_tables.php` | `payroll_items` (two CNPS bases, YTD cols, cross-run `UNIQUE(payroll_month, staff_member_id)` via generated column excluding cancelled), `payroll_lines` (§10.2), `deduction_carry_forwards` (§5.7) |
| 250013 | `2026_08_09_250013_create_payroll_item_snapshots_table.php` | INSERT-only, BEFORE UPDATE/DELETE triggers reject unconditionally (§10.2) |
| 250014 | `2026_08_09_250014_create_payroll_preflight_results_table.php` | §9.1 persisted checklist |
| 250015 | `2026_08_09_250015_create_payroll_payments_tables.php` | `payroll_payments`, `payroll_payment_lines` (§8.8) |
| 250016 | `2026_08_09_250016_create_statutory_declarations_tables.php` | `statutory_declarations` (§11.1), `work_accidents`, `cnps_benefit_claims` (§11.5–11.6), `dipe_layouts` (unpopulated definition object, §11.4) |
| 250017 | `2026_08_09_250017_create_leave_tables.php` | `leave_types` (seeded WITHOUT `statutory_days`), `leave_accruals` (append-only + triggers), `leave_requests` (§12.2) |
| 250018 | `2026_08_09_250018_create_termination_settlements_table.php` | §13.1 |

## Models

`app/Modules/HR/Models/`: StaffMember (rewrite), StaffContract, StaffContractExemption, StaffDependant, Department, Position, SalaryGrade, StaffAssignment, StaffAppraisal, StaffDisciplineCase, StaffCostAllocation, LeaveType, LeaveAccrual, LeaveRequest, TeachingHoursLog, Timesheet, TerminationSettlement, WorkAccident.

`app/Modules/Payroll/Models/`: EmployerProfile, StatutoryRate, PayrollComponent, StaffCompensation, HourlyRate, PayrollRun, PayrollItem, PayrollLine, PayrollItemSnapshot (guarded, insert-only), PayrollPreflightResult, PayrollPayment, PayrollPaymentLine, DeductionCarryForward, StatutoryDeclaration, CnpsBenefitClaim.

Cross-module boundary: Payroll reads HR contract/compensation data via Actions or `DB::table` per ModuleBoundaryTest — decide up front: HR owns staff/contract/leave/timesheet; Payroll owns rates/components/runs/declarations; Payroll reads HR through `DB::table` queries inside its Actions.

## Domain (pure, no numeric literals — architecture test per §4.3)

`app/Modules/Payroll/Domain/`:
- `StatutoryRateResolver` (§4.3 — period-END dated, throws `StatutoryRateUnresolved`/`StatutoryRateAmbiguous`)
- `IrppEngine` — exact rational arithmetic (int numerator/denominator or BCMath), both modes: `annualised` (§6.3, ANNUALISE→BRACKETS→/12→ROUND ONCE) and `ytd_cumulative` (§6.5 default, clamp at 0, December regularisation residual)
- `CnpsBases` — `cnps_capped_base = min(SBC, ceiling)`, `cnps_uncapped_base = SBC` (N1)
- `ComponentGraph` — Kahn's DAG validation, order-200 bases barrier (§7.2)
- `PayrollFormula` — wrapper over `App\Support\Expression` restricting variables to §5.4 set, parse-at-save
- `Proration` (§8.5), `DeductionCap` (§5.7), enums (RunType, RunStatus, ContractType, SocialSecurityStatus, DeclarationType, …)

## Actions

HR: `HireStaffMember` (sets CNPS deadline = hire+8d), `OpenStaffContract` (CDD invariant, `CddLimitExceeded`, MINTSS visa check), `TerminateContract` (settlement draft + departure declaration + documents), `SaveCostAllocation` (Σ=100% invariant), `RequestLeave`/`ApproveLeave` (overlap check, `taken` accrual row), `AccrueMonthlyLeave` (idempotent scheduled), `ValidateTimesheet`, `SeedTeachingHoursFromTimetable`.

Payroll: `ConfigureEmployerProfile` (wizard step, audit-logged confirmation), `ConfigureStatutoryRate` / `CloseAndSupersedeRate` (§4.4 — only mutation path for locked rows), `SavePayrollComponent` (formula parse + stored test execution), `PayrollPreflightCheck` (checks 1–15, persisted results), `CalculatePayrollRun` (locking §8.2, preflight, component-order execution, arrears generation, gross−deductions=net assert, `inputs_hash`), `ApprovePayrollRun` (hash re-verify, `calculated_by <> approved_by`, write snapshots, lock rates, **PostFromEvent('payroll.approved', …)** with `StaffCostAllocation` analytic split), `ReversePayrollRun` (new `reversal` run, contrepassation in earliest open period, mirrors Fees `VoidPayment` pattern), `PreparePayrollPayment`/`ExportDisbursementFile` (+ `PostFromEvent('payroll.paid')`), `GenerateStatutoryDeclarations` (compliance calendar job), `ExportDipe` (disabled until `DipeLayout` populated; snapshot-only reads), `PostLeaveProvision` (reports-only until 66x/428x confirmed → `ProvisionAccountsUnconfigured`), `RecordCnpsBenefitClaim`, `ComputeTerminationSettlement` (manual amounts + `basis_note` while schedule unverified).

## Livewire screens, routes, navigation, permissions

Screens (`app/Modules/HR/Livewire`, `app/Modules/Payroll/Livewire` + `resources/views/livewire/{hr,payroll}`), per §15:
1. `Hr\StaffIndex` + `Hr\StaffDossier` (tabs incl. Leave Balance from ledger SUM, Payroll History from snapshots)
2. `Payroll\RunManager` (period selector, KPI row, preflight checklist gating the Process button)
3. `Payroll\TaxRates` (effective-dating UI, §9.2 non-dismissible "Not configured — payroll is blocked" empty states, Close-and-supersede)
4. `Hr\TimesheetValidation`
5. `Payroll\ComplianceCalendar`
6. Wizard step: EmployerProfile confirmation (blocking).

Routes (`routes/web.php`): `/staff` → StaffIndex (`can:staff.view`), `/staff/{staffMember}` dossier, `/payroll` → RunManager (`can:payroll.view`), `/payroll/rates` (`can:payroll.configure`), `/payroll/timesheets`, `/payroll/compliance`. Navigation: flip `staff` to `built => true` with `Permission::StaffView`; add `payroll` item under finance group; both removed from `placeholderRoutes()`; `tests/Feature/Ui/ShellTest.php` must stay green.

Permission enum additions + RolePermissionSeeder: `staff.view`, `staff.manage`, `leave.approve`, `timesheet.validate`, `payroll.view`, `payroll.run`, `payroll.approve`, `payroll.reverse`, `payroll.configure`, `payroll.pay`, `payroll.override_risk_class`, `payroll.classify_non_employee`, `declaration.file`.

## Test list (Pest, real MySQL + RefreshDatabase, `function_exists`-guarded unique helpers)

`tests/Feature/HR/`: StaffContractTest (CDD limit, active-role unique, exemptions), StaffCompensationTest (overlap, arrears), LeaveLedgerTest (append-only triggers, overlap, accrual idempotency, balance=SUM), TimesheetTest, CostAllocationTest, TerminationTest.
`tests/Feature/Payroll/`: StatutoryRateTest (XOR/RP-ceiling CHECKs, resolver, locked-row trigger, 10-year sweep property), IrppGoldenTest (§6.3 bracket boundaries both directions; Examples A–D to the franc; N4 regression; A30 cap regression), CnpsBaseTest (N1: RP on 900,000 uncapped; N2: PF `enseignement_prive`), YtdEquivalenceTest (§6.5 flat-pay m=1..12), PreflightTest (each check code refuses, writes nothing), PayrollRunTest (lifecycle, inputs_hash approve failure, seg-of-duties, cross-run unique, gross−ded=net invariant, posting via PostFromEvent — single posting path asserted), ReversalTest, SnapshotTest (§10.3 mutate-and-re-render authoritative), ProrationTest, HourlyPayrollTest, DeclarationTest (calendar, DIPE disabled until layout), LeaveProvisionTest, PayrollScreensTest, SeederRefusalTest (zero non-null rate amounts after `db:seed`). Architecture: no dependants identifier in IRPP path (N3), no numeric literal under `Payroll/Domain`, bases-barrier ordering.

## Parallel agent scopes (disjoint; worktrees; per-agent test DB)

| Agent | DB | Scope (files owned exclusively) | Migrations |
|---|---|---|---|
| **F1 HR core** | `opeschool_test_f1` | StaffMember extension, contracts, dependants, assignments, cost allocations, HR models/actions, HR tests | 250001–250005 |
| **F2 Rates & components** | `opeschool_test_f2` | StatutoryRate (+triggers, shells), PayrollComponent, StaffCompensation, HourlyRate, formula wrapper, resolver, Domain enums, rate/component tests | 250006–250010 |
| **F3 Run engine** | `opeschool_test_f3` | IrppEngine, CnpsBases, ComponentGraph, Proration, Preflight, Calculate/Approve/Reverse, snapshots, posting payloads + PostingRule seeds, golden/property/run tests | 250011–250014 |
| **F4 Declarations, leave, termination** | `opeschool_test_f4` | Declarations, DIPE scaffolding, benefit claims, leave tables/actions/provision, termination, payments/disbursement, their tests | 250015–250018 |
| **F5 UI & wiring** | `opeschool_test_f5` | Livewire screens + blades, routes, Navigation flip, Permission enum + seeder, ShellTest, screen tests | none |

Sequencing: F1 and F2 first (F3 depends on both schemas; F3 can stub against migration files immediately since filenames are pre-assigned); F4 depends on F3's run tables (250011–13) existing — coordinate via merged migrations, not shared code; F5 last-merge. Exact-path `git add`; PHPStan level 8 zero suppressions; solo full suite + `composer deploy` at the end. Acceptance gate: ~20 real anonymised payslips reproduced to the franc (§16) — cannot pass until the customer supplies gate-8 rates; the deliverable is a system that correctly *refuses* until then.

### Critical Files for Implementation
- /home/user/opes-school-soft/docs/specs/05-hr-payroll.md
- /home/user/opes-school-soft/app/Modules/Accounting/Actions/PostFromEvent.php
- /home/user/opes-school-soft/app/Support/Expression/Parser.php (with Rate.php, Money/Allocator.php)
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php
- /home/user/opes-school-soft/routes/web.php