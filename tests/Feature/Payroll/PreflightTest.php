<?php

declare(strict_types=1);

use App\Modules\Payroll\Actions\CalculatePayrollRun;
use App\Modules\Payroll\Actions\PayrollPreflightCheck;
use App\Modules\Payroll\Domain\PreflightCheckCode;
use App\Modules\Payroll\Domain\PreflightFailed;
use App\Modules\Payroll\Domain\PreflightStatus;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__.'/P11RunHelpers.php';

/*
 * docs/specs/05-hr-payroll.md 9.1: the fifteen preflight checks. Every
 * check is FATAL except UnfiledPriorDeclarations, which warns. Each test
 * here breaks exactly the ONE precondition its check enforces and asserts
 * that check's code is among the refusal's failures - the checklist itself
 * is called directly (`PayrollPreflightCheck::handle`) so each scenario is
 * isolated from the others' preconditions.
 */

if (! function_exists('p11pfMonth')) {
    function p11pfMonth(): string
    {
        return '2031-01-01';
    }
}

if (! function_exists('p11pfDraftRun')) {
    /**
     * A bare draft PayrollRun row for the calendar p11runCalendar() builds,
     * pointed at the given profile - built directly (not through
     * CalculatePayrollRun::openRun) so a scenario check 1 exists to catch
     * (a profile that no longer covers the period) can even be constructed.
     */
    function p11pfDraftRun(EmployerProfile $profile): PayrollRun
    {
        $month = p11pfMonth();

        $fiscalYearId = DB::table('fiscal_years')
            ->where('starts_on', '<=', $month)
            ->where('ends_on', '>=', $month)
            ->value('id');

        $academicYearId = DB::table('academic_years')->value('id');

        $accountingPeriodId = DB::table('accounting_periods')
            ->where('period_month', $month)
            ->value('id');

        return PayrollRun::query()->create([
            'payroll_month' => $month,
            'run_type' => RunType::Regular->value,
            'status' => RunStatus::Draft->value,
            'fiscal_year_id' => $fiscalYearId,
            'academic_year_id' => $academicYearId,
            'accounting_period_id' => $accountingPeriodId,
            'employer_profile_id' => $profile->getKey(),
        ]);
    }
}

if (! function_exists('p11pfAssertCheck')) {
    /**
     * Runs the checklist and asserts the named check has the given status -
     * and that the WHOLE checklist persisted (9.1: the refusal survives as
     * a checklist, not a stack trace).
     */
    function p11pfAssertCheck(PayrollRun $run, PreflightCheckCode $code, PreflightStatus $expected): void
    {
        $outcome = app(PayrollPreflightCheck::class)->handle($run);

        $row = collect($outcome['results'])->first(
            fn (App\Modules\Payroll\Models\PayrollPreflightResult $r): bool => $r->check_code === $code,
        );

        if ($row === null) {
            throw new RuntimeException("Check {$code->value} did not persist a result row.");
        }

        expect($row->status)->toBe($expected);

        if ($expected === PreflightStatus::Fail && $code->isFatal()) {
            expect($outcome['failed'])->toContain($code);
        }

        expect(DB::table('payroll_preflight_results')->where('payroll_run_id', $run->getKey())->count())
            ->toBe(count($outcome['results']));
    }
}

it('check 1 - EMPLOYER_PROFILE_MISSING fails when no profile covers the period end', function (): void {
    $user = p11runActor();
    p11runCalendar();

    // A profile that exists but only starts AFTER the payroll month.
    $profile = p11runEmployerProfile(['effective_from' => '2032-01-01']);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::EmployerProfileMissing, PreflightStatus::Fail);
});

it('check 2 - EMPLOYER_REGIME_UNCONFIRMED fails when the risk class is blank', function (): void {
    p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    $profile->forceFill(['rp_risk_class' => ''])->save();

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::EmployerRegimeUnconfirmed, PreflightStatus::Fail);
});

