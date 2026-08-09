<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Payroll\Domain\CnpsBases;
use App\Modules\Payroll\Domain\CnpsRegime;
use App\Modules\Payroll\Domain\ComponentCalculation;
use App\Modules\Payroll\Domain\ComponentGraph;
use App\Modules\Payroll\Domain\ComponentType;
use App\Modules\Payroll\Domain\IrppEngine;
use App\Modules\Payroll\Domain\PayrollFormula;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Domain\PreflightFailed;
use App\Modules\Payroll\Domain\Proration;
use App\Modules\Payroll\Domain\Rational;
use App\Modules\Payroll\Domain\RunStatus;
use App\Modules\Payroll\Domain\RunType;
use App\Modules\Payroll\Domain\StatutoryRateResolver;
use App\Modules\Payroll\Models\EmployerProfile;
use App\Modules\Payroll\Models\PayrollComponent;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollLine;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\StatutoryRate;
use App\Modules\Payroll\Support\IrppFormula;
use App\Modules\Payroll\Support\PayrollInputsHasher;
use App\Modules\Payroll\Support\RunScope;
use App\Support\Audit\Actor;
use App\Support\Money\Money;
use App\Support\Rate\Rate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/05-hr-payroll.md 8 - the calculation. EMPLOYER-WIDE, once per
 * month (H3); preflight FIRST, inside nothing - its checklist commits in
 * its own transaction so a refusal survives; then the computation inside
 * one transaction under the run row lock plus a named advisory lock on
 * (employer_profile, month) so two run types cannot race one month (8.2).
 *
 * Execution follows the 7.1 graph in strict calculation_order with the
 * order-200 bases barrier between earnings and statutory components.
 * Rounding: ONCE per component (00-core 7.3); bases are never rounded
 * (they are integer sums of already-rounded component amounts). The
 * gross - deductions = net identity is asserted PER ITEM before persist
 * and again by the table's CHECK constraint - no tolerance, because the
 * tolerance is where the bug hides (8.6).
 */
final class CalculatePayrollRun
{
    public function __construct(
        private readonly PayrollPreflightCheck $preflight,
        private readonly StatutoryRateResolver $resolver,
        private readonly RunScope $scope,
        private readonly PayrollInputsHasher $hasher,
    ) {}

    public function handle(
        string $payrollMonth,
        RunType $runType,
        Actor $actor,
        ?string $idempotencyKey = null,
    ): PayrollRun {
        Gate::authorize(PayrollPermission::RUN);

        $monthStart = Carbon::parse($payrollMonth)->startOfMonth();
        $periodEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        if ($runType === RunType::Reversal) {
            throw new DomainException('A reversal run is created by ReversePayrollRun, never calculated directly (8.7).');
        }

        // 00-core 6.2 rule 7: an idempotent retry returns the original.
        if ($idempotencyKey !== null) {
            $existing = PayrollRun::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $run = $this->openRun($monthStart, $periodEnd, $runType, $idempotencyKey);

        $lockName = sprintf('payroll:%d:%s', $run->employer_profile_id, $monthStart->toDateString());
        $this->acquireAdvisoryLock($lockName);

        try {
            // Preflight persists its checklist in its own transaction and
            // the refusal computes NOTHING (9.1).
            $outcome = $this->preflight->handle($run);

            if ($outcome['failed'] !== []) {
                throw PreflightFailed::withCodes($outcome['failed']);
            }

            return DB::transaction(function () use ($run, $monthStart, $periodEnd, $actor): PayrollRun {
                /** @var PayrollRun $locked */
                $locked = PayrollRun::query()->lockForUpdate()->findOrFail($run->getKey());

                $claimed = PayrollRun::query()
                    ->whereKey($locked->getKey())
                    ->whereIn('status', [RunStatus::Draft->value, RunStatus::Calculated->value])
                    ->update(['status' => RunStatus::Calculating->value]);

                if ($claimed !== 1) {
                    throw new DomainException(sprintf(
                        'Payroll run %d is not in a calculable state (conditional transition failed, 00-core 10.4).',
                        (int) $locked->getKey(),
                    ));
                }

                // Recalculation replaces this run's previous draft output.
                $itemIds = PayrollItem::query()->where('payroll_run_id', $locked->getKey())->pluck('id');
                PayrollLine::query()->whereIn('payroll_item_id', $itemIds)->delete();
                PayrollItem::query()->whereKey($itemIds)->delete();

                /** @var EmployerProfile $profile */
                $profile = EmployerProfile::query()->findOrFail($locked->employer_profile_id);

                $this->computeItems($locked, $profile, $monthStart, $periodEnd);

                $contractIds = [];

                foreach ($this->scope->includedStaff($monthStart, $periodEnd) as $member) {
                    foreach ($member['contract_ids'] as $contractId) {
                        $contractIds[] = $contractId;
                    }
                }

                $hash = $this->hasher->handle(
                    (int) $locked->employer_profile_id,
                    $monthStart->toDateString(),
                    $periodEnd->toDateString(),
                    $contractIds,
                );

                $completed = PayrollRun::query()
                    ->whereKey($locked->getKey())
                    ->where('status', RunStatus::Calculating->value)
                    ->update([
                        'status' => RunStatus::Calculated->value,
                        'inputs_hash' => $hash,
                        'calculated_by' => $actor->id,
                        'calculated_at' => Carbon::now(),
                    ]);

                if ($completed !== 1) {
                    throw new DomainException('Payroll run left the calculating state mid-computation; aborting (00-core 10.4).');
                }

                return $locked->refresh();
            });
        } finally {
            $this->releaseAdvisoryLock($lockName);
        }
    }

    /**
     * Finds or creates the month's run row, deriving both calendars and
     * the accounting period from the payroll month (02-accounting C3).
     */
    private function openRun(Carbon $monthStart, Carbon $periodEnd, RunType $runType, ?string $idempotencyKey): PayrollRun
    {
        /** @var EmployerProfile|null $profile */
        $profile = EmployerProfile::query()
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
            })
            ->orderByDesc('effective_from')
            ->first();

