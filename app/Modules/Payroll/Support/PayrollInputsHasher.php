<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Support;

use Illuminate\Support\Facades\DB;

/**
 * The 8.3 inputs hash: SHA-256 over a CANONICAL, ORDERED serialisation of
 * everything that feeds a run's arithmetic - included contracts' employment
 * attributes, every StaffCompensation row in force, validated timesheet
 * totals, every resolved statutory rate row WITH ITS AMOUNT COLUMNS, the
 * EmployerProfile version in force, and the enabled component set with
 * calculation_order and formula_expression.
 *
 * Canonical means: fixed key order, every list sorted by its natural key,
 * JSON-encoded the same way every time. Computed at calculate, RE-VERIFIED
 * at approve: a mismatch fails approval - it never silently recalculates,
 * because a run someone approved is a run someone reviewed.
 *
 * Same shape as Students' PromotionInputsHasher, deliberately. HR-owned
 * tables are read with DB::table only (ModuleBoundaryTest).
 */
final class PayrollInputsHasher
{
    /**
     * @param  list<int>  $contractIds
     */
    public function handle(int $employerProfileId, string $payrollMonth, string $periodEnd, array $contractIds): string
    {
        $ids = array_values(array_unique($contractIds));
        sort($ids);

        $context = [
            'employer_profile' => $this->employerProfile($employerProfileId),
            'payroll_month' => $payrollMonth,
            'contracts' => $this->contracts($ids),
            'compensations' => $this->compensations($ids, $periodEnd),
            'timesheets' => $this->timesheets($ids, $payrollMonth),
            'rates' => $this->verifiedRates($periodEnd),
            'components' => $this->components(),
        ];

        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     */
    private function employerProfile(int $id): array
    {
        $row = DB::table('employer_profiles')->where('id', $id)->first();

        return [
            'id' => $id,
            'cnps_regime' => $row->cnps_regime ?? null,
            'rp_risk_class' => $row->rp_risk_class ?? null,
            'proration_basis' => $row->proration_basis ?? null,
            'ceiling_prorates_partial_month' => $row->ceiling_prorates_partial_month ?? null,
            'irpp_mode' => $row->irpp_mode ?? null,
            'effective_from' => $row->effective_from ?? null,
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function contracts(array $ids): array
    {
        $rows = [];

        foreach (DB::table('staff_contracts')->whereIn('id', $ids)->orderBy('id')->get() as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'staff_member_id' => (int) $row->staff_member_id,
                'contract_role' => $row->contract_role,
                'contract_type' => $row->contract_type,
                'working_time' => $row->working_time,
                'starts_on' => $row->starts_on,
                'ends_on' => $row->ends_on,
                'social_security_status' => $row->social_security_status,
                'is_payroll_eligible' => (bool) $row->is_payroll_eligible,
                'rp_risk_class_override' => $row->rp_risk_class_override,
                'version' => (int) $row->version,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function compensations(array $ids, string $periodEnd): array
    {
        $rows = [];

        $query = DB::table('staff_compensations')
            ->whereIn('staff_contract_id', $ids)
            ->where('effective_from', '<=', $periodEnd)
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd);
            })
            ->orderBy('id');

        foreach ($query->get() as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'staff_contract_id' => (int) $row->staff_contract_id,
                'component_code' => $row->component_code,
                'amount' => $row->amount === null ? null : (int) $row->amount,
                'rate_bp' => $row->rate_bp === null ? null : (int) $row->rate_bp,
                'effective_from' => $row->effective_from,
                'retroactive_from' => $row->retroactive_from,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function timesheets(array $ids, string $payrollMonth): array
    {
        $rows = [];

        $teaching = DB::table('teaching_hours_logs')
            ->whereIn('staff_contract_id', $ids)
            ->where('payroll_month', $payrollMonth)
            ->orderBy('id');

        foreach ($teaching->get() as $row) {
            $rows[] = [
                'kind' => 'teaching',
                'id' => (int) $row->id,
                'staff_contract_id' => (int) $row->staff_contract_id,
                'status' => $row->status,
                'hours_validated' => $row->hours_validated,
            ];
        }

        $administrative = DB::table('timesheets')
            ->whereIn('staff_contract_id', $ids)
            ->where('payroll_month', $payrollMonth)
            ->orderBy('id');

        foreach ($administrative->get() as $row) {
            $rows[] = [
                'kind' => 'timesheet',
                'id' => (int) $row->id,
                'staff_contract_id' => (int) $row->staff_contract_id,
                'status' => $row->status,
                'hours_validated' => $row->hours_validated,
            ];
        }

        return $rows;
    }

    /**
     * Every VERIFIED rate row in force at the period end, amount columns
     * included - a rate correction between calculate and approve therefore
     * changes the hash and fails approval, which is the point (8.3).
     *
     * @return list<array<string, mixed>>
     */
    private function verifiedRates(string $periodEnd): array
    {
        $rows = [];

        $query = DB::table('statutory_rates')
            ->where('is_verified', true)
            ->where('effective_from', '<=', $periodEnd)
            ->where(function ($q) use ($periodEnd): void {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $periodEnd);
            })
            ->orderBy('id');

        foreach ($query->get() as $row) {
            $rows[] = [
                'id' => (int) $row->id,
                'code' => $row->code,
                'employee_rate_bp' => $row->employee_rate_bp === null ? null : (int) $row->employee_rate_bp,
                'employer_rate_bp' => $row->employer_rate_bp === null ? null : (int) $row->employer_rate_bp,
                'flat_amount' => $row->flat_amount === null ? null : (int) $row->flat_amount,
                'ceiling_amount' => $row->ceiling_amount === null ? null : (int) $row->ceiling_amount,
                'floor_amount' => $row->floor_amount === null ? null : (int) $row->floor_amount,
                'band_from' => $row->band_from === null ? null : (int) $row->band_from,
                'band_to' => $row->band_to === null ? null : (int) $row->band_to,
                'risk_class' => $row->risk_class,
                'cnps_regime' => $row->cnps_regime,
                'effective_from' => $row->effective_from,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function components(): array
    {
        $rows = [];

        $query = DB::table('payroll_components')
            ->where('is_enabled', true)
            ->orderBy('code');

        foreach ($query->get() as $row) {
            $rows[] = [
                'code' => $row->code,
                'type' => $row->type,
                'calculation' => $row->calculation,
                'basis' => $row->basis,
                'statutory_rate_code' => $row->statutory_rate_code,
                'formula_expression' => $row->formula_expression,
                'calculation_order' => (int) $row->calculation_order,
                'is_taxable' => (bool) $row->is_taxable,
                'is_cnps_liable' => (bool) $row->is_cnps_liable,
                'is_prorated' => (bool) $row->is_prorated,
                'version' => (int) $row->version,
            ];
        }

        return $rows;
    }
}
