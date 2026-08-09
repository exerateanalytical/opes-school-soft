<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Payroll\Domain\CnpsClaimStatus;
use App\Modules\Payroll\Domain\CnpsClaimType;
use App\Modules\Payroll\Domain\PayrollPermission;
use App\Modules\Payroll\Models\CnpsBenefitClaim;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records the employer's reimbursement receivable for an advanced CNPS
 * benefit (docs/specs/05-hr-payroll.md 11.6) and submits it.
 *
 * NO ledger write: the receivable's posting (Dr CNPS receivable / Cr staff
 * payable) awaits the sub-account confirmation flagged NEEDS VERIFICATION -
 * `journal_entry_id` stays NULL and the claim still ages on the report,
 * which is the part v1 lacked entirely.
 */
final class RecordCnpsBenefitClaim
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $staffMemberId,
        CnpsClaimType $claimType,
        string $periodFrom,
        string $periodTo,
        int $amountAdvanced,
        int $amountClaimed,
        Actor $actor,
        ?int $workAccidentId = null,
    ): CnpsBenefitClaim {
        Gate::authorize(PayrollPermission::DECLARATION_FILE);

        if ($amountAdvanced < 0 || $amountClaimed < 0) {
            throw ValidationException::withMessages([
                'amount_advanced' => 'Claim amounts cannot be negative.',
            ]);
        }

        if ($workAccidentId !== null && $claimType !== CnpsClaimType::WorkAccident) {
            throw ValidationException::withMessages([
                'work_accident_id' => 'Only a work_accident claim links to a work accident.',
            ]);
        }

        return DB::transaction(function () use (
            $staffMemberId, $claimType, $periodFrom, $periodTo,
            $amountAdvanced, $amountClaimed, $actor, $workAccidentId
        ): CnpsBenefitClaim {
            $claim = CnpsBenefitClaim::query()->create([
                'staff_member_id' => $staffMemberId,
                'claim_type' => $claimType,
                'work_accident_id' => $workAccidentId,
                'period_from' => $periodFrom,
                'period_to' => $periodTo,
                'amount_advanced' => $amountAdvanced,
                'amount_claimed' => $amountClaimed,
                'amount_reimbursed' => 0,
                'status' => CnpsClaimStatus::Draft,
                'created_by' => (int) $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Payroll',
                auditableType: CnpsBenefitClaim::class,
                auditableId: (int) $claim->getKey(),
                after: [
                    'staff_member_id' => $staffMemberId,
                    'claim_type' => $claimType->value,
                    'amount_advanced' => $amountAdvanced,
                    'amount_claimed' => $amountClaimed,
                ],
                actor: $actor,
            );

            return $claim;
        });
    }

    /** Marks a draft claim submitted to CNPS (conditional UPDATE, 00-core 10.4). */
    public function submit(int $claimId, Actor $actor): CnpsBenefitClaim
    {
        Gate::authorize(PayrollPermission::DECLARATION_FILE);

        return DB::transaction(function () use ($claimId, $actor): CnpsBenefitClaim {
            /** @var CnpsBenefitClaim $claim */
            $claim = CnpsBenefitClaim::query()->whereKey($claimId)->lockForUpdate()->firstOrFail();

            $updated = CnpsBenefitClaim::query()
                ->whereKey($claim->id)
                ->where('status', CnpsClaimStatus::Draft->value)
                ->update([
                    'status' => CnpsClaimStatus::Submitted->value,
                    'submitted_at' => now(),
                    'version' => $claim->version + 1,
                ]);

            if ($updated !== 1) {
                throw ValidationException::withMessages([
                    'status' => "Only a draft claim is submitted; #{$claim->id} is '{$claim->status->value}'.",
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Payroll',
                auditableType: CnpsBenefitClaim::class,
                auditableId: (int) $claim->getKey(),
                before: ['status' => CnpsClaimStatus::Draft->value],
                after: ['status' => CnpsClaimStatus::Submitted->value],
                actor: $actor,
            );

            return $claim->refresh();
        });
    }
}
