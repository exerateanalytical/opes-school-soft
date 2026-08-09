# Phase 7 Implementation Plan — Operations: Year Rollover Wizard + Licensing

Sources: `/home/user/opes-school-soft/docs/specs/08-operations.md` §4 (licensing) and §6 (rollover), `00-core.md` §8/§12/§17, `HANDOVER.md` standing rules. Backups/health/restore-drill (§3, §7) already exist in `app/Modules/Operations` — Phase 7 adds only rollover + licensing + the "What's open right now" panel (§6.4).

## 1. Architecture decisions

1. **Module home**: everything new lives in `app/Modules/Operations` except cross-module doors it *calls*. Cross-module reads via `DB::table` only; cross-module writes via the owning module's Actions (existing rule). Carry-forward of credit balances (wizard step 7) is a ledger event and MUST route through `Accounting\Actions\PostFromEvent` via a Fees Action — no second posting path.
2. **Undo mechanism**: instead of adding `rollover_run_id` columns to seven tables across five modules (huge cross-module DDL churn), one Operations-owned ledger table `rollover_artifacts (rollover_run_id, entity_type, entity_id, step)` records every created row. Undo walks it in reverse-FK order and refuses once the new year has its first payment/mark/journal entry (checked live via `DB::table`), naming which one closed the window (spec §6.3).
3. **Resumability**: `rollover_runs` row with `current_step`, JSON `step_states`, `inputs_hash`, `status`, `UNIQUE(academic_year_from_id, academic_year_to_id)`. Each step Action is idempotent (re-validates, skips already-created artifacts by natural key).
4. **Promotion decisions (step 6 dependency)**: Phase 8 owns the full promotion module, but rollover needs decisions now. Add a minimal `promotion_decisions` table owned by **Students** (`enrollment_id`, `decision: promoted|repeat|graduated|withdrawn`, `target_class_group_key`, `decided_by`, unique per enrollment) plus a `Students\Actions\RecordPromotionDecision` door. The wizard's step-6 screen is a per-class-group decision grid writing through that Action; the rollover step refuses while any active enrollment in the outgoing year is undecided. Phase 8 builds on this table, not around it.
5. **Licensing**: `Operations\Licensing\` namespace. Cached licence in a `licences` table (payload JSON, signature, fingerprint, source `file|activation`, `expires_at`, `next_check_after`, `grace_days`, `revoked_at`). Two embedded public keys in `config/opes.php` (`licence_file_public_key` ECDSA P-256, `activation_public_key` RSA-2048); tests generate throwaway pairs in memory (openssl ext, already required). Canonical-JSON verifier per §4.3. **No network in any status check**; the only HTTP call is `ActivateOnline`, and the opportunistic re-check fires solely from the Licence panel.
6. **Entitlement gate**: `Operations\Actions\AssertEntitlement` (the cross-module door). Called at the top of `Academics\Actions\CreateAcademicYear`, `Assessment\Actions\PublishPeriod`, and `Operations\Actions\Rollover\StartRolloverRun`. Pest asserts these refuse when expired-enforced, and asserts `Fees\Actions\RecordPayment` etc. do **not** reference the gate (architecture test greps imports). States: `valid | trial | expiring | grace | enforced | revoked` per the §4.4 table; trial = 30 days or 25 students.
7. **Wizard UX**: follow `Admissions\Livewire\Wizard` conventions exactly — `#[Layout('layouts.app')]`, `#[Url]` run id for resume, validation lives in the Actions, Gate check in `mount()` *and* every write, view under `resources/views/livewire/operations/rollover-wizard.blade.php`. Every step renders a preview diff (counts + row list under 200) before its Apply button; step 0 blocks on a verified backup via existing `Operations\Actions\CreateBackup`/`VerifyBackup`.

## 2. Pre-assigned migration filenames (series `2026_08_09_250001–250008`)

Follow the repo's existing one-table-per-file convention (as the Fees series did):