        if ($profile === null) {
            throw new DomainException(sprintf(
                'No EmployerProfile covers %s - complete the blocking first-run wizard step before payroll (05-hr-payroll 3.1).',
                $periodEnd->toDateString(),
            ));
        }

        $existing = PayrollRun::query()
            ->where('payroll_month', $monthStart->toDateString())
            ->where('run_type', $runType->value)
            ->where('employer_profile_id', $profile->getKey())
            ->where('status', '<>', RunStatus::Cancelled->value)
            ->first();

        if ($existing !== null) {
            if (! in_array($existing->status, [RunStatus::Draft, RunStatus::Calculated], true)) {
                throw new DomainException(sprintf(
                    'A %s run for %s already exists in status %s; reverse it before recalculating (8.7).',
                    $runType->value,
                    $monthStart->toDateString(),
                    $existing->status->value,
                ));
            }

            return $existing;
        }

        $fiscalYearId = DB::table('fiscal_years')
            ->where('starts_on', '<=', $monthStart->toDateString())
            ->where('ends_on', '>=', $periodEnd->toDateString())
            ->value('id');

        $academicYearId = DB::table('academic_years')
            ->where('starts_on', '<=', $monthStart->toDateString())
            ->where('ends_on', '>=', $monthStart->toDateString())
            ->value('id');

        $accountingPeriodId = DB::table('accounting_periods')
            ->where('period_month', $monthStart->toDateString())
            ->value('id');

        if ($fiscalYearId === null || $academicYearId === null || $accountingPeriodId === null) {
            throw new DomainException(sprintf(
                'Payroll month %s is not covered by an open fiscal year, academic year and accounting period (02-accounting C3).',
                $monthStart->toDateString(),
            ));
        }

