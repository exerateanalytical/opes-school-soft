<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\ApprovalDecision;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\MarkApproval;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Step two of the approval chain (docs/specs/01-assessment.md §7.2): the head
 * of department verifies the grid.
 *
 * Gated on `marks.validate`, which the Vice-Principal holds WITHOUT
 * `marks.enter` on purpose — §7.2's flow is two-person, and an approver who
 * can also author is one person, not two.
 *
 * `draft → validated` is not a transition. Nothing is validated that was never
 * submitted, or the chain means nothing; WorkflowState::canTransitionTo is the
 * single statement of that rule and this Action refuses on it.
 */
final class ValidateMarks
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return array{approval: MarkApproval, validated: int}
     */
    public function handle(
        int $subjectAllocationId,
        int $assessmentPeriodId,
        int $classGroupId,
    ): array {
        $decision = ApprovalDecision::Validate;

        Gate::authorize($decision->requiredPermission());

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();
        $actorId = auth()->id();

        return DB::transaction(function () use (
            $subjectAllocationId,
            $assessmentPeriodId,
            $classGroupId,
            $decision,
            $actor,
            $actorId,
        ): array {
            $now = Carbon::now(BusinessDate::TIMEZONE);

            $approval = MarkApproval::query()
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('assessment_period_id', $assessmentPeriodId)
                ->where('class_group_id', $classGroupId)
                ->first();

            if (! $approval instanceof MarkApproval) {
                throw new DomainException('These marks have not been submitted for validation.');
            }

            $current = $this->currentWorkflowState($approval->status);

            if (! $decision->isLegalFrom($current)) {
                throw new DomainException(
                    "Marks in state '{$approval->status}' cannot be validated; they must be submitted first."
                );
            }

            $validated = DB::table('marks')
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('assessment_period_id', $assessmentPeriodId)
                ->whereIn('enrollment_id', $this->enrollmentIds($classGroupId))
                ->where('workflow_state', WorkflowState::Submitted->value)
                ->update([
                    'workflow_state' => WorkflowState::Validated->value,
                    'validated_by' => $actorId,
                    'validated_at' => $now,
                    'updated_at' => $now,
                ]);

            // 00-core §10.4: two heads of department clicking Validate at the
            // same instant produce one validation and one refusal.
            $moved = DB::table('mark_approvals')
                ->where('id', $approval->getKey())
                ->where('version', $approval->version)
                ->where('status', MarkApproval::STATUS_SUBMITTED)
                ->update([
                    'status' => $decision->resultingBatchStatus(),
                    'last_decision' => $decision->value,
                    'validated_by' => $actorId,
                    'validated_at' => $now,
                    'mark_count' => $validated,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            if ($moved === 0) {
                throw new DomainException(
                    'Another user changed this submission while you were validating it; nothing was validated.'
                );
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: MarkApproval::class,
                auditableId: (int) $approval->getKey(),
                before: ['status' => $approval->status],
                after: [
                    'status' => $decision->resultingBatchStatus(),
                    'decision' => $decision->value,
                    'marks_validated' => $validated,
                ],
                actor: $actor,
            );

            return [
                'approval' => MarkApproval::query()->where('id', $approval->getKey())->firstOrFail(),
                'validated' => $validated,
            ];
        });
    }

    /**
     * The batch header's status and the marks' workflow_state are the same
     * fact at two granularities; `returned` is the header's word for `draft`.
     */
    private function currentWorkflowState(string $status): WorkflowState
    {
        return match ($status) {
            MarkApproval::STATUS_SUBMITTED => WorkflowState::Submitted,
            MarkApproval::STATUS_VALIDATED => WorkflowState::Validated,
            default => WorkflowState::Draft,
        };
    }

    /**
     * @return list<int>
     */
    private function enrollmentIds(int $classGroupId): array
    {
        /** @var list<int> $ids */
        $ids = DB::table('enrollment_segments')
            ->where('class_group_id', $classGroupId)
            ->whereNull('ends_on')
            ->pluck('enrollment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return $ids;
    }
}
