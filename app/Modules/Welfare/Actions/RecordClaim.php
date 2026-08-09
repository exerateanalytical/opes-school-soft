<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\ClaimStatus;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Models\InsuranceClaim;
use App\Modules\Welfare\Models\InsurancePolicy;
use App\Modules\Welfare\Models\StudentInsurance;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W5). Records a claim against a policy.
 *
 * The incident must fall INSIDE the policy's coverage period - a claim for
 * an uncovered date is the insurer's refusal letter, not a database row.
 * A student claim names the certificate (student_insurances row), which
 * must belong to the same policy. Claims are born `submitted` by default
 * (recording one IS filing it); pass ClaimStatus::Draft to hold it back.
 */
final class RecordClaim
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $policyId,
        Carbon $incidentDate,
        string $description,
        int $amountClaimed,
        Actor $actor,
        ?int $studentInsuranceId = null,
        ClaimStatus $status = ClaimStatus::Submitted,
    ): InsuranceClaim {
        Gate::authorize(InsurancePermission::MANAGE);

        return DB::transaction(function () use (
            $policyId, $incidentDate, $description, $amountClaimed, $actor, $studentInsuranceId, $status
        ): InsuranceClaim {
            /** @var InsurancePolicy $policy */
            $policy = InsurancePolicy::query()->lockForUpdate()->findOrFail($policyId);

            if (! $status->isOpen()) {
                throw new DomainException(
                    'A new claim starts draft or submitted; settlement is SettleClaim\'s decision.'
                );
            }

            if (trim($description) === '') {
                throw ValidationException::withMessages([
                    'description' => 'A claim requires a description of the incident.',
                ]);
            }

            if ($amountClaimed <= 0) {
                throw ValidationException::withMessages([
                    'amount_claimed' => 'The claimed amount must be positive.',
                ]);
            }

            if (! $policy->covers($incidentDate)) {
                throw new DomainException(
                    "The incident date {$incidentDate->toDateString()} falls outside "
                    ."policy {$policy->policy_no}'s coverage "
                    ."({$policy->coverage_start->toDateString()} → {$policy->coverage_end->toDateString()})."
                );
            }

            if ($studentInsuranceId !== null) {
                /** @var StudentInsurance $cover */
                $cover = StudentInsurance::query()->lockForUpdate()->findOrFail($studentInsuranceId);

                if ($cover->policy_id !== (int) $policy->getKey()) {
                    throw new DomainException(
                        'The named certificate belongs to a different policy.'
                    );
                }
            }

            $claim = InsuranceClaim::query()->create([
                'policy_id' => $policyId,
                'student_insurance_id' => $studentInsuranceId,
                'incident_date' => $incidentDate,
                'description' => $description,
                'amount_claimed' => $amountClaimed,
                'status' => $status,
                'recorded_by' => $actor->id,
            ]);

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Welfare',
                auditableType: InsuranceClaim::class,
                auditableId: (int) $claim->getKey(),
                after: [
                    'policy_no' => $policy->policy_no,
                    'incident_date' => $incidentDate->toDateString(),
                    'amount_claimed' => $amountClaimed,
                    'status' => $status->value,
                ],
                actor: $actor,
            );

            return $claim->refresh();
        });
    }
}