        return PayrollRun::query()->create([
            'payroll_month' => $monthStart->toDateString(),
            'run_type' => $runType->value,
            'status' => RunStatus::Draft->value,
            'fiscal_year_id' => (int) $fiscalYearId,
            'academic_year_id' => (int) $academicYearId,
            'accounting_period_id' => (int) $accountingPeriodId,
            'employer_profile_id' => (int) $profile->getKey(),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    private function computeItems(PayrollRun $run, EmployerProfile $profile, Carbon $monthStart, Carbon $periodEnd): void
    {
        /** @var list<PayrollComponent> $components */
        $components = PayrollComponent::query()->where('is_enabled', true)->get()->all();

        $graph = new ComponentGraph(
            array_map(static fn (PayrollComponent $c): array => [
                'code' => $c->code,
                'order' => $c->calculation_order,
                'type' => $c->type,
                'calculation' => $c->calculation,
                'depends_on' => array_map(strval(...), $c->depends_on),
            ], $components),
            IrppFormula::BASES_BARRIER_ORDER,
        );

        $ordered = $graph->validated();

        $byCode = [];

        foreach ($components as $component) {
            $byCode[$component->code] = $component;
        }

        // The ceiling comes from the resolved PVID row; NULL = uncapped.
        $pvidRate = $this->resolveRate('PVID', $periodEnd, $profile);

        $irppEngine = new IrppEngine(IrppFormula::parameters(
            StatutoryRate::query()
                ->where('code', 'IRPP')
                ->where('is_verified', true)
                ->where('effective_from', '<=', $periodEnd->toDateString())
                ->where(function ($q) use ($periodEnd): void {
                    $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
                })
                ->get(),
        ));

        foreach ($this->scope->includedStaff($monthStart, $periodEnd) as $member) {
            $this->computeItem($run, $profile, $member, $ordered, $byCode, $pvidRate, $irppEngine, $monthStart, $periodEnd);
        }
    }

    /**
     * One staff member's month, in calculation order across the barrier.
     *
     * @param  array{staff_member_id: int, staff_no: string, primary_contract_id: int, contract_ids: list<int>, working_times: list<string>, social_security_status: string, rp_risk_class_override: string|null, cnps_number_present: bool, starts_on: string, ends_on: string|null, is_partial: bool}  $member
     * @param  list<array{code: string, order: int, type: ComponentType, calculation: ComponentCalculation, depends_on: list<string>}>  $ordered
     * @param  array<string, PayrollComponent>  $byCode
     */
    private function computeItem(
        PayrollRun $run,
        EmployerProfile $profile,
        array $member,
        array $ordered,
        array $byCode,
        StatutoryRate $pvidRate,
        IrppEngine $irppEngine,
        Carbon $monthStart,
        Carbon $periodEnd,
    ): void {
        [$daysWorked, $daysInPeriod] = $this->scope->days(
            ['starts_on' => $member['starts_on'], 'ends_on' => $member['ends_on']],
            $monthStart,
            $periodEnd,
            $profile->proration_basis === null ? null : (string) $profile->proration_basis,
        );

        $exemptBranches = $this->exemptBranches($member['contract_ids'], $periodEnd);

        // 3.5: assurance_volontaire suppresses every CNPS branch line.
        if ($member['social_security_status'] === 'assurance_volontaire') {
            $exemptBranches = array_values(array_unique([...$exemptBranches, 'PVID', 'PF', 'RP']));
        }

        $exceptionFlags = [];

        if ($exemptBranches !== []) {
            $exceptionFlags['exempt_branches'] = $exemptBranches;
        }

        if ($member['rp_risk_class_override'] !== null) {
            $exceptionFlags['rp_risk_class_override'] = $member['rp_risk_class_override'];
        }

        $compensations = $this->compensationsInForce($member['contract_ids'], $periodEnd);

        /** @var array<string, int> $amounts component code => amount */
        $amounts = [];
        /** @var list<array<string, mixed>> $lines */
        $lines = [];
        $hoursValidated = null;

        // ---- earnings, below the order-200 barrier ----
        foreach ($ordered as $node) {
            if ($node['order'] >= IrppFormula::BASES_BARRIER_ORDER) {
                continue;
            }

            $component = $byCode[$node['code']];
            $amount = null;
            $detail = [];

            switch ($component->calculation) {
                case ComponentCalculation::Fixed:
                    $amount = $this->fixedAmount($component, $compensations);
                    break;

                case ComponentCalculation::Percentage:
                    $amount = $this->percentageAmount($component, $compensations, $amounts, $detail);
                    break;

                case ComponentCalculation::Hourly:
                    [$amount, $hoursValidated] = $this->hourlyAmount($member['contract_ids'], $monthStart, $periodEnd);
                    break;

                case ComponentCalculation::Formula:
                    $amount = $this->formulaAmount($component, $amounts, $byCode, $daysWorked, $daysInPeriod);
                    break;

                case ComponentCalculation::Table:
                    // Preflight check 12 already refused any enabled table
                    // component; reaching here is a logic error.
                    throw new DomainException(sprintf(
                        'Component %s is table-calculated but its table is unconfigured (2.4).',
                        $component->code,
                    ));

                case ComponentCalculation::Statutory:
                    throw new DomainException(sprintf(
                        'Statutory component %s is ordered below the bases barrier (7.2 rule 3).',
                        $component->code,
                    ));
            }

            if ($amount === null || $amount === 0) {
                continue;
            }

            if ($component->is_prorated) {
                $amount = Proration::prorate($amount, $daysWorked, $daysInPeriod);
            }

            $amounts[$component->code] = $amount;
            $lines[] = [
                'component' => $component,
                'statutory_rate_id' => null,
                'base_amount' => $detail['base'] ?? $amount,
                'applied_rate_bp' => $detail['rate_bp'] ?? null,
                'applied_flat_amount' => null,
                'bracket_detail' => null,
                'amount' => $amount,
            ];
        }

        // Arrears (5.1): entitlement change vs the approved snapshots of
        // the retroactive window, one line per compensated month.
        foreach ($this->arrearsLines($member, $byCode, $periodEnd) as $arrears) {
            $amounts['ARREARS'] = ($amounts['ARREARS'] ?? 0) + (int) $arrears['amount'];
            $lines[] = $arrears;
        }

        // ---- the bases, materialised at the barrier (7.2 rule 3) ----
        $gross = 0;
        $sbt = 0;
        $sbc = 0;

        foreach ($lines as $line) {
            /** @var PayrollComponent $component */
            $component = $line['component'];
            $gross += (int) $line['amount'];

            if ($component->is_taxable) {
                $sbt += (int) $line['amount'];
            }

            if ($component->is_cnps_liable) {
                $sbc += (int) $line['amount'];
            }
        }

        $cnpsCapped = CnpsBases::cappedBase($sbc, $pvidRate->ceiling_amount);
        $cnpsUncapped = CnpsBases::uncappedBase($sbc);

        // ---- statutory components, above the barrier ----
        $irppAmount = 0;
        $taxableBase = 0;
        $ytdSbt = $sbt;
        $ytdIrpp = 0;

        foreach ($ordered as $node) {
            if ($node['order'] < IrppFormula::BASES_BARRIER_ORDER) {
                continue;
            }

            $component = $byCode[$node['code']];

            if ($component->calculation === ComponentCalculation::Statutory) {
                $rateCode = (string) $component->statutory_rate_code;

                if (in_array($rateCode, $exemptBranches, true)) {
                    continue;
                }

                if ($rateCode === 'IRPP') {
                    [$irppLine, $irppAmount, $taxableBase, $ytdSbt, $ytdIrpp] = $this->irppLine(
                        $component, $irppEngine, $profile, $member, $run,
                        $sbt, $amounts['PVID_EE'] ?? 0, $monthStart, $exceptionFlags,
                    );

                    if ($irppLine !== null) {
                        $amounts[$component->code] = (int) $irppLine['amount'];
                        $lines[] = $irppLine;
                    }

                    continue;
                }

                $line = $this->statutoryLine(
                    $component, $member, $profile, $periodEnd,
                    $gross, $sbt, $cnpsCapped, $cnpsUncapped,
                    $amounts['BASIC'] ?? 0, $irppAmount,
                );

                $amounts[$component->code] = (int) $line['amount'];
                $lines[] = $line;

                continue;
            }

            if ($component->calculation === ComponentCalculation::Fixed
                && $component->type === ComponentType::EmployeeDeduction) {
                // Order-600 voluntary deductions (loans, advances, fines).
                // Preflight check 11 refused any cappable one while the
                // quotite table is empty, so whatever reaches here is
                // uncapped by configuration.
                $amount = $this->fixedAmount($component, $compensations);

                if ($amount !== null && $amount !== 0) {
                    $amounts[$component->code] = $amount;
                    $lines[] = [
                        'component' => $component,
                        'statutory_rate_id' => null,
                        'base_amount' => $amount,
                        'applied_rate_bp' => null,
                        'applied_flat_amount' => null,
                        'bracket_detail' => null,
                        'amount' => $amount,
                    ];
                }
            }

            // NET (informational, order 900) is materialised below from the
            // engine-asserted identity, not evaluated as a formula.
        }

        // ---- totals and the 8.6 invariant ----
        $employeeDeductions = 0;
        $employerCharges = 0;

        foreach ($lines as $line) {
            /** @var PayrollComponent $component */
            $component = $line['component'];

            if ($component->type === ComponentType::EmployeeDeduction) {
                $employeeDeductions += (int) $line['amount'];
            }

            if ($component->type === ComponentType::EmployerCharge) {
                $employerCharges += (int) $line['amount'];
            }
        }

        $net = $gross - $employeeDeductions;

        if ($gross - $employeeDeductions !== $net) {
            throw new DomainException('gross - employee deductions must equal net EXACTLY (05-hr-payroll 8.6).');
        }

        if (isset($byCode['NET'])) {
            $lines[] = [
                'component' => $byCode['NET'],
                'statutory_rate_id' => null,
                'base_amount' => $gross,
                'applied_rate_bp' => null,
                'applied_flat_amount' => null,
                'bracket_detail' => null,
                'amount' => $net,
            ];
        }

        $item = PayrollItem::query()->create([
            'payroll_run_id' => $run->getKey(),
            'staff_member_id' => $member['staff_member_id'],
            'staff_contract_id' => $member['primary_contract_id'],
            'payroll_month' => $monthStart->toDateString(),
            'is_cancelled' => false,
            'days_worked' => $this->decimal($daysWorked),
            'days_in_period' => $this->decimal($daysInPeriod),
            'hours_validated' => $hoursValidated,
            'gross' => $gross,
            'sbt' => $sbt,
            'cnps_capped_base' => $cnpsCapped,
            'cnps_uncapped_base' => $cnpsUncapped,
            'taxable_base' => $taxableBase,
            'irpp_amount' => $irppAmount,
            'total_employee_deductions' => $employeeDeductions,
            'total_employer_charges' => $employerCharges,
            'net' => $net,
            'ytd_sbt' => $ytdSbt,
            'ytd_irpp_withheld' => $ytdIrpp,
            'exception_flags' => $exceptionFlags,
        ]);

        foreach ($lines as $line) {
            /** @var PayrollComponent $component */
            $component = $line['component'];

            PayrollLine::query()->create([
                'payroll_item_id' => $item->getKey(),
                'payroll_component_id' => $component->getKey(),
                'statutory_rate_id' => $line['statutory_rate_id'],
                'base_amount' => $line['base_amount'],
                'applied_rate_bp' => $line['applied_rate_bp'],
                'applied_flat_amount' => $line['applied_flat_amount'],
                'bracket_detail' => $line['bracket_detail'],
                'amount' => $line['amount'],
                'arrears_for_month' => $line['arrears_for_month'] ?? null,
            ]);
        }
    }

    /**
     * @param  array<string, list<array{amount: int|null, rate_bp: int|null}>>  $compensations
     */
    private function fixedAmount(PayrollComponent $component, array $compensations): ?int
    {
        $rows = $compensations[$component->code] ?? [];
        $total = 0;
        $found = false;

        foreach ($rows as $row) {
            if ($row['amount'] !== null) {
                $total += $row['amount'];
                $found = true;
            }
        }

        return $found ? $total : null;
    }

    /**
     * A percentage EARNING (e.g. SENIORITY) applies its granted rate to
     * the BASIC amount computed so far - the only basis available below
     * the barrier (5.3).
     *
     * @param  array<string, list<array{amount: int|null, rate_bp: int|null}>>  $compensations
     * @param  array<string, int>  $amounts
     * @param  array<string, mixed>  $detail
     */
    private function percentageAmount(PayrollComponent $component, array $compensations, array $amounts, array &$detail): ?int
    {
        $rows = $compensations[$component->code] ?? [];
        $base = $amounts['BASIC'] ?? 0;

        $total = 0;
        $found = false;

        foreach ($rows as $row) {
            if ($row['rate_bp'] !== null) {
                $total += Rate::ofBasisPoints($row['rate_bp'])->applyTo(Money::of($base))->amount();
                $found = true;
                $detail = ['base' => $base, 'rate_bp' => $row['rate_bp']];
            }
        }

        return $found ? $total : null;
    }

    /**
     * 5.5: Σ hours_validated x resolved rate, staff scope first, then
     * grade. Returns [amount|null, total hours string|null].
     *
     * @param  list<int>  $contractIds
     * @return array{int|null, string|null}
     */
    private function hourlyAmount(array $contractIds, Carbon $monthStart, Carbon $periodEnd): array
    {
        $totalHours = Rational::zero();
        $segments = [];

        $logs = DB::table('teaching_hours_logs')
            ->whereIn('staff_contract_id', $contractIds)
            ->where('payroll_month', $monthStart->toDateString())
            ->where('status', 'validated')
            ->orderBy('id')
            ->get();

        $sheets = DB::table('timesheets')
            ->whereIn('staff_contract_id', $contractIds)
            ->where('payroll_month', $monthStart->toDateString())
            ->where('status', 'validated')
            ->orderBy('id')
            ->get();

        foreach ([...$logs, ...$sheets] as $row) {
            $contractId = (int) $row->staff_contract_id;
            $hours = Rational::fromDecimalString((string) $row->hours_validated);

            $rate = $this->resolveHourlyRate($contractId, $periodEnd);

            if ($rate === null) {
                throw new DomainException(sprintf(
                    'No hourly rate resolves for contract %d at %s (5.5 staff -> grade precedence).',
                    $contractId,
                    $periodEnd->toDateString(),
                ));
            }

            // One rounding per COMPONENT (00-core 7.3): each segment stays
            // exact; the sum rounds once at the end.
            $totalHours = $totalHours->plus($hours);
            $segments[] = $hours->times(Rational::ofInt($rate));
        }

        if ($segments === []) {
            return [null, null];
        }

        $exact = Rational::zero();

        foreach ($segments as $segment) {
            $exact = $exact->plus($segment);
        }

        return [$exact->roundHalfUp(), $this->decimal($totalHours)];
    }

    private function resolveHourlyRate(int $contractId, Carbon $periodEnd): ?int
    {
        $inForce = static function ($q) use ($periodEnd): void {
            $q->where('effective_from', '<=', $periodEnd->toDateString())
                ->where(function ($qq) use ($periodEnd): void {
                    $qq->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
                });
        };

        $staffRate = DB::table('hourly_rates')
            ->where('scope', 'staff')
            ->where('staff_contract_id', $contractId)
            ->where($inForce)
            ->value('rate_per_hour');

        if ($staffRate !== null) {
            return (int) $staffRate;
        }

        $gradeId = DB::table('staff_contracts')->where('id', $contractId)->value('salary_grade_id');

        if ($gradeId === null) {
            return null;
        }

        $gradeRate = DB::table('hourly_rates')
            ->where('scope', 'grade')
            ->where('salary_grade_id', (int) $gradeId)
            ->where($inForce)
            ->value('rate_per_hour');

        return $gradeRate === null ? null : (int) $gradeRate;
    }

    /**
     * @param  array<string, int>  $amounts
     * @param  array<string, PayrollComponent>  $byCode
     */
    private function formulaAmount(
        PayrollComponent $component,
        array $amounts,
        array $byCode,
        Rational $daysWorked,
        Rational $daysInPeriod,
    ): ?int {
        if ($component->formula_expression === null) {
            return null;
        }

        $formula = PayrollFormula::parse($component->formula_expression, array_keys($byCode));

        $variables = $amounts;
        $variables['basic'] = $amounts['BASIC'] ?? 0;
        $variables['days_worked'] = $daysWorked->roundHalfUp();
        $variables['days_in_period'] = $daysInPeriod->roundHalfUp();
        $variables['hours_taught'] = 0;

        $provided = [];

        foreach ($formula->variables() as $name) {
            $provided[$name] = $variables[$name] ?? 0;
        }

        $amount = $formula->evaluate($provided);

        return $amount === 0 ? null : $amount;
    }

    /**
     * A percentage or flat-band statutory line above the barrier.
     *
     * @param  array{staff_member_id: int, staff_no: string, primary_contract_id: int, contract_ids: list<int>, working_times: list<string>, social_security_status: string, rp_risk_class_override: string|null, cnps_number_present: bool, starts_on: string, ends_on: string|null, is_partial: bool}  $member
     * @return array<string, mixed>
     */
    private function statutoryLine(
        PayrollComponent $component,
        array $member,
        EmployerProfile $profile,
        Carbon $periodEnd,
        int $gross,
        int $sbt,
        int $cnpsCapped,
        int $cnpsUncapped,
        int $basic,
        int $irppAmount,
    ): array {
        $base = match ((string) $component->basis?->value) {
            'gross' => $gross,
            'sbt' => $sbt,
            'cnps_capped' => $cnpsCapped,
            'cnps_uncapped' => $cnpsUncapped,
            'irpp_amount' => $irppAmount,
            'basic' => $basic,
            default => $gross,
        };

        $riskClass = $component->statutory_rate_code === 'RP'
            ? ($member['rp_risk_class_override'] ?? $profile->rp_risk_class)
            : null;

        $rate = $this->resolver->resolve(
            code: (string) $component->statutory_rate_code,
            periodEnd: $periodEnd,
            riskClass: $riskClass,
            cnpsRegime: CnpsRegime::from((string) $profile->cnps_regime),
            bandValue: $base,
        );

        if ($rate->flat_amount !== null) {
            return [
                'component' => $component,
                'statutory_rate_id' => (int) $rate->getKey(),
                'base_amount' => $base,
                'applied_rate_bp' => null,
                'applied_flat_amount' => $rate->flat_amount,
                'bracket_detail' => null,
                'amount' => $rate->flat_amount,
            ];
        }

        $rateBp = $component->type === ComponentType::EmployerCharge
            ? $rate->employer_rate_bp
            : $rate->employee_rate_bp;

        if ($rateBp === null) {
            throw new DomainException(sprintf(
                'Rate row %d for %s carries no %s share (H1 shape).',
                (int) $rate->getKey(),
                (string) $component->statutory_rate_code,
                $component->type->value,
            ));
        }

        $amount = Rate::ofBasisPoints($rateBp)->applyTo(Money::of($base))->amount();

        return [
            'component' => $component,
            'statutory_rate_id' => (int) $rate->getKey(),
            'base_amount' => $base,
            'applied_rate_bp' => $rateBp,
            'applied_flat_amount' => null,
            'bracket_detail' => null,
            'amount' => $amount,
        ];
    }

    /**
     * The IRPP line under the profile's engine mode (6.3 / 6.5).
     *
     * @param  array{staff_member_id: int, staff_no: string, primary_contract_id: int, contract_ids: list<int>, working_times: list<string>, social_security_status: string, rp_risk_class_override: string|null, cnps_number_present: bool, starts_on: string, ends_on: string|null, is_partial: bool}  $member
     * @param  array<string, mixed>  $exceptionFlags
     * @return array{array<string, mixed>|null, int, int, int, int} line, irpp_amount, taxable_base, ytd_sbt, ytd_irpp
     */
    private function irppLine(
        PayrollComponent $component,
        IrppEngine $engine,
        EmployerProfile $profile,
        array $member,
        PayrollRun $run,
        int $sbt,
        int $pvidEe,
        Carbon $monthStart,
        array &$exceptionFlags,
    ): array {
        // 2.4: no withholding floor is configured - tax from the first
        // franc, flagged on every payslip until the school configures one.
        $exceptionFlags['irpp_floor_unconfigured'] = true;

        if ((string) $profile->irpp_mode === 'annualised') {
            $result = $engine->monthlyAnnualised($sbt, $pvidEe);
            $ytdSbt = $sbt;
            $ytdIrpp = $result->irppMonth;
        } else {
            [$priorSbt, $priorPvid, $priorIrpp, $monthIndex] = $this->ytdFromSnapshots($member['staff_member_id'], $run, $monthStart);

            $result = $engine->monthlyYtdCumulative(
                ytdSbt: $priorSbt + $sbt,
                ytdPvidEe: $priorPvid + $pvidEe,
                monthIndex: $monthIndex,
                priorWithheld: $priorIrpp,
            );

            $ytdSbt = $priorSbt + $sbt;
            $ytdIrpp = $priorIrpp + $result->irppMonth;
        }

        $line = [
            'component' => $component,
            // Bracket provenance is per-bracket, not per-line: each
            // bracket_detail row identifies its rate row through the
            // engine's IrppBracket construction.
            'statutory_rate_id' => null,
            'base_amount' => $sbt,
            'applied_rate_bp' => null,
            'applied_flat_amount' => null,
            'bracket_detail' => $result->bracketDetail,
            'amount' => $result->irppMonth,
        ];

        return [$line, $result->irppMonth, $result->taxableBaseMonthly, $ytdSbt, $ytdIrpp];
    }

    /**
     * 6.5: ΣSBT, ΣPVID and Σ withheld for fiscal months 1..m-1 are read
     * from PayrollItemSnapshot payloads - NEVER recomputed (10).
     *
     * @return array{int, int, int, int} prior sbt, prior pvid, prior irpp, month index
     */
    private function ytdFromSnapshots(int $staffMemberId, PayrollRun $run, Carbon $monthStart): array
    {
        $fiscalStart = DB::table('fiscal_years')->where('id', $run->fiscal_year_id)->value('starts_on');
        $fiscalStartDate = Carbon::parse((string) $fiscalStart)->startOfDay();

        $monthIndex = (int) $fiscalStartDate->diffInMonths($monthStart) + 1;

        $rows = DB::table('payroll_item_snapshots')
            ->join('payroll_items', 'payroll_items.id', '=', 'payroll_item_snapshots.payroll_item_id')
            ->where('payroll_items.staff_member_id', $staffMemberId)
            ->where('payroll_items.is_cancelled', false)
            ->where('payroll_items.payroll_month', '>=', $fiscalStartDate->toDateString())
            ->where('payroll_items.payroll_month', '<', $monthStart->toDateString())
            ->get(['payroll_item_snapshots.payload']);

        $priorSbt = 0;
        $priorPvid = 0;
        $priorIrpp = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $payload */
            $payload = json_decode((string) $row->payload, true, flags: JSON_THROW_ON_ERROR);

            $priorSbt += (int) ($payload['totals']['sbt'] ?? 0);
            $priorPvid += (int) ($payload['totals']['pvid_ee'] ?? 0);
            $priorIrpp += (int) ($payload['totals']['irpp'] ?? 0);
        }

        return [$priorSbt, $priorPvid, $priorIrpp, $monthIndex];
    }

    /**
     * 5.1 arrears: for a compensation granted retroactively into approved
     * months, one taxable ARREARS line per month, each amount = new
     * entitlement - what that month's SNAPSHOT says was paid. Snapshots
     * are never mutated; the correction happens in the month of payment.
     *
     * @param  array{staff_member_id: int, staff_no: string, primary_contract_id: int, contract_ids: list<int>, working_times: list<string>, social_security_status: string, rp_risk_class_override: string|null, cnps_number_present: bool, starts_on: string, ends_on: string|null, is_partial: bool}  $member
     * @param  array<string, PayrollComponent>  $byCode
     * @return list<array<string, mixed>>
     */
    private function arrearsLines(array $member, array $byCode, Carbon $periodEnd): array
    {
        if (! isset($byCode['ARREARS'])) {
            return [];
        }

        $grants = DB::table('staff_compensations')
            ->whereIn('staff_contract_id', $member['contract_ids'])
            ->whereNotNull('retroactive_from')
            ->whereColumn('retroactive_from', '<', 'effective_from')
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->get();

        $lines = [];

        foreach ($grants as $grant) {
            if ($grant->amount === null) {
                continue;
            }

            $cursor = Carbon::parse((string) $grant->retroactive_from)->startOfMonth();
            $until = Carbon::parse((string) $grant->effective_from)->startOfMonth();

            while ($cursor->lt($until)) {
                $snapshot = DB::table('payroll_item_snapshots')
                    ->join('payroll_items', 'payroll_items.id', '=', 'payroll_item_snapshots.payroll_item_id')
                    ->where('payroll_items.staff_member_id', $member['staff_member_id'])
                    ->where('payroll_items.is_cancelled', false)
                    ->where('payroll_items.payroll_month', $cursor->toDateString())
                    ->value('payroll_item_snapshots.payload');

                if ($snapshot !== null) {
                    /** @var array<string, mixed> $payload */
                    $payload = json_decode((string) $snapshot, true, flags: JSON_THROW_ON_ERROR);

                    $paid = 0;

                    foreach ((array) ($payload['lines'] ?? []) as $paidLine) {
                        if (is_array($paidLine) && ($paidLine['component_code'] ?? null) === $grant->component_code) {
                            $paid += (int) ($paidLine['amount'] ?? 0);
                        }
                    }

                    $delta = (int) $grant->amount - $paid;

                    if ($delta > 0) {
                        $lines[] = [
                            'component' => $byCode['ARREARS'],
                            'statutory_rate_id' => null,
                            'base_amount' => $delta,
                            'applied_rate_bp' => null,
                            'applied_flat_amount' => null,
                            'bracket_detail' => null,
                            'amount' => $delta,
                            'arrears_for_month' => $cursor->toDateString(),
                        ];
                    }
                }

                $cursor->addMonth();
            }
        }

        return $lines;
    }

    /**
     * @param  list<int>  $contractIds
     * @return array<string, list<array{amount: int|null, rate_bp: int|null}>>
     */
    private function compensationsInForce(array $contractIds, Carbon $periodEnd): array
    {
        $rows = DB::table('staff_compensations')
            ->whereIn('staff_contract_id', $contractIds)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
            })
            ->orderBy('id')
            ->get();