| File | Table |
|---|---|
| `2026_08_09_250001_create_rollover_runs_table.php` | `rollover_runs` (from/to year ids, unique pair, current_step, step_states JSON, inputs_hash, status enum `running|completed|undone|failed`, operator_id, backup_id FK, timestamps) |
| `2026_08_09_250002_create_rollover_artifacts_table.php` | `rollover_artifacts` (run_id FK, entity_type string, entity_id, step tinyint, unique(run_id, entity_type, entity_id), index(entity_type, entity_id)) |
| `2026_08_09_250003_create_promotion_decisions_table.php` | `promotion_decisions` (enrollment_id unique FK, decision enum, target string nullable, decided_by, decided_at) |
| `2026_08_09_250004_create_licences_table.php` | `licences` |
| `2026_08_09_250005_create_rollover_balance_carries_table.php` | per-student step-7 outcomes (run_id, student_id, kind `credit_carry|debt_carry|write_off|block`, amount int, journal_entry ref nullable) |
| `2026_08_09_250006_add_rollover_columns_to_academic_years_table.php` | reserved — only if `closed` status/`closed_at` gap found; otherwise leave unused |
| `2026_08_09_250007_reserved.php` / `2026_08_09_250008_reserved.php` | spare, F1 may claim with parent-agent notice |

## 3. New files by component

**Models** (`app/Modules/Operations/Models/`): `RolloverRun`, `RolloverArtifact`, `RolloverBalanceCarry`, `Licence`; `app/Modules/Students/Models/PromotionDecision`. Factories under `database/factories/`.

**Domain**: `Operations/Domain/RolloverStep.php` (int-backed enum 0–10 with labels/guards), `RolloverRunStatus`, `Licensing/LicenceState`, `Licensing/EntitlementDecision`.

**Rollover Actions** (`Operations/Actions/Rollover/`): `StartRolloverRun` (step 0 preflight: verified backup, no unpublished periods — `DB::table('assessment_periods')`, no open cash desk, no draft entries), `CreateNewYearStep` (delegates to `Academics\Actions\CreateAcademicYear` — contiguity already enforced there), `CopyClassGroupsStep`, `CopySubjectAllocationsStep`, `CopyAssessmentPeriodsStep` (re-validate Σweights), `CopyFeeStructuresStep` (uplift %; delegates to a new `Fees\Actions\CloneFeeStructureForYear`; residual-to-last-instalment per 00-core §7.3), `PromoteStudentsStep` (consumes `promotion_decisions`, calls `Students\Actions\EnrollStudent`), `CarryBalancesStep` (credit carries via new `Fees\Actions\CarryForwardStudentCredit` → `PostFromEvent`; never nets across students), `ArchiveLeaversStep`, `ReassignTeachersStep`, `FlipActiveYearStep` (delegates to `Academics\Actions\SetCurrentAcademicYear`), `PreviewStep` (dry-run diff per step), `UndoRollover`.

**Licensing** (`Operations/Licensing/` + `Operations/Actions/Licensing/`): `CanonicalJson`, `LicenceVerifier` (both key types), `MachineFingerprint` (`SHA-256("opes-machine-fingerprint-v1|"+source)`, empty-never-random), `LicenceStatus` service (offline evaluation, entitlement state machine), Actions `ImportLicenceFile`, `ActivateOnline`, `DeactivateLicence` (unconditional local clear + seat message), `OpportunisticRecheck` (clears only on `revoked|invalid_key`), `AssertEntitlement`. Distinct EN/FR failure strings in `lang/en/licence.php`, `lang/fr/licence.php` + test that no two collapse.

**Livewire + views**: `Operations/Livewire/RolloverWizard.php` (+ `rollover-wizard.blade.php`), `Operations/Livewire/LicencePanel.php` (+ view), `Operations/Livewire/WhatsOpenPanel.php` (§6.4 panel; reads active year, exercice, period lock states via `DB::table`) embedded on the dashboard view.

**Wiring** (single-owner files, F5 only): `Identity/Domain/Permission.php` add `RolloverRun = 'rollover.run'` (LicenceManage, BackupRun/Restore already exist); `Identity/Domain/Role.php` defaults (Administrator/SuperAdmin get rollover.run + licence.manage); `Identity/Support/Navigation.php` (settings item stays; rollover reachable from Academic Settings and a new `operations` entry if mockups show one — F5 verifies against `frontend images/`); `routes/web.php`: `/operations/rollover` (`can:rollover.run`), `/settings/licence` (`can:licence.manage`); `config/opes.php` public-key entries; `RolePermissionSeeder` needs no change (enum-driven).

