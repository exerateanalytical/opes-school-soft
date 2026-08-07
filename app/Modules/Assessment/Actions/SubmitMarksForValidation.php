<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\ApprovalDecision;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Assessment\Models\MarkApproval;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Step one of the approval chain (docs/specs/01-assessment.md §7.2):
 * the subject teacher sends the grid up.
 *
 * §17 is explicit that this is a **separate, explicit action** and never a
 * side effect of saving — a teacher must be able to save a half-finished grid
 * without declaring it finished.
 *
 * Only `workflow_state` moves. `state` is untouched: a certified absence stays
 * a certified absence through submission, validation and publication, which is
 * the whole reason the two columns are separate (§7.1).
 */
final class SubmitMarksForValidation
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return array{approval: MarkApproval, submitted: int, still_pending: int}
     */
    public function handle(
        int $subjectAllocationId,
        int $assessmentPeriodId,
        int $classGroupId,
    ): array {
        Gate::authorize(ApprovalDecision::Submit->requiredPermission());
        Gate::authorize(Permission::MarksEnter->value);
        Mark::assertMayEnter($subjectAllocationId);

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();
        $actorId = auth()->id();

        return DB::transaction(function () use (
            $subjectAllocationId,
            $assessmentPeriodId,
            $classGroupId,
            $actor,
            $actorId,
        ): array {
            $now = Carbon::now(BusinessDate::TIMEZONE);
            $enrollmentIds = $this->enrollmentIds($classGroupId);

            $approval = $this->header($subjectAllocationId, $assessmentPeriodId, $classGroupId);
            $decision = ApprovalDecision::Submit;

            // The state machine refuses illegal transitions BEFORE it touches
            // the marks: submitting a batch that is already validated is not a
            // no-op, it is a misunderstanding of where the grid stands.
            if (! in_array($approval->status, [MarkApproval::STATUS_OPEN, MarkApproval::STATUS_RETURNED], true)) {
                throw new DomainException(
                    "These marks are already {$approval->status}; they cannot be submitted again."
                );
            }

            $scope = DB::table('marks')
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('assessment_period_id', $assessmentPeriodId)
                ->whereIn('enrollment_id', $enrollmentIds);

            // §6.4: a pending component is never silently swept along. It is
            // counted and returned, and it is what blocks publication later.
            $stillPending = (clone $scope)->where('state', 'pending')->count();

            $submitted = (clone $scope)
                ->where('workflow_state', WorkflowState::Draft->value)
                ->where('state', '<>', 'pending')
                ->update([
                    'workflow_state' => WorkflowState::Submitted->value,
                    'submitted_by' => $actorId,
                    'submitted_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($submitted === 0) {
                throw new DomainException('There are no draft marks in this scope to submit.');
            }

            // 00-core §10.4: conditional UPDATE with an affected-rows check.
            // Two teachers clicking Submit at the same instant produce one
            // submission and one refusal, never two headers claiming the act.
            $moved = DB::table('mark_approvals')
                ->where('id', $approval->getKey())
                ->where('version', $approval->version)
                ->whereIn('status', [MarkApproval::STATUS_OPEN, MarkApproval::STATUS_RETURNED])
                ->update([
                    'status' => $decision->resultingBatchStatus(),
                    'last_decision' => $decision->value,
                    'submitted_by' => $actorId,
                    'submitted_at' => $now,
                    'return_reason' => null,
                    'mark_count' => $submitted,
                    'version' => DB::raw('version + 1'),
                    'updated_at' => $now,
                ]);

            if ($moved === 0) {
                throw new DomainException(
                    'Another user changed this submission while you were submitting it; nothing was submitted.'
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
                    'marks_submitted' => $submitted,
                    'still_pending' => $stillPending,
                ],
                actor: $actor,
            );

            $fresh = MarkApproval::query()->where('id', $approval->getKey())->firstOrFail();

            return [
                'approval' => $fresh,
                'submitted' => $submitted,
                'still_pending' => $stillPending,
            ];
        });
    }

    /**
     * The class list, read through the query builder: `enrollment_segments`
     * belongs to Students and 00-core §6.2 rule 2 forbids importing its Model.
     * The OPEN segment is the student's current class group (07-students §5.2).
     *
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

        if ($ids === []) {
            throw new DomainException('This class group has no enrolled students.');
        }

        return $ids;
    }

    private function header(int $subjectAllocationId, int $assessmentPeriodId, int $classGroupId): MarkApproval
    {
        return MarkApproval::query()->firstOrCreate(
            [
                'subject_allocation_id' => $subjectAllocationId,
                'assessment_period_id' => $assessmentPeriodId,
                'class_group_id' => $classGroupId,
            ],
            ['status' => MarkApproval::STATUS_OPEN, 'version' => 1],
        );
    }
}