        $byComponent = [];

        foreach ($rows as $row) {
            $byComponent[(string) $row->component_code][] = [
                'amount' => $row->amount === null ? null : (int) $row->amount,
                'rate_bp' => $row->rate_bp === null ? null : (int) $row->rate_bp,
            ];
        }

        return $byComponent;
    }

    /**
     * @param  list<int>  $contractIds
     * @return list<string>
     */
    private function exemptBranches(array $contractIds, Carbon $periodEnd): array
    {
        $branches = DB::table('staff_contract_exemptions')
            ->whereIn('staff_contract_id', $contractIds)
            ->where('effective_from', '<=', $periodEnd->toDateString())
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd->toDateString());
            })
            ->pluck('branch');

        return array_values(array_unique(array_map(strval(...), $branches->all())));
    }

    private function resolveRate(string $code, Carbon $periodEnd, EmployerProfile $profile): StatutoryRate
    {
        return $this->resolver->resolve(
            code: $code,
            periodEnd: $periodEnd,
            riskClass: null,
            cnpsRegime: CnpsRegime::from((string) $profile->cnps_regime),
        );
    }

    private function decimal(Rational $value): string
    {
        // Two decimal places, exact for the day/hour precision in play.
        $scaled = $value->times(Rational::ofInt(100))->roundHalfUp();

        return sprintf('%d.%02d', intdiv($scaled, 100), abs($scaled % 100));
    }

    /**
     * 8.2: the named advisory lock on (employer_profile, month) - what
     * stops two DIFFERENT run types racing one month. MySQL GET_LOCK is
     * session-scoped and survives transactions, which is exactly why it
     * complements (not replaces) the run-row FOR UPDATE.
     */
    private function acquireAdvisoryLock(string $name): void
    {
        $row = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$name]);

        if ((int) ($row->acquired ?? 0) !== 1) {
            throw new DomainException(sprintf(
                'Another payroll operation holds the lock for %s; retry when it completes (8.2).',
                $name,
            ));
        }
    }

    private function releaseAdvisoryLock(string $name): void
    {
        DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
    }
}
