<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Domain\SettlementStatus;
use App\Modules\HR\Models\LeaveAccrual;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\TerminationSettlement;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Drafts the ONE settlement a terminated contract will ever have
 * (docs/specs/05-hr-payroll.md 13.1, H9).
 *
 * What is computed: `seniority_years` (from seniority_reference_date) and
 * the leave-days balance (from the LeaveAccrual ledger, recorded in
 * other_amounts for the payslip note).
 *
 * What is NOT computed - by design, not omission:
 * - `indemnite_licenciement`: the Arrêté 016/MTPS severance schedule is
 *   NEEDS VERIFICATION and sources conflict materially; the amount is
 *   manual and REQUIRES a basis note (also a CHECK constraint).
 * - the exempt/taxable IRPP split of severance: rule unverified, manual,
 *   entered together (CHECK).
 * - `leave_compensation` in francs: the days come from the ledger; the
 *   monetary rate rides the unverified ALLOCATION_CONGE inputs, so the
 *   amount is manual while they are.
 */
final class ComputeTerminationSettlement
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $otherAmounts
     */
    public function handle(
        int $staffContractId,
        Actor $actor,
        ?int $indemniteLicenciement = null,
        ?string $indemniteBasisNote = null,
        ?int $indemniteCompensatricePreavis = null,
        ?int $leaveCompensation = null,
        ?int $exemptPortion = null,
        ?int $taxablePortion = null,
        array $otherAmounts = [],
        ?string $noticeStart = null,
        ?string $noticeEnd = null,
        bool $noticeServed = false,
    ): TerminationSettlement {
        Gate::authorize(HrPermission::MANAGE);

        if ($indemniteLicenciement !== null && ($indemniteBasisNote === null || trim($indemniteBasisNote) === '')) {
            throw ValidationException::withMessages([
                'indemnite_basis_note' => 'Severance is manual while the Arrêté 016/MTPS schedule is unverified; '
                    .'a basis note stating the source of the amount is mandatory.',
            ]);
        }

        if (($exemptPortion === null) !== ($taxablePortion === null)) {
            throw ValidationException::withMessages([
                'exempt_portion' => 'The severance IRPP split is entered as BOTH portions or neither.',
            ]);
        }

        return DB::transaction(function () use (
            $staffContractId, $actor, $indemniteLicenciement, $indemniteBasisNote,
            $indemniteCompensatricePreavis, $leaveCompensation, $exemptPortion,
            $taxablePortion, $otherAmounts, $noticeStart, $noticeEnd, $noticeServed
        ): TerminationSettlement {
            /** @var StaffContract $contract */
            $contract = StaffContract::query()->whereKey($staffContractId)->lockForUpdate()->firstOrFail();

            if ($contract->termination_reason === null || $contract->ends_on === null) {
                throw ValidationException::withMessages([
                    'staff_contract_id' => 'A settlement is drafted for a TERMINATED contract; terminate it first.',
                ]);
            }

            if (TerminationSettlement::query()->where('staff_contract_id', $contract->id)->exists()) {
                throw ValidationException::withMessages([
                    'staff_contract_id' => 'This contract already has its settlement; a contract gets exactly one.',
                ]);
            }

            // ends_on is exclusive: the last working day is the day before.
            $lastWorkingDay = $contract->ends_on->copy()->subDay();

            $seniorityYears = round(
                $contract->seniority_reference_date->diffInDays($lastWorkingDay) / 365.25,
                2,
            );

            // The ledger answers "days owed at departure" (12.2); the
            // monetised leave_compensation stays manual (see class docblock).
            $annualTypeId = (int) LeaveType::query()
                ->where('code', LeaveType::ANNUAL_CODE)
                ->value('id');

            $leaveDaysBalance = LeaveAccrual::balance($contract->id, $annualTypeId, $lastWorkingDay->toDateString());

            $settlement = TerminationSettlement::query()->create([
                'staff_contract_id' => $contract->id,
                'termination_type' => $contract->termination_reason,
                'notice_start' => $noticeStart,
                'notice_end' => $noticeEnd,
                'notice_served' => $noticeServed,
                'last_working_day' => $lastWorkingDay->toDateString(),
                'settlement_date' => null,
                'seniority_years' => number_format($seniorityYears, 2, '.', ''),
                'indemnite_licenciement' => $indemniteLicenciement,
                'indemnite_basis_note' => $indemniteBasisNote,
                'indemnite_compensatrice_preavis' => $indemniteCompensatricePreavis,
                'leave_compensation' => $leaveCompensation,
                'other_amounts' => $otherAmounts + ['leave_days_balance' => $leaveDaysBalance],
                'exempt_portion' => $exemptPortion,
                'taxable_portion' => $taxablePortion,
                'status' => SettlementStatus::Draft,
                'created_by' => (int) $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'HR',
                auditableType: TerminationSettlement::class,
                auditableId: (int) $settlement->getKey(),
                after: [
                    'staff_contract_id' => $contract->id,
                    'termination_type' => $contract->termination_reason->value,
                    'seniority_years' => $settlement->seniority_years,
                    'leave_days_balance' => $leaveDaysBalance,
                    'indemnite_licenciement' => $indemniteLicenciement,
                ],
                actor: $actor,
            );

            return $settlement;
        });
    }
}
