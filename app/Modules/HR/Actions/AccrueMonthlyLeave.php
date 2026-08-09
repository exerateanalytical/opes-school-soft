<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\LeaveAccrualRateUnconfigured;
use App\Modules\HR\Domain\LeaveEntryType;
use App\Modules\HR\Domain\LeaveRequestStatus;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\StaffContract;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The monthly scheduled accrual (docs/specs/05-hr-payroll.md 12.3): one
 * `accrual` ledger row per eligible contract for the month, keyed
 * idempotently on the generated UNIQUE (contract, type, 'accrual',
 * effective_on) - running twice writes nothing twice.
 *
 * REFUSES while `leave_types.monthly_accrual_days` is NULL (the 0 standing
 * rule): the 1.5 j.o./month figure is a 2.3 reference value the operator
 * must configure from a verified source, never seed data.
 *
 * A contract spending the WHOLE month on approved leave of a
 * `counts_as_effective_service = FALSE` type accrues nothing.
 */
final class AccrueMonthlyLeave
{
    /**
     * @return int number of accrual rows written (0 when already accrued)
     */
    public function handle(string $payrollMonth, ?Actor $actor = null): int
    {
        $actor ??= Actor::system();

        $monthStart = Carbon::parse($payrollMonth)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth()->startOfDay();

        /** @var LeaveType $annual */
        $annual = LeaveType::query()->where('code', LeaveType::ANNUAL_CODE)->firstOrFail();

        if ($annual->monthly_accrual_days === null) {
            throw new LeaveAccrualRateUnconfigured(
                'The monthly leave accrual rate is not configured (05-hr-payroll 12.3). '
                .'The statutory 1.5 j.o./month is a reference value, not seed data; '
                .'set leave_types.monthly_accrual_days from a verified source before accruing.'
            );
        }

        $rate = $annual->monthly_accrual_days;

        return DB::transaction(function () use ($annual, $rate, $monthStart, $monthEnd, $actor): int {
            // Contracts in force for the FULL month (a month of effective
            // service, 12.3), still payroll-eligible.
            /** @var list<StaffContract> $contracts */
            $contracts = StaffContract::query()
                ->where('is_payroll_eligible', true)
                ->where('starts_on', '<=', $monthStart->toDateString())
                ->where(function ($query) use ($monthEnd): void {
                    $query->whereNull('ends_on')
                        ->orWhere('ends_on', '>', $monthEnd->toDateString());
                })
                ->get()
                ->all();

            $written = 0;

            foreach ($contracts as $contract) {
                // Whole month on non-effective-service leave => no accrual.
                $suspendedByLeave = LeaveRequest::query()
                    ->where('staff_contract_id', $contract->id)
                    ->where('status', LeaveRequestStatus::Approved->value)
                    ->where('starts_on', '<=', $monthStart->toDateString())
                    ->where('ends_on', '>=', $monthEnd->toDateString())
                    ->whereIn('leave_type_id', LeaveType::query()
                        ->where('counts_as_effective_service', false)
                        ->pluck('id'))
                    ->exists();

                if ($suspendedByLeave) {
                    continue;
                }

                // insertOrIgnore rides the generated accrual_month_key UNIQUE:
                // the idempotency lives in the schema, not in a read.
                $written += DB::table('leave_accruals')->insertOrIgnore([
                    'staff_contract_id' => $contract->id,
                    'leave_type_id' => $annual->id,
                    'entry_type' => LeaveEntryType::Accrual->value,
                    'delta_days' => $rate,
                    'effective_on' => $monthEnd->toDateString(),
                    'source_type' => 'monthly_accrual',
                    'source_id' => null,
                    'reason' => null,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);
            }

            return $written;
        });
    }
}
