<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\InsuranceCoverType;
use App\Modules\Welfare\Domain\InsurancePermission;
use App\Modules\Welfare\Domain\InsurancePolicyStatus;
use App\Modules\Welfare\Domain\InsuranceStatus;
use App\Modules\Welfare\Models\InsurancePolicy;
use App\Modules\Welfare\Models\StudentInsurance;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W5). Bulk-enrols a cohort of ENROLLMENTS (the
 * year-scoped identity, 07-students line 39) under a student policy.
 *
 * IDEMPOTENT on UNIQUE(enrollment_id, policy_id): a rerun over the same
 * cohort (double-click, retried batch) inserts nothing new and reports the
 * overlap as `already_covered`. Enrollments that are not ACTIVE, or that
 * belong to a different academic year than the policy, are counted out as
 * `skipped` rather than failing the batch - a withdrawn student inside a
 * class selection is a normal fact, not an error.
 *
 * NO billing here: the premium bills through the policy's FeeItem via the
 * ordinary Phase 6 pipeline (design §14).
 */
final class EnrollStudentsInPolicy
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<int>  $enrollmentIds
     * @return array{enrolled: int, already_covered: int, skipped: int}
     */
    public function handle(int $policyId, array $enrollmentIds, Carbon $enrolledOn, Actor $actor): array
    {
        Gate::authorize(InsurancePermission::MANAGE);

        return DB::transaction(function () use ($policyId, $enrollmentIds, $enrolledOn, $actor): array {
            /** @var InsurancePolicy $policy */
            $policy = InsurancePolicy::query()->lockForUpdate()->findOrFail($policyId);

            if ($policy->cover_type !== InsuranceCoverType::Student) {
                throw new DomainException(
                    "Policy {$policy->policy_no} covers assets; students cannot be enrolled under it."
                );
            }

            if ($policy->status !== InsurancePolicyStatus::Active) {
                throw new DomainException(
                    "Policy {$policy->policy_no} is {$policy->status->value}; only an active policy accepts enrolments."
                );
            }

            $ids = array_values(array_unique(array_map(intval(...), $enrollmentIds)));

            // ONE read for the whole cohort: which ids exist as ACTIVE
            // enrollments of the policy's academic year.
            /** @var list<int> $eligible */
            $eligible = DB::table('enrollments')
                ->whereIn('id', $ids)
                ->where('status', 'active')
                ->where('academic_year_id', $policy->academic_year_id)
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

            /** @var list<int> $covered */
            $covered = StudentInsurance::query()
                ->where('policy_id', $policyId)
                ->whereIn('enrollment_id', $eligible)
                ->lockForUpdate()
                ->pluck('enrollment_id')
                ->all();

            $toInsert = array_values(array_diff($eligible, $covered));

            foreach ($toInsert as $enrollmentId) {
                StudentInsurance::query()->create([
                    'enrollment_id' => $enrollmentId,
                    'policy_id' => $policyId,
                    'enrolled_on' => $enrolledOn,
                    'status' => InsuranceStatus::Active,
                ]);
            }

            $summary = [
                'enrolled' => count($toInsert),
                'already_covered' => count($covered),
                'skipped' => count($ids) - count($eligible),
            ];

            if ($summary['enrolled'] > 0) {
                $this->audit->handle(
                    action: AuditAction::Created,
                    module: 'Welfare',
                    auditableType: StudentInsurance::class,
                    auditableId: (int) $policy->getKey(),
                    after: [
                        'policy_no' => $policy->policy_no,
                        'enrolled_on' => $enrolledOn->toDateString(),
                        ...$summary,
                    ],
                    actor: $actor,
                );
            }

            return $summary;
        });
    }
}