it('check 3 - PRORATION_CONVENTION_UNCONFIGURED fails when a partial month exists and no convention is set', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();

    // A mid-month starter: the profile's proration_basis stays NULL.
    p11runStaff(200_000, $user, from: '2031-01-15');

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::ProrationConventionUnconfigured, PreflightStatus::Fail);
});

it('check 4 - STATUTORY_RATE_RESOLUTION fails when an enabled statutory component has no verified rate', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    // No p11runReferenceRates(): every rate row ships as an unverified
    // NULL-amount shell (05-hr-payroll §0).
    p11runStaff(200_000, $user);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::StatutoryRateResolution, PreflightStatus::Fail);
});

it('check 5 - STATUTORY_BAND_COVERAGE fails when RAV/TDL bands are unconfigured', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();

    // The percentage/bracket rows, but WITHOUT the RAV/TDL zero-bands
    // p11runReferenceRates() normally adds.
    $base = ['effective_from' => '2024-01-01', 'is_verified' => true, 'source_citation' => 'Test fixture'];
    App\Modules\Payroll\Models\StatutoryRate::factory()->create($base + [
        'code' => 'PVID', 'shape' => 'percentage', 'basis' => 'cnps_capped',
        'employee_rate_bp' => 4_200, 'employer_rate_bp' => 4_200, 'ceiling_amount' => 750_000,
    ]);

    p11runStaff(200_000, $user);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::StatutoryBandCoverage, PreflightStatus::Fail);
});

it('check 6 - IRPP_BRACKET_COVERAGE fails when the annual bracket table has a gap', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();

    $base = ['effective_from' => '2024-01-01', 'is_verified' => true, 'source_citation' => 'Test fixture'];

    // Two brackets with a gap between 2,000,000 and 3,500,000.
    App\Modules\Payroll\Models\StatutoryRate::factory()->create($base + [
        'code' => 'IRPP', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
        'bracket_basis' => 'annual', 'band_from' => 0, 'band_to' => 2_000_000,
        'employee_rate_bp' => 10_000,
    ]);
    App\Modules\Payroll\Models\StatutoryRate::factory()->create($base + [
        'code' => 'IRPP', 'shape' => 'progressive_bracket', 'basis' => 'sbt',
        'bracket_basis' => 'annual', 'band_from' => 3_500_000, 'band_to' => null,
        'employee_rate_bp' => 35_000,
    ]);

    p11runStaff(200_000, $user);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::IrppBracketCoverage, PreflightStatus::Fail);
});

it('check 7 - FORMULA_TESTS fails when an enabled formula component has no stored unit test', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    p11runStaff(200_000, $user);

    // THIRTEENTH ships disabled precisely because its divisor is a
    // reference value (2.3); enabling it without a stored test is exactly
    // what check 7 exists to catch.
    PayrollComponent::query()->where('code', 'THIRTEENTH')->update(['is_enabled' => true]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::FormulaTests, PreflightStatus::Fail);
});

it('check 8 - CNPS_NUMBER_MISSING fails for an affilie_cnps staff member with no CNPS number on file', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();

    // A staff member WITHOUT the cnps_number the p11runStaff() helper sets.
    $member = App\Modules\HR\Models\StaffMember::factory()->create();
    $contract = App\Modules\HR\Models\StaffContract::factory()->create([
        'staff_member_id' => $member->id,
        'starts_on' => '2030-01-01',
        'seniority_reference_date' => '2030-01-01',
    ]);
    DB::table('staff_compensations')->insert([
        'staff_contract_id' => $contract->id, 'component_code' => 'BASIC', 'amount' => 200_000,
        'rate_bp' => null, 'effective_from' => '2030-01-01', 'effective_to' => null,
        'retroactive_from' => null, 'granted_by' => $user->id, 'grant_reason' => 'Test fixture',
        'document_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::CnpsNumberMissing, PreflightStatus::Fail);
});

it('check 9 - TIMESHEET_NOT_VALIDATED fails for hourly staff with no validated hours', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();

    $member = App\Modules\HR\Models\StaffMember::factory()->create();
    App\Modules\HR\Models\StaffContract::factory()->hourly()->create([
        'staff_member_id' => $member->id,
        'starts_on' => '2030-01-01',
        'seniority_reference_date' => '2030-01-01',
    ]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::TimesheetNotValidated, PreflightStatus::Fail);
});