## 4. Agent scopes (5 parallel, disjoint files, per-agent DBs)

| Agent | DB | Owns (writes only these) |
|---|---|---|
| **F1 — Schema & models** | `opeschool_test_f1` | Migrations 250001–250005, all 5 models + factories, domain enums, `tests/Feature/Operations/RolloverSchemaTest.php`. Delivers first; F2–F4 branch from its commit. |
| **F2 — Rollover engine A (steps 0–5, 10, undo)** | `opeschool_test_f2` | `StartRolloverRun`, copy steps 1–5, `FlipActiveYearStep`, `PreviewStep`, `UndoRollover`, `Fees\Actions\CloneFeeStructureForYear`, tests `tests/Feature/Operations/RolloverStepsTest.php`, `RolloverUndoTest.php`, `RolloverResumeTest.php` (kill-and-restart idempotency). |
| **F3 — Rollover engine B (people & money, steps 6–9)** | `opeschool_test_f3` | `Students\Actions\RecordPromotionDecision`, `PromoteStudentsStep`, `CarryBalancesStep`, `Fees\Actions\CarryForwardStudentCredit` (PostFromEvent only), `ArchiveLeaversStep`, `ReassignTeachersStep`, tests `RolloverPromotionTest.php`, `RolloverBalancesTest.php` (asserts one posting path, no cross-student netting). |
| **F4 — Licensing** | `opeschool_test_f4` | Entire `Operations/Licensing/*` + licensing Actions, `AssertEntitlement`, gate call-site edits in `Academics\Actions\CreateAcademicYear` and `Assessment\Actions\PublishPeriod` (F4 exclusively owns those two edits — one added guard line each), lang files, `LicencePanel` Livewire + view, tests `LicenceVerificationTest.php`, `EntitlementGateTest.php` (incl. "36 offline months = zero network calls" and the never-gated Action assertions), architecture test additions. |
| **F5 — UI & wiring** | `opeschool_test_f5` | `RolloverWizard` Livewire + blade (pixel-faithful to mockups), `WhatsOpenPanel` + dashboard view edit, `routes/web.php`, `Navigation.php`, `Permission.php`, `Role.php`, `config/opes.php`, `tests/Feature/Operations/RolloverWizardScreenTest.php`, keeps `tests/Feature/Ui/ShellTest.php` green. |

Rules restated for all agents: git worktrees only, exact-path `git add`, `function_exists`-guarded globally-unique Pest helpers, PHPStan level 8 zero suppressions, RefreshDatabase on real MySQL, no two suites on one DB, draft→lines→post ledger order, `Carbon::parse()` not `create()`, `(int)` casts on SUM.

Sequencing: F1 merges first (schema is the shared contract; publish exact column lists in the PR body). F2/F3 share the `RolloverRun`/artifact contract but disjoint Action files; F5 stubs against F1's models and F2/F3's Action class names (agreed signatures above). F4 is fully independent except the two one-line gate insertions.

## 5. Key risks

- **Gate call-site collision**: only F4 touches `CreateAcademicYear`/`PublishPeriod`; F2 *calls* `CreateAcademicYear` but does not edit it. Entitlement in a valid/trial state must be the test-fixture default or 1000+ existing tests break — `AssertEntitlement` must treat "no licence row + within trial window" as permissive, and the trial clock must be seedable (use `App\Support\Clock`).
- **`closed` year semantics** (step 10 guard) — verify `AcademicYearStatus` cases before F1 finalizes; migration 250006 is the escape hatch.
- **Cash-desk/open-session check in step 0**: Fees has no cash-desk session table yet — implement the guard against draft journal entries + unpublished periods only, and document the deferred check.
- Fee structure child tables (structures/plans/lines across `240003–240004`) make `CloneFeeStructureForYear` the trickiest copy — it must live in Fees (own-module models) and be invoked by Operations.

### Critical Files for Implementation
- /home/user/opes-school-soft/docs/specs/08-operations.md
- /home/user/opes-school-soft/app/Modules/Academics/Actions/CreateAcademicYear.php
- /home/user/opes-school-soft/app/Modules/Admissions/Livewire/Wizard.php
- /home/user/opes-school-soft/app/Modules/Identity/Support/Navigation.php
- /home/user/opes-school-soft/app/Modules/Identity/Domain/Permission.php