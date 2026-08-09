<?php

declare(strict_types=1);

namespace App\Modules\Students\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\PromotionDecision;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/plans/phase-07.md decision 4 - the Students-module door the rollover
 * wizard's step-6 decision grid writes through. One decision per enrollment
 * (unique by constraint); re-recording UPDATES the decision, which is how an
 * operator corrects a mistake before the promotion step consumes it.
 *
 * Phase 8's full promotion engine builds ON promotion_decisions; this Action
 * stays the only writer until then.
 *
 * Validation stated here, not in the UI:
 *  - promoted / repeat need a `target_class_group_key` (the destination in
 *    the NEW year, resolved by PromoteStudentsStep at execution time, since
 *    the destination group may not exist yet when the decision is taken);
 *  - graduated / withdrawn must NOT carry a target - there is nowhere to go;
 *  - graduated is only available to an exit-level class (`is_exam_class`,
 *    07-students 3.2's graduation rule);
 *  - the enrollment must still be LIVE - a terminal enrollment's fate is
 *    already fact, not a pending decision.
 */
final class RecordPromotionDecision
{
    public const PERMISSION = Permission::StudentsManage->value;

    /** @var list<string> */
    private const DECISIONS = [
        PromotionDecision::DECISION_PROMOTED,
        PromotionDecision::DECISION_REPEAT,
        PromotionDecision::DECISION_GRADUATED,
        PromotionDecision::DECISION_WITHDRAWN,
    ];

    /** @var list<string> */
    private const NEEDS_TARGET = [
        PromotionDecision::DECISION_PROMOTED,
        PromotionDecision::DECISION_REPEAT,
    ];

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(
        int $enrollmentId,
        string $decision,
        ?string $targetClassGroupKey,
        Actor $actor,
    ): PromotionDecision {
        Gate::authorize(self::PERMISSION);

        if (! in_array($decision, self::DECISIONS, true)) {
            throw ValidationException::withMessages([
                'decision' => "'{$decision}' is not a promotion decision; expected one of: ".implode(', ', self::DECISIONS).'.',
            ]);
        }

        $needsTarget = in_array($decision, self::NEEDS_TARGET, true);

        if ($needsTarget && ($targetClassGroupKey === null || trim($targetClassGroupKey) === '')) {
            throw ValidationException::withMessages([
                'target_class_group_key' => "A '{$decision}' decision must name where the student goes next year.",
            ]);
        }

        if (! $needsTarget && $targetClassGroupKey !== null) {
            throw ValidationException::withMessages([
                'target_class_group_key' => "A '{$decision}' decision cannot carry a destination class group.",
            ]);
        }

        return DB::transaction(function () use ($enrollmentId, $decision, $targetClassGroupKey, $actor): PromotionDecision {
            /** @var Enrollment $enrollment */
            $enrollment = Enrollment::query()->lockForUpdate()->findOrFail($enrollmentId);

            if (! $enrollment->status->isLive()) {
                throw ValidationException::withMessages([
                    'enrollment_id' => "Enrollment {$enrollmentId} is {$enrollment->status->value}; a promotion decision applies only to a live enrollment.",
                ]);
            }

            if ($decision === PromotionDecision::DECISION_GRADUATED) {
                // Cross-module READ through the query builder - never an
                // Academics model (tests/Architecture/ModuleBoundaryTest.php).
                $isExamClass = DB::table('class_levels')
                    ->where('id', $enrollment->class_level_id)
                    ->value('is_exam_class');

                if (! (bool) $isExamClass) {
                    throw ValidationException::withMessages([
                        'decision' => 'Only a student in an exit-level class can graduate; this class level is not an exam class.',
                    ]);
                }
            }

            $existing = PromotionDecision::query()->where('enrollment_id', $enrollmentId)->first();

            $recorded = PromotionDecision::query()->updateOrCreate(
                ['enrollment_id' => $enrollmentId],
                [
                    'decision' => $decision,
                    'target_class_group_key' => $targetClassGroupKey,
                    'decided_by' => $actor->id,
                    'decided_at' => Carbon::now(),
                ],
            );

            $this->audit->handle(
                action: $existing === null ? AuditAction::Created : AuditAction::Updated,
                module: 'Students',
                auditableType: PromotionDecision::class,
                auditableId: (int) $recorded->getKey(),
                before: $existing === null ? null : [
                    'decision' => $existing->decision,
                    'target_class_group_key' => $existing->target_class_group_key,
                ],
                after: [
                    'enrollment_id' => $enrollmentId,
                    'decision' => $decision,
                    'target_class_group_key' => $targetClassGroupKey,
                ],
                actor: $actor,
            );

            return $recorded;
        });
    }
}