it('check 10 - DAYS_WORKED_UNAVAILABLE fails when the convention derives zero worked days', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile([
        'proration_basis' => 'working_days',
        'ceiling_prorates_partial_month' => true,
    ]);
    p11runReferenceRates();

    // A one-day contract slice that lands on a Sunday - jours ouvrables
    // excludes it, so working_days derives zero for BOTH days_worked and
    // (because the slice is one calendar day) the member is genuinely
    // unworkable under this convention.
    $sunday = Carbon::parse('2031-01-01');
    while (! $sunday->isSunday()) {
        $sunday->addDay();
    }

    $member = App\Modules\HR\Models\StaffMember::factory()->create();
    $contract = App\Modules\HR\Models\StaffContract::factory()->create([
        'staff_member_id' => $member->id,
        'starts_on' => $sunday->toDateString(),
        'ends_on' => $sunday->copy()->addDay()->toDateString(),
        'seniority_reference_date' => $sunday->toDateString(),
    ]);
    $member->forceFill(['cnps_number' => 'CNPS-'.$member->id])->save();
    DB::table('staff_compensations')->insert([
        'staff_contract_id' => $contract->id, 'component_code' => 'BASIC', 'amount' => 200_000,
        'rate_bp' => null, 'effective_from' => $sunday->toDateString(), 'effective_to' => null,
        'retroactive_from' => null, 'granted_by' => $user->id, 'grant_reason' => 'Test fixture',
        'document_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::DaysWorkedUnavailable, PreflightStatus::Fail);
});

it('check 11 - DEDUCTION_CAP_UNCONFIGURED fails when a cappable deduction is present and the cap table is empty', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    $staff = p11runStaff(200_000, $user);

    $now = now();
    DB::table('payroll_components')->insert([
        'code' => 'LOAN', 'name' => 'Staff loan repayment', 'name_fr' => null,
        'type' => 'employee_deduction', 'calculation' => 'fixed', 'basis' => null,
        'statutory_rate_code' => null, 'formula_expression' => null,
        'calculation_order' => 610, 'depends_on' => '[]',
        'is_taxable' => false, 'is_cnps_liable' => false, 'is_prorated' => false,
        'subject_to_deduction_cap' => true,
        'expense_account_id' => null, 'liability_account_id' => null,
        'analytic_axis_behaviour' => 'none', 'print_group' => null, 'print_order' => 0,
        'is_enabled' => true, 'is_system' => false,
        'effective_from' => '2024-01-01', 'effective_to' => null,
        'version' => 0, 'created_at' => $now, 'updated_at' => $now,
    ]);

    DB::table('staff_compensations')->insert([
        'staff_contract_id' => $staff['contract']->id, 'component_code' => 'LOAN', 'amount' => 10_000,
        'rate_bp' => null, 'effective_from' => '2030-01-01', 'effective_to' => null,
        'retroactive_from' => null, 'granted_by' => $user->id, 'grant_reason' => 'Test fixture',
        'document_id' => null, 'version' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::DeductionCapUnconfigured, PreflightStatus::Fail);
});

it('check 12 - BENEFIT_BAREME_UNCONFIGURED fails when a table-calculated component is enabled', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    p11runStaff(200_000, $user);

    // OVERTIME ships disabled precisely because its premium tranches are
    // NEEDS VERIFICATION (2.4).
    PayrollComponent::query()->where('code', 'OVERTIME')->update(['is_enabled' => true]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::BenefitBaremeUnconfigured, PreflightStatus::Fail);
});

it('check 13 - ACCOUNTING_PERIOD_LOCKED fails when the run\'s period is not open', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    p11runStaff(200_000, $user);

    DB::table('accounting_periods')->where('period_month', p11pfMonth())->update(['status' => 'hard_locked']);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::AccountingPeriodLocked, PreflightStatus::Fail);
});

