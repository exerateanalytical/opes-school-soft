<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\PayrollFormula;
use App\Modules\Payroll\Domain\PreflightCheckCode;
use App\Modules\Payroll\Domain\PreflightStatus;
use App\Modules\Payroll\Domain\RateShape;
use App\Modules\Payroll\Domain\StatutoryRateAmbiguous;
use App\Modules\Payroll\Domain\StatutoryRateResolver;
use App\Modules\Payroll\Domain\StatutoryRateUnresolved;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollPreflightResult;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Modules\Payroll\Support\RunScope;
use App\Support\Expression\ExpressionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * How payroll refuses to run (docs/specs/05-hr-payroll.md 9.1): the fifteen
 * checks, executed at the head of CalculatePayrollRun BEFORE any
 * computation. Every failure is fatal except check 15 (a warning). There
 * is no "proceed anyway", no default and no fallback - an empty field
 * stops a bursar for an afternoon; a wrong rate survives an audit cycle
 * and the school pays the reassessment (05 §0).
 *
 * The result set is PERSISTED and replaced atomically per execution, in
 * its own transaction, so the refusal's reasons survive the calculate
 * rollback: the bursar sees a checklist, not a stack trace, each failing
 * row linking to the settings screen that fixes it.
 */
final class PayrollPreflightCheck
{
    public function __construct(
        private readonly RunScope $scope,
        private readonly StatutoryRateResolver $resolver,
    ) {}

    /**
     * @return array{failed: list<PreflightCheckCode>, results: list<PayrollPreflightResult>}
     */
    public function handle(PayrollRun $run): array
    {
        $monthStart = $run->payroll_month->copy()->startOfMonth();
        $monthEnd = $run->periodEnd();

        /** @var EmployerProfile|null $profile */
        $profile = EmployerProfile::query()->find($run->employer_profile_id);

        $staff = $this->scope->includedStaff($monthStart, $monthEnd);
        $contractIds = array_merge(...array_values(array_map(
            static fn (array $member): array => $member['contract_ids'],
            $staff,
        )) ?: [[]]);

        /** @var list<PayrollComponent> $enabled */
        $enabled = PayrollComponent::query()->where('is_enabled', true)->orderBy('code')->get()->all();

        $checks = [];
        $checks[] = $this->checkEmployerProfile($profile, $monthEnd);
        $checks[] = $this->checkRegimeConfirmed($profile);
        $checks[] = $this->checkProrationConvention($profile, $staff);
        $checks[] = $this->checkStatutoryResolution($enabled, $profile, $staff, $monthEnd);
        $checks[] = $this->checkBandCoverage($enabled, $monthEnd);
        $checks[] = $this->checkIrppBrackets($enabled, $monthEnd);
        $checks[] = $this->checkFormulaTests($enabled);
        $checks[] = $this->checkCnpsNumbers($staff);
        $checks[] = $this->checkTimesheets($staff, $monthStart);
        $checks[] = $this->checkDaysWorked($staff, $monthStart, $monthEnd, $profile);
        $checks[] = $this->checkDeductionCap($enabled, $contractIds, $monthEnd);
        $checks[] = $this->checkBenefitBareme($enabled);
        $checks[] = $this->checkAccountingPeriod($run);
        $checks[] = $this->checkDuplicateMonth($run, $staff, $monthStart);
        $checks[] = $this->checkUnfiledDeclarations($monthStart);

        // Persist the checklist in ITS OWN transaction: a refusal must
        // survive the calculate rollback or the screen has nothing to show.
        $results = DB::transaction(function () use ($run, $checks): array {
            PayrollPreflightResult::query()->where('payroll_run_id', $run->getKey())->delete();

            $rows = [];
            $now = Carbon::now();

            foreach ($checks as [$code, $status, $detail]) {
                $rows[] = PayrollPreflightResult::query()->create([
                    'payroll_run_id' => $run->getKey(),
                    'check_code' => $code->value,
                    'status' => $status->value,
                    'detail' => $detail,
                    'checked_at' => $now,
                ]);
            }

            return $rows;
        });

        $failed = [];

        foreach ($checks as [$code, $status]) {
            if ($status === PreflightStatus::Fail && $code->isFatal()) {
                $failed[] = $code;
            }
        }

        return ['failed' => $failed, 'results' => $results];
    }

