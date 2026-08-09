<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\InsuranceCoverType;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Domain\InsurancePolicyStatus;
use App\Modules\Welfare\Models\InsurancePolicy;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-10.md §4 (W5). Creates or updates an insurance policy.
 *
 * Cross-module references (academic year, fee item, Phase 9 asset) are
 * bare ids verified via DB::table - never another module's Models
 * (ModuleBoundaryTest). The fee_item_id link is the ONLY connection to
 * billing: the premium bills through that FeeItem via the ordinary Phase 6
 * pipeline (design §14), and this Action never posts anything.
 */
final class SavePolicy
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(?int $policyId, array $data, Actor $actor): InsurancePolicy
    {
        Gate::authorize(InsurancePermission::MANAGE);

        return DB::transaction(function () use ($policyId, $data, $actor): InsurancePolicy {
            $existing = null;

            if ($policyId !== null) {
                /** @var InsurancePolicy $existing */
                $existing = InsurancePolicy::query()->lockForUpdate()->findOrFail($policyId);
            }

            $this->validate($data, $existing);

            if ($existing !== null) {
                $existing->fill($data)->save();
                $policy = $existing;
                $auditAction = AuditAction::Updated;
            } else {
                $policy = InsurancePolicy::query()->create($data);
                $auditAction = AuditAction::Created;
            }

            // Pick up database defaults (status) before reading for audit.
            $policy->refresh();

            $this->audit->handle(
                action: $auditAction,
                module: 'Welfare',
                auditableType: InsurancePolicy::class,
                auditableId: (int) $policy->getKey(),
                after: [
                    'provider' => $policy->provider,
                    'policy_no' => $policy->policy_no,
                    'cover_type' => $policy->cover_type->value,
                    'premium_per_student' => $policy->premium_per_student,
                    'coverage' => $policy->coverage_start->toDateString()
                        .' → '.$policy->coverage_end->toDateString(),
                    'status' => $policy->status->value,
                ],
                actor: $actor,
            );

            return $policy;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(array $data, ?InsurancePolicy $existing): void
    {
        if ($existing === null) {
            foreach (['provider', 'policy_no', 'cover_type', 'coverage_start', 'coverage_end', 'academic_year_id'] as $field) {
                $value = $data[$field] ?? null;

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    throw ValidationException::withMessages([
                        $field => 'A policy requires provider, policy number, cover type, coverage period and academic year.',
                    ]);
                }
            }
        }

        $coverType = $this->coverType($data, $existing);

        $policyNo = $data['policy_no'] ?? null;

        if ($policyNo !== null) {
            $clash = InsurancePolicy::query()
                ->where('policy_no', (string) $policyNo)
                ->when($existing !== null, fn ($q) => $q->whereKeyNot($existing?->getKey()))
                ->exists();

            if ($clash) {
                throw ValidationException::withMessages([
                    'policy_no' => 'A policy with this number already exists.',
                ]);
            }
        }

        $start = $data['coverage_start'] ?? $existing?->coverage_start;
        $end = $data['coverage_end'] ?? $existing?->coverage_end;

        if ($start !== null && $end !== null
            && Carbon::parse($this->dateString($end))->lessThan(Carbon::parse($this->dateString($start)))) {
            throw ValidationException::withMessages([
                'coverage_end' => 'Coverage cannot end before it starts.',
            ]);
        }

        $premium = array_key_exists('premium_per_student', $data)
            ? $data['premium_per_student']
            : $existing?->premium_per_student;

        if ($coverType === InsuranceCoverType::Student) {
            if ($premium === null || (int) $premium < 0) {
                throw ValidationException::withMessages([
                    'premium_per_student' => 'A student policy requires a non-negative premium per student.',
                ]);
            }
        } elseif ($premium !== null) {
            throw ValidationException::withMessages([
                'premium_per_student' => 'An asset policy has no per-student premium.',
            ]);
        }

        $status = $data['status'] ?? null;

        if ($status !== null && ! $status instanceof InsurancePolicyStatus
            && InsurancePolicyStatus::tryFrom((string) $status) === null) {
            throw ValidationException::withMessages([
                'status' => 'Unknown policy status; expected active, expired or cancelled.',
            ]);
        }

        if (($data['academic_year_id'] ?? null) !== null
            && ! DB::table('academic_years')->where('id', (int) $data['academic_year_id'])->exists()) {
            throw ValidationException::withMessages([
                'academic_year_id' => 'The referenced academic year does not exist.',
            ]);
        }

        $feeItemId = array_key_exists('fee_item_id', $data)
            ? $data['fee_item_id']
            : $existing?->fee_item_id;

        if ($feeItemId !== null) {
            if ($coverType === InsuranceCoverType::Asset) {
                throw ValidationException::withMessages([
                    'fee_item_id' => 'An asset policy never bills students, so it takes no fee item.',
                ]);
            }

            if (! DB::table('fee_items')->where('id', (int) $feeItemId)->exists()) {
                throw ValidationException::withMessages([
                    'fee_item_id' => 'The referenced fee item does not exist.',
                ]);
            }
        }

        // asset_id is deliberately NOT existence-checked: the FK to the
        // Phase 9 register is a tracked follow-up (plan §1), and checking
        // here would order-couple the phases the plan keeps apart.
        if (($data['asset_id'] ?? null) !== null && $coverType === InsuranceCoverType::Student) {
            throw ValidationException::withMessages([
                'asset_id' => 'A student policy covers people, not a register asset.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function coverType(array $data, ?InsurancePolicy $existing): InsuranceCoverType
    {
        $raw = $data['cover_type'] ?? $existing?->cover_type;

        if ($raw instanceof InsuranceCoverType) {
            return $raw;
        }

        $type = InsuranceCoverType::tryFrom((string) $raw);

        if ($type === null) {
            throw ValidationException::withMessages([
                'cover_type' => 'Unknown cover type; expected student or asset.',
            ]);
        }

        return $type;
    }

    private function dateString(mixed $date): string
    {
        return $date instanceof Carbon ? $date->toDateString() : (string) $date;
    }
}
