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
 * The return leg of the approval chain (docs/specs/01-assessment.md §7.2,
 * §7.4): the head of department sends the grid back to draft with a reason.
 *
 * The reason is mandatory. A rejection with no reason is how a teacher loses
 * an afternoon with nothing to act on, and the database CHECK on
 * `mark_approvals.return_reason` refuses it even if a future caller forgets.
 *
 * A rejection also clears `submitted_by` / `submitted_at` on every mark it
 * touches: the marks are genuinely back in the teacher's hands, and leaving a
 * stale submitter on them would make the next audit read as though the batch
 * had been submitted twice.
 */
final class RejectMarks
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return array{approval: MarkApproval, returned: int}
     */
    public function handle(
        int $subjectAllocationId,
        int $assessmentPeriodId,
        int $classGroupId,
        string $reason,
    ): array {
        $decision = ApprovalDecision::Reject;

        Gate::authorize($decision->requiredPermission());

        $reason = trim($reason);

        if ($decision->requiresReason() && $reason === '') {
            throw new DomainException('Returning marks to the teacher requires a reason.');
        }

        if (mb_strlen($reason) > 500) {
            throw new DomainException('A return reason may not exceed 500 characters.');
        }

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();
        $actorId = auth()->id();

        return DB::transaction(function () use (
            $subjectAllocationId,
            $assessmentPeriodId,
            $classGroupId,
            $decision,
            $reason,
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

            if ($approval->status !== MarkApproval::STATUS_SUBMITTED) {
                throw new DomainException(
                    "Marks in state '{$approval->status}' cannot be returned; only a submitted batch can be."
                );
            }

            $returned = DB::table('marks')
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('assessment_period_id', $assessmentPeriodId)
                ->whereIn('enrollment_id', $this->enrollmentIds($classGroupId))
                ->where('workflow_state', WorkflowState::Submitted->value)
                ->update([
                    'workflow_state' => WorkflowState::Draft->value,
                    'submitted_by' => null,
                    'submitted_at' => null,
                    'updated_at' => $now,
                ]);

            $moved = DB::table('mark_approvals')
                ->where('id', $approval->getKey())
                ->where('version', $approval->version)
                ->where('status', MarkApproval::STATUS_SUBMITTED)
                ->update([
                    'status' => $decision->resultingBatchStatus(),
                    'last_decision' => $decision->value,
                    'returned_by' => $actorId,
                    'returned_at' => $now,
                    'return_reason' => $reason,
                    'mark_count' => $returned,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            if ($moved === 0) {
                throw new DomainException(
                    'Another user changed this submission while you were returning it; nothing was returned.'
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
                    'reason' => $reason,
                    'marks_returned' => $returned,
                ],
                actor: $actor,
            );

            return [
                'approval' => MarkApproval::query()->where('id', $approval->getKey())->firstOrFail(),
                'returned' => $returned,
            ];
        });
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