    /**
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkEmployerProfile(?EmployerProfile $profile, Carbon $periodEnd): array
    {
        $covers = $profile !== null
            && ! $profile->effective_from->gt($periodEnd)
            && ($profile->effective_to === null || $profile->effective_to->gt($periodEnd));

        return [
            PreflightCheckCode::EmployerProfileMissing,
            $covers ? PreflightStatus::Pass : PreflightStatus::Fail,
            $covers ? [] : ['period_end' => $periodEnd->toDateString()],
        ];
    }

    /**
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkRegimeConfirmed(?EmployerProfile $profile): array
    {
        $confirmed = $profile !== null
            && $profile->cnps_regime !== null
            && trim($profile->rp_risk_class) !== ''
            && $profile->cnps_notification_document_id !== 0;

        return [
            PreflightCheckCode::EmployerRegimeUnconfirmed,
            $confirmed ? PreflightStatus::Pass : PreflightStatus::Fail,
            [],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkProrationConvention(?EmployerProfile $profile, array $staff): array
    {
        $partials = array_values(array_filter(
            array_map(static fn (array $m): ?string => ($m['is_partial'] ?? false) ? (string) $m['staff_no'] : null, $staff),
        ));

        if ($partials === []) {
            return [PreflightCheckCode::ProrationConventionUnconfigured, PreflightStatus::Pass, []];
        }

        $configured = $profile !== null
            && $profile->proration_basis !== null
            && $profile->ceiling_prorates_partial_month !== null;

        return [
            PreflightCheckCode::ProrationConventionUnconfigured,
            $configured ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['partial_month_staff' => $partials],
        ];
    }

    /**
     * Check 4 - every enabled percentage-statutory component resolves to
     * exactly one VERIFIED row at the period end. Flat bands are check 5;
     * IRPP brackets are check 6.
     *
     * @param  list<PayrollComponent>  $enabled
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkStatutoryResolution(array $enabled, ?EmployerProfile $profile, array $staff, Carbon $periodEnd): array
    {
        $bandCodes = ['RAV', 'TDL'];
        $failures = [];

        $riskClasses = [];

        if ($profile !== null) {
            $riskClasses[] = $profile->rp_risk_class;
        }

        foreach ($staff as $member) {
            if ($member['rp_risk_class_override'] !== null) {
                $riskClasses[] = (string) $member['rp_risk_class_override'];
            }
        }

        $codesSeen = [];

        foreach ($enabled as $component) {
            $code = $component->statutory_rate_code;

            if ($component->calculation->value !== 'statutory' || $code === null) {
                continue;
            }

            if (in_array($code, $bandCodes, true) || $code === 'IRPP' || isset($codesSeen[$code])) {
                continue;
            }

            $codesSeen[$code] = true;

            $classes = $code === 'RP' ? array_values(array_unique($riskClasses)) : [null];

            $regime = $profile === null ? null : CnpsRegime::from((string) $profile->cnps_regime);

            foreach ($classes as $riskClass) {
                try {
                    $this->resolver->resolve(
                        code: $code,
                        periodEnd: $periodEnd,
                        riskClass: $riskClass,
                        cnpsRegime: $regime,
                    );
                } catch (StatutoryRateUnresolved) {
                    $failures[] = ['code' => $code, 'risk_class' => $riskClass, 'reason' => 'unresolved'];
                } catch (StatutoryRateAmbiguous) {
                    $failures[] = ['code' => $code, 'risk_class' => $riskClass, 'reason' => 'ambiguous'];
                }
            }
        }

        return [
            PreflightCheckCode::StatutoryRateResolution,
            $failures === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['failures' => $failures],
        ];
    }

    /**
     * Check 5 - RAV and TDL band tables must cover EVERY possible basis
     * value with no gap: verified bands contiguous from zero with an open
     * top band (4.5: the tables ship EMPTY and block, by design).
     *
     * @param  list<PayrollComponent>  $enabled
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkBandCoverage(array $enabled, Carbon $periodEnd): array
    {
        $failures = [];

        foreach (['RAV', 'TDL'] as $code) {
            $isEnabled = array_filter(
                $enabled,
                static fn (PayrollComponent $c): bool => $c->statutory_rate_code === $code,
            ) !== [];

            if (! $isEnabled) {
                continue;
            }

            $gap = $this->coverageGap($code, RateShape::FlatBand, $periodEnd);

            if ($gap !== null) {
                $failures[] = ['code' => $code, 'uncovered' => $gap];
            }
        }

        return [
            PreflightCheckCode::StatutoryBandCoverage,
            $failures === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['failures' => $failures],
        ];
    }

    /**
     * Check 6 - IRPP brackets contiguous, non-overlapping, starting at 0,
     * top band open, on an ANNUAL basis.
     *
     * @param  list<PayrollComponent>  $enabled
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkIrppBrackets(array $enabled, Carbon $periodEnd): array
    {
        $irppEnabled = array_filter(
            $enabled,
            static fn (PayrollComponent $c): bool => $c->statutory_rate_code === 'IRPP',
        ) !== [];

        if (! $irppEnabled) {
            return [PreflightCheckCode::IrppBracketCoverage, PreflightStatus::Pass, []];
        }

        $gap = $this->coverageGap('IRPP', RateShape::ProgressiveBracket, $periodEnd);

        $annual = StatutoryRate::query()
            ->where('code', 'IRPP')
            ->where('is_verified', true)
            ->where('bracket_basis', '<>', 'annual')
            ->doesntExist();

        $ok = $gap === null && $annual;

        return [
            PreflightCheckCode::IrppBracketCoverage,
            $ok ? PreflightStatus::Pass : PreflightStatus::Fail,
            $ok ? [] : ['uncovered' => $gap, 'all_annual' => $annual],
        ];
    }

    /**
     * Returns a description of the first coverage defect for a banded
     * code, or null when verified rows cover [0, infinity) contiguously.
     *
     * @return array<string, mixed>|null
     */
    private function coverageGap(string $code, RateShape $shape, Carbon $periodEnd): ?array
    {
        /** @var list<StatutoryRate> $rows */
        $rows = StatutoryRate::query()
            ->where('code', $code)
            ->where('shape', $shape->value)
            ->where('is_verified', true)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
            })
            ->orderBy('band_from')
            ->get()
            ->all();

        if ($rows === []) {
            return ['from' => 0, 'to' => null, 'reason' => 'no_rows'];
        }

        $expectedFrom = 0;

        foreach ($rows as $row) {
            if ($row->band_from === null || $row->band_from !== $expectedFrom) {
                return ['from' => $expectedFrom, 'to' => $row->band_from, 'reason' => 'gap_or_overlap'];
            }

            if ($row->band_to === null) {
                // Open top band: fully covered - any later row would have
                // been an overlap caught by the ordering above.
                return null;
            }

            $expectedFrom = $row->band_to;
        }

        return ['from' => $expectedFrom, 'to' => null, 'reason' => 'no_open_top_band'];
    }

    /**
     * Check 7 - every enabled formula component that PRODUCES LINES has at
     * least one stored unit test, and every stored test passes right now.
     * NET is informational: its formula is the printed statement of the
     * 7.2 rule 4 invariant the engine asserts directly, not an executed
     * computation, so it is outside this check's scope.
     *
     * @param  list<PayrollComponent>  $enabled
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkFormulaTests(array $enabled): array
    {
        $failures = [];

        $componentCodes = array_map(static fn (PayrollComponent $c): string => $c->code, $enabled);

        foreach ($enabled as $component) {
            if ($component->calculation->value !== 'formula' || $component->type->value === 'informational') {
                continue;
            }

            $tests = $component->storedTests()->get();

            if ($tests->isEmpty()) {
                $failures[] = ['code' => $component->code, 'reason' => 'no_stored_test'];

                continue;
            }

            foreach ($tests as $test) {
                try {
                    $formula = PayrollFormula::parse((string) $component->formula_expression, $componentCodes);
                    /** @var array<string, int> $inputs */
                    $inputs = $test->inputs;
                    $actual = $formula->evaluate($inputs);
                } catch (ExpressionException|Throwable $e) {
                    $failures[] = ['code' => $component->code, 'test' => $test->name, 'reason' => $e->getMessage()];

                    continue;
                }

                if ($actual !== $test->expected) {
                    $failures[] = [
                        'code' => $component->code,
                        'test' => $test->name,
                        'expected' => $test->expected,
                        'actual' => $actual,
                    ];
                }
            }
        }

        return [
            PreflightCheckCode::FormulaTests,
            $failures === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['failures' => $failures],
        ];
    }

    /**
     * Check 8 - an affilie_cnps employee without a CNPS number cannot go
     * on a DIPE; the run refuses rather than filing a hole (3.5).
     *
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkCnpsNumbers(array $staff): array
    {
        $missing = [];

        foreach ($staff as $member) {
            if ($member['social_security_status'] === 'affilie_cnps' && ! $member['cnps_number_present']) {
                $missing[] = (string) $member['staff_no'];
            }
        }

        sort($missing);

        return [
            PreflightCheckCode::CnpsNumberMissing,
            $missing === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['staff_no' => $missing],
        ];
    }

    /**
     * Check 9 - hourly staff enter payroll ONLY through fully validated
     * hours (5.5): no rows at all, or any row not yet `validated`, refuses.
     *
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkTimesheets(array $staff, Carbon $monthStart): array
    {
        $offenders = [];

        foreach ($staff as $member) {
            if (! in_array('hourly', $member['working_times'], true)) {
                continue;
            }

            $teaching = DB::table('teaching_hours_logs')
                ->whereIn('staff_contract_id', $member['contract_ids'])
                ->where('payroll_month', $monthStart->toDateString());

            $administrative = DB::table('timesheets')
                ->whereIn('staff_contract_id', $member['contract_ids'])
                ->where('payroll_month', $monthStart->toDateString());

            $total = (clone $teaching)->count() + (clone $administrative)->count();
            $unvalidated = (clone $teaching)->where('status', '<>', 'validated')->count()
                + (clone $administrative)->where('status', '<>', 'validated')->count();

            if ($total === 0 || $unvalidated > 0) {
                $offenders[] = (string) $member['staff_no'];
            }
        }

        sort($offenders);

        return [
            PreflightCheckCode::TimesheetNotValidated,
            $offenders === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['staff_no' => $offenders],
        ];
    }

    /**
     * Check 10 - days worked must be derivable for everyone included: the
     * DIPE requires them per employee per month, and a zero-or-negative
     * derivation means the scope and the convention disagree.
     *
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkDaysWorked(array $staff, Carbon $monthStart, Carbon $monthEnd, ?EmployerProfile $profile): array
    {
        $offenders = [];

        foreach ($staff as $member) {
            /** @var array{starts_on: string, ends_on: string|null} $slice */
            $slice = ['starts_on' => $member['starts_on'], 'ends_on' => $member['ends_on']];

            [$worked, $inPeriod] = $this->scope->days(
                $slice,
                $monthStart,
                $monthEnd,
                $profile?->proration_basis === null ? null : (string) $profile->proration_basis,
            );

            if ($worked->isZero() || $worked->isNegative() || $inPeriod->isZero()) {
                $offenders[] = (string) $member['staff_no'];
            }
        }

        sort($offenders);

        return [
            PreflightCheckCode::DaysWorkedUnavailable,
            $offenders === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['staff_no' => $offenders],
        ];
    }

    /**
     * Check 11 - the quotite cessible table ships EMPTY (2.4): while it is
     * unconfigured, any present cappable deduction refuses the run.
     * Statutory deductions are exempt - a school with no loans runs on
     * day one.
     *
     * @param  list<PayrollComponent>  $enabled
     * @param  list<int>  $contractIds
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkDeductionCap(array $enabled, array $contractIds, Carbon $periodEnd): array
    {
        $cappableCodes = array_values(array_map(
            static fn (PayrollComponent $c): string => $c->code,
            array_filter($enabled, static fn (PayrollComponent $c): bool => $c->subject_to_deduction_cap),
        ));

        if ($cappableCodes === [] || $contractIds === []) {
            return [PreflightCheckCode::DeductionCapUnconfigured, PreflightStatus::Pass, []];
        }

        $present = DB::table('staff_compensations')
            ->whereIn('staff_contract_id', $contractIds)
            ->whereIn('component_code', $cappableCodes)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
            })
            ->exists();

        return [
            PreflightCheckCode::DeductionCapUnconfigured,
            $present ? PreflightStatus::Fail : PreflightStatus::Pass,
            $present ? ['cappable_components' => $cappableCodes] : [],
        ];
    }

    /**
     * Check 12 - the benefits-in-kind bareme (and the overtime tranche
     * table) ship EMPTY (2.4): enabling any `table`-calculated component
     * without its table blocks the run.
     *
     * @param  list<PayrollComponent>  $enabled
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkBenefitBareme(array $enabled): array
    {
        $offenders = array_values(array_map(
            static fn (PayrollComponent $c): string => $c->code,
            array_filter($enabled, static fn (PayrollComponent $c): bool => $c->calculation->value === 'table'),
        ));

        return [
            PreflightCheckCode::BenefitBaremeUnconfigured,
            $offenders === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['components' => $offenders],
        ];
    }

    /**
     * Check 13 - the accounting period must exist and be OPEN (02 C8).
     *
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkAccountingPeriod(PayrollRun $run): array
    {
        $status = DB::table('accounting_periods')
            ->where('id', $run->accounting_period_id)
            ->value('status');

        $open = $status === 'open';

        return [
            PreflightCheckCode::AccountingPeriodLocked,
            $open ? PreflightStatus::Pass : PreflightStatus::Fail,
            $open ? [] : ['status' => $status],
        ];
    }

    /**
     * Check 14 - no live PayrollItem may already exist for any included
     * staff member for this month, across ALL runs (00-core 10.4). The
     * run's own draft items are exempt: recalculation replaces them.
     *
     * @param  array<int, array<string, mixed>>  $staff
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkDuplicateMonth(PayrollRun $run, array $staff, Carbon $monthStart): array
    {
        $memberIds = array_keys($staff);

        if ($memberIds === []) {
            return [PreflightCheckCode::DuplicatePayrollMonth, PreflightStatus::Pass, []];
        }

        $duplicateMemberIds = DB::table('payroll_items')
            ->whereIn('staff_member_id', $memberIds)
            ->where('payroll_month', $monthStart->toDateString())
            ->where('is_cancelled', false)
            ->where('payroll_run_id', '<>', $run->getKey())
            ->pluck('staff_member_id');

        $staffNos = [];

        foreach ($duplicateMemberIds as $memberId) {
            $staffNos[] = (string) $staff[(int) $memberId]['staff_no'];
        }

        sort($staffNos);

        return [
            PreflightCheckCode::DuplicatePayrollMonth,
            $staffNos === [] ? PreflightStatus::Pass : PreflightStatus::Fail,
            ['staff_no' => $staffNos],
        ];
    }

    /**
     * Check 15 - unfiled prior-period declarations WARN, never block: a
     * school in arrears still needs to pay its staff (9.1). The
     * declarations table belongs to a later work package; absent, the
     * check passes vacuously.
     *
     * @return array{PreflightCheckCode, PreflightStatus, array<string, mixed>}
     */
    private function checkUnfiledDeclarations(Carbon $monthStart): array
    {
        if (! Schema::hasTable('statutory_declarations')) {
            return [PreflightCheckCode::UnfiledPriorDeclarations, PreflightStatus::Pass, []];
        }

        $unfiled = DB::table('statutory_declarations')
            ->where('period_month', '<', $monthStart->toDateString())
            ->whereIn('status', ['due', 'generated', 'late'])
            ->count();

        return [
            PreflightCheckCode::UnfiledPriorDeclarations,
            $unfiled === 0 ? PreflightStatus::Pass : PreflightStatus::Warning,
            ['unfiled_count' => $unfiled],
        ];
    }
}
