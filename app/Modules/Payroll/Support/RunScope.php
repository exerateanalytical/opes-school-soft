<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use App\Modules\Payroll\Domain\Rational;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Who is IN a payroll month, and for how many days (docs/specs/05-hr-payroll.md
 * 8.5): payroll-eligible contracts overlapping the month, on staff whose
 * person-level status admits pay, excluding seconded State teachers
 * (detache_etat - paid by MINESEC, not on the school's DIPE).
 *
 * One PayrollItem per STAFF MEMBER (H3 - the ceiling and the annualisation
 * are per employee per employer per month): a member's concurrent contracts
 * are merged onto one item keyed on the primary (earliest) contract, with
 * compensation aggregated across all of them.
 *
 * HR-owned tables are read with DB::table only (ModuleBoundaryTest).
 */
final class RunScope
{
    /**
     * @return array<int, array{staff_member_id: int, staff_no: string, primary_contract_id: int, contract_ids: list<int>, working_times: list<string>, social_security_status: string, rp_risk_class_override: string|null, cnps_number_present: bool, starts_on: string, ends_on: string|null, is_partial: bool}>
     *         keyed by staff_member_id
     */
    public function includedStaff(Carbon $monthStart, Carbon $monthEnd): array
    {
        $contracts = DB::table('staff_contracts')
            ->join('staff_members', 'staff_members.id', '=', 'staff_contracts.staff_member_id')
            ->where('staff_contracts.is_payroll_eligible', true)
            ->where('staff_contracts.social_security_status', '<>', 'detache_etat')
            ->where('staff_contracts.starts_on', '<=', $monthEnd->toDateString())
            ->where(function ($q) use ($monthStart): void {
                $q->whereNull('staff_contracts.ends_on')
                    ->orWhere('staff_contracts.ends_on', '>', $monthStart->toDateString());
            })
            ->whereIn('staff_members.status', ['active', 'on_leave'])
            ->orderBy('staff_contracts.id')
            ->get([
                'staff_contracts.id as contract_id',
                'staff_contracts.staff_member_id',
                'staff_contracts.working_time',
                'staff_contracts.social_security_status',
                'staff_contracts.rp_risk_class_override',
                'staff_contracts.starts_on',
                'staff_contracts.ends_on',
                'staff_members.staff_no',
                'staff_members.cnps_number',
            ]);

        $staff = [];

        foreach ($contracts as $row) {
            $memberId = (int) $row->staff_member_id;

            // The contract's slice of the month, exclusive end date.
            $startsOn = max((string) $row->starts_on, $monthStart->toDateString());
            $endsOn = $row->ends_on === null
                ? null
                : min((string) $row->ends_on, $monthEnd->copy()->addDay()->toDateString());

            if (! isset($staff[$memberId])) {
                $staff[$memberId] = [
                    'staff_member_id' => $memberId,
                    'staff_no' => (string) $row->staff_no,
                    'primary_contract_id' => (int) $row->contract_id,
                    'contract_ids' => [],
                    'working_times' => [],
                    'social_security_status' => (string) $row->social_security_status,
                    'rp_risk_class_override' => $row->rp_risk_class_override === null
                        ? null
                        : (string) $row->rp_risk_class_override,
                    'cnps_number_present' => $row->cnps_number !== null,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'is_partial' => false,
                ];
            }

            $staff[$memberId]['contract_ids'][] = (int) $row->contract_id;
            $staff[$memberId]['working_times'][] = (string) $row->working_time;

            $isPartial = $startsOn > $monthStart->toDateString()
                || ($endsOn !== null && $endsOn <= $monthEnd->toDateString());

            if ($isPartial) {
                $staff[$memberId]['is_partial'] = true;
            }
        }

        return $staff;
    }

    /**
     * The month's day counts under the configured convention (8.5): the
     * pair (days_worked, days_in_period) as exact rationals. When the
     * month is full both equal the period length and no proration occurs;
     * preflight check 3 has already blocked a partial month without a
     * configured convention.
     *
     * @param  array{starts_on: string, ends_on: string|null}  $member
     * @return array{Rational, Rational}
     */
    public function days(array $member, Carbon $monthStart, Carbon $monthEnd, ?string $prorationBasis): array
    {
        $periodStart = $monthStart;
        // Exclusive upper bound of the month.
        $periodEndExclusive = $monthEnd->copy()->addDay();

        $workedFrom = Carbon::parse($member['starts_on']);
        $workedUntilExclusive = $member['ends_on'] === null
            ? $periodEndExclusive
            : Carbon::parse($member['ends_on']);

        if ($workedUntilExclusive->gt($periodEndExclusive)) {
            $workedUntilExclusive = $periodEndExclusive;
        }

        switch ($prorationBasis) {
            case 'thirty_day_month':
                $daysInPeriod = 30;
                $calendarWorked = $workedFrom->diffInDays($workedUntilExclusive);
                $calendarInMonth = $periodStart->diffInDays($periodEndExclusive);
                // A full month is 30/30 regardless of calendar length; a
                // partial one scales its calendar days onto the 30-day base.
                $daysWorked = $calendarWorked >= $calendarInMonth
                    ? 30
                    : min($calendarWorked, 30);
                break;

            case 'working_days':
                // Jours ouvrables: Monday-Saturday.
                $daysInPeriod = $this->workingDays($periodStart, $periodEndExclusive);
                $daysWorked = $this->workingDays($workedFrom, $workedUntilExclusive);
                break;

            case 'calendar_days':
            default:
                $daysInPeriod = $periodStart->diffInDays($periodEndExclusive);
                $daysWorked = $workedFrom->diffInDays($workedUntilExclusive);
                break;
        }

        return [Rational::ofInt((int) $daysWorked), Rational::ofInt((int) $daysInPeriod)];
    }

    private function workingDays(Carbon $fromInclusive, Carbon $untilExclusive): int
    {
        $count = 0;
        $cursor = $fromInclusive->copy();

        while ($cursor->lt($untilExclusive)) {
            if (! $cursor->isSunday()) {
                $count++;
            }

            $cursor->addDay();
        }

        return $count;
    }
}
