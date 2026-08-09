<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Domain\PromotionOutcome;
use App\Modules\Students\Domain\PromotionRunStatus;
use App\Modules\Students\Models\PromotionDecision;
use App\Modules\Students\Models\PromotionRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/07-students.md §10.5 — the conseil's red pen.
 *
 * `computed_outcome` is retained beside `outcome` so a manual change is never
 * invisible, and `override_reason` is mandatory so the printed list explains
 * WHY the row differs from what the criteria computed. This is also the only
 * exit for `indeterminate` / `manual_review` rows when the run must apply —
 * the engine refuses to guess, a human decides on the record.
 *
 * The run moves to `under_review`: still applicable (§10.6's conditional
 * UPDATE accepts it), visibly no longer the untouched computed list.
 */
final class OverridePromotionDecision
{
    public const PERMISSION = Permission::PromotionEvaluate->value;

    /** @var list<PromotionOutcome> */
    private const DECISIVE = [
        PromotionOutcome::Promote,
        PromotionOutcome::ConditionalPromote,
        PromotionOutcome::Repeat,
        PromotionOutcome::Graduate,
        PromotionOutcome::Exclude,
    ];

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $promotionRunId,
        int $enrollmentId,
        PromotionOutcome $outcome,
        string $reason,
        ?int $targetClassGroupId = null,
        ?Actor $actor = null,
    ): PromotionDecision {
        Gate::authorize(self::PERMISSION);

        if (! in_array($outcome, self::DECISIVE, true)) {
            throw ValidationException::withMessages([
                'outcome' => "An override must decide; '{$outcome->value}' is not a decision.",
            ]);
        }

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'override_reason' => 'An override must say why the computed outcome is wrong.',
            ]);
        }

        $writer = $actor ?? $this->currentActor();

        if ($writer->id === null) {
            throw ValidationException::withMessages([
                'actor' => 'An override must be attributed to a user.',
            ]);
        }

        return DB::transaction(function () use (
            $promotionRunId, $enrollmentId, $outcome, $reason, $targetClassGroupId, $writer
        ): PromotionDecision {
            /** @var PromotionRun $run */
            $run = PromotionRun::query()->lockForUpdate()->findOrFail($promotionRunId);

            if (! in_array($run->status, [PromotionRunStatus::Evaluated, PromotionRunStatus::UnderReview], true)) {
                throw ValidationException::withMessages([
                    'promotion_run_id' => "A {$run->status->value} run cannot be overridden; "
                        .'the review window is between evaluation and apply.',
                ]);
            }

            /** @var PromotionDecision|null $decision */
            $decision = PromotionDecision::query()
                ->where('promotion_run_id', $promotionRunId)
                ->where('enrollment_id', $enrollmentId)
                ->lockForUpdate()
                ->first();

            if ($decision === null) {
                throw ValidationException::withMessages([
                    'enrollment_id' => 'This enrollment is not part of the promotion run.',
                ]);
            }

            $targetLevelId = $this->resolveTargetLevel(
                $run,
                $decision,
                $outcome,
                $targetClassGroupId,
            );

            $before = [
                'outcome' => $decision->outcome?->value,
                'overridden' => $decision->overridden,
            ];

            $decision->forceFill([
                'outcome' => $outcome,
                'decision' => $outcome->legacyDecision(),
                'target_class_level_id' => $targetLevelId,
                'target_class_group_id' => $targetClassGroupId,
                'overridden' => true,
                'override_reason' => trim($reason),
                'overridden_by' => $writer->id,
            ])->save();

            if ($run->status !== PromotionRunStatus::UnderReview) {
                $run->forceFill(['status' => PromotionRunStatus::UnderReview])->save();
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Students',
                auditableType: PromotionDecision::class,
                auditableId: (int) $decision->getKey(),
                before: $before,
                after: [
                    'outcome' => $outcome->value,
                    'computed_outcome' => $decision->computed_outcome,
                    'override_reason' => trim($reason),
                    'target_class_group_id' => $targetClassGroupId,
                ],
                actor: $writer,
            );

            return $decision;
        });
    }

    /**
     * Where does the overridden student land? Repeat stays on the
     * enrollment's level; promote moves to the next level of the section (or
     * to the level of an explicitly chosen target group); graduate / exclude
     * go nowhere. All Academics reads via the query builder.
     */
    private function resolveTargetLevel(
        PromotionRun $run,
        PromotionDecision $decision,
        PromotionOutcome $outcome,
        ?int $targetClassGroupId,
    ): ?int {
        if (! $outcome->createsNextYearEnrollment()) {
            if ($targetClassGroupId !== null) {
                throw ValidationException::withMessages([
                    'target_class_group_id' => "A '{$outcome->value}' override cannot carry a destination class group.",
                ]);
            }

            return null;
        }

        $enrollmentLevelId = DB::table('enrollments')
            ->where('id', $decision->enrollment_id)
            ->value('class_level_id');
        $enrollmentLevelId = is_numeric($enrollmentLevelId) ? (int) $enrollmentLevelId : null;

        if ($targetClassGroupId !== null) {
            $group = DB::table('class_groups')->where('id', $targetClassGroupId)->first();

            if ($group === null) {
                throw ValidationException::withMessages([
                    'target_class_group_id' => 'The target class group does not exist.',
                ]);
            }

            if ((int) $group->academic_year_id !== $run->target_academic_year_id) {
                throw ValidationException::withMessages([
                    'target_class_group_id' => "The target class group belongs to a different year than the run's target year.",
                ]);
            }

            return (int) $group->class_level_id;
        }

        if ($outcome === PromotionOutcome::Repeat) {
            return $enrollmentLevelId;
        }

        if ($enrollmentLevelId === null) {
            return null;
        }

        $level = DB::table('class_levels')->where('id', $enrollmentLevelId)->first();

        if ($level === null) {
            return null;
        }

        $next = DB::table('class_levels')
            ->where('school_section_id', (int) $level->school_section_id)
            ->where('order_index', '>', (int) $level->order_index)
            ->orderBy('order_index')
            ->value('id');

        if (! is_numeric($next)) {
            throw ValidationException::withMessages([
                'outcome' => 'There is no higher class level in this section to promote into; '
                    .'choose an explicit target class group or a different outcome.',
            ]);
        }

        return (int) $next;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