it('check 14 - DUPLICATE_PAYROLL_MONTH fails when a live item already exists for the staff member elsewhere', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    $staff = p11runStaff(200_000, $user);

    // A DIFFERENT run type for the same month/profile, so it does not
    // collide with the run-under-test on `uq_payroll_runs_active`.
    $otherRun = PayrollRun::query()->create([
        'payroll_month' => p11pfMonth(),
        'run_type' => RunType::ThirteenthMonth->value,
        'status' => RunStatus::Calculated->value,
        'fiscal_year_id' => DB::table('fiscal_years')->value('id'),
        'academic_year_id' => DB::table('academic_years')->value('id'),
        'accounting_period_id' => DB::table('accounting_periods')->where('period_month', p11pfMonth())->value('id'),
        'employer_profile_id' => $profile->getKey(),
    ]);

    PayrollItem::query()->create([
        'payroll_run_id' => $otherRun->getKey(),
        'staff_member_id' => $staff['member']->id,
        'staff_contract_id' => $staff['contract']->id,
        'payroll_month' => p11pfMonth(),
        'is_cancelled' => false,
        'days_worked' => '31.00',
        'days_in_period' => '31.00',
        'hours_validated' => null,
        'gross' => 200_000,
        'sbt' => 200_000,
        'cnps_capped_base' => 200_000,
        'cnps_uncapped_base' => 200_000,
        'taxable_base' => 0,
        'irpp_amount' => 0,
        'total_employee_deductions' => 0,
        'total_employer_charges' => 0,
        'net' => 200_000,
        'ytd_sbt' => 200_000,
        'ytd_irpp_withheld' => 0,
        'exception_flags' => [],
    ]);

    // The run under test is a SEPARATE draft for the same month.
    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::DuplicatePayrollMonth, PreflightStatus::Fail);
});

it('check 15 - UNFILED_PRIOR_DECLARATIONS is a WARNING, never a refusal', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    p11runReferenceRates();
    p11runStaff(200_000, $user);

    DB::table('statutory_declarations')->insert([
        'type' => 'dipe', 'payee' => 'CNPS', 'period_month' => '2030-12-01',
        'period_year' => null, 'staff_member_id' => null,
        'due_date' => '2031-01-15', 'status' => 'due',
        'penalty_amount' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $run = p11pfDraftRun($profile);

    p11pfAssertCheck($run, PreflightCheckCode::UnfiledPriorDeclarations, PreflightStatus::Warning);

    // A warning never appears among the FATAL failures a run is blocked on.
    $outcome = app(PayrollPreflightCheck::class)->handle($run);
    expect($outcome['failed'])->not->toContain(PreflightCheckCode::UnfiledPriorDeclarations);
});

it('a fatal preflight failure blocks CalculatePayrollRun and writes NOTHING', function (): void {
    $user = p11runActor();
    p11runCalendar();
    $profile = p11runEmployerProfile();
    // No reference rates configured at all: every statutory component's
    // rate is unresolved (check 4), among others.
    p11runStaff(200_000, $user);

    expect(fn () => app(CalculatePayrollRun::class)->handle(
        p11pfMonth(),
        RunType::Regular,
        $user->toAuditActor(),
    ))->toThrow(PreflightFailed::class);

    expect(PayrollRun::query()->count())->toBe(1) // the draft row itself
        ->and(PayrollItem::query()->count())->toBe(0)
        ->and(DB::table('payroll_lines')->count())->toBe(0);

    /** @var PayrollRun $run */
    $run = PayrollRun::query()->firstOrFail();
    expect($run->status)->toBe(RunStatus::Draft);

    // The checklist itself DID persist - the refusal is a checklist, not a
    // stack trace (9.1).
    expect(DB::table('payroll_preflight_results')->where('payroll_run_id', $run->getKey())->count())->toBe(15);
});
