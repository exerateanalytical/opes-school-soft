<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverRun;
use App\Modules\Students\Actions\EnrollStudent;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/08-operations.md §6.2 step 6 - "Promote students". Consumes the
 * promotion decisions recorded through Students\Actions\RecordPromotionDecision
 * and creates the new year's Enrollments THROUGH Students\Actions\EnrollStudent
 * - the owning module's door, which enforces capacity under lock, the C1
 * one-live-enrollment constraint and the segment invariant. This step never
 * touches the enrollments table directly.
 *
 * Guard (spec, verbatim): "Refuses if any class group has undecided students;
 * lists them." The refusal happens BEFORE any write.
 *
 * `is_repeat` on the new enrollment comes from the DECISION, not from a
 * person flag (§6.2's own emphasis).
 *
 * Idempotency (§6.3): a student who already has an enrollment in the new year
 * - because a killed run is being resumed, or because the registrar enrolled
 * them by hand - is SKIPPED and counted, not double-enrolled; EnrollStudent's
 * unique constraints back this check at the database layer.
 *
 * Target resolution: `target_class_group_key` is resolved against the NEW
 * year at execution time (the destination group may not have existed when the
 * decision was taken - step 2 creates it). Two documented forms:
 *   - "group:<name>"           -> the new-year class group with that name;
 *   - "level:<class_level_id>" -> the new year's single group on that level
 *                                 (refused as ambiguous if there are several).
 */
final class PromoteStudentsStep
{
    /**
     * Phase-07 plan §3: Identity\Domain\Permission::RolloverRun is added by
     * the wiring agent (F5). The VALUE is fixed by the plan, so the step
     * gates on it by string - spatie resolves it the moment the seeder runs.
     */
    public const PERMISSION = 'rollover.run';

    public function __construct(private readonly EnrollStudent $enroll) {}

    /**
     * @return array{promoted: int, repeated: int, skipped: int, leavers: int}
     */
    public function handle(int $runId, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        // $actor documents intent at the call boundary; EnrollStudent (like
        // WithdrawStudent) resolves its own audit actor from the
        // authenticated user, so the closure needs no capture.
        return DB::transaction(function () use ($runId): array {
            $run = $this->lockRunAt($runId, RolloverStep::PromoteStudents);

            $toYearId = (int) $run->academic_year_to_id;
            $toYearStartsOn = DB::table('academic_years')->where('id', $toYearId)->value('starts_on');

            if (! is_string($toYearStartsOn)) {
                throw new DomainException("Rollover run {$runId}: the new academic year does not exist; run step 1 first.");
            }

            $decisions = $this->decisionsForRun($run);

            $counts = ['promoted' => 0, 'repeated' => 0, 'skipped' => 0, 'leavers' => 0];

            foreach ($decisions as $decision) {
                if (in_array($decision->decision, ['graduated', 'withdrawn'], true)) {
                    // Step 8 (ArchiveLeaversStep) owns their exit.
                    $counts['leavers']++;

                    continue;
                }

                $alreadyEnrolled = DB::table('enrollments')
                    ->where('student_id', (int) $decision->student_id)
                    ->where('academic_year_id', $toYearId)
                    ->exists();

                if ($alreadyEnrolled) {
                    $counts['skipped']++;

                    continue;
                }

                $groupId = $this->resolveTarget((string) $decision->target_class_group_key, $toYearId);
                $isRepeat = $decision->decision === 'repeat';

                $enrollment = $this->enroll->handle(
                    studentId: (int) $decision->student_id,
                    academicYearId: $toYearId,
                    classGroupId: $groupId,
                    enrolledOn: Carbon::parse($toYearStartsOn)->toDateString(),
                    isRepeat: $isRepeat,
                );

                $enrollmentId = (int) $enrollment->getKey();
                $this->recordArtifact($run, 'enrollments', $enrollmentId);

                $segmentId = DB::table('enrollment_segments')
                    ->where('enrollment_id', $enrollmentId)
                    ->whereNull('ends_on')
                    ->value('id');

                if (is_numeric($segmentId)) {
                    $this->recordArtifact($run, 'enrollment_segments', (int) $segmentId);
                }

                $counts[$isRepeat ? 'repeated' : 'promoted']++;
            }

            $this->advance($run, RolloverStep::PromoteStudents, $counts);

            return $counts;
        });
    }

    /**
     * Live enrollments of the outgoing year, each with its decision - or a
     * refusal LISTING the class groups that still hold undecided students.
     *
     * @return list<object{enrollment_id: int|string, student_id: int|string, decision: string, target_class_group_key: string|null}>
     */
    private function decisionsForRun(RolloverRun $run): array
    {
        /** @var list<object{enrollment_id: int|string, student_id: int|string, decision: string|null, target_class_group_key: string|null, group_name: string|null}> $rows */
        $rows = DB::table('enrollments as e')
            ->leftJoin('promotion_decisions as pd', 'pd.enrollment_id', '=', 'e.id')
            ->leftJoin('enrollment_segments as es', function ($join): void {
                $join->on('es.enrollment_id', '=', 'e.id')->whereNull('es.ends_on');
            })
            ->leftJoin('class_groups as cg', 'cg.id', '=', 'es.class_group_id')
            ->where('e.academic_year_id', $run->academic_year_from_id)
            ->whereIn('e.status', ['pending', 'active', 'suspended'])
            ->orderBy('e.id')
            ->get([
                'e.id as enrollment_id',
                'e.student_id',
                'pd.decision',
                'pd.target_class_group_key',
                'cg.name as group_name',
            ])
            ->all();

        $undecidedByGroup = [];

        foreach ($rows as $row) {
            if ($row->decision === null) {
                $group = $row->group_name ?? '(no class group)';
                $undecidedByGroup[$group] = ($undecidedByGroup[$group] ?? 0) + 1;
            }
        }

        if ($undecidedByGroup !== []) {
            ksort($undecidedByGroup);
            $list = implode(', ', array_map(
                static fn (string $group, int $count): string => "{$group} ({$count} undecided)",
                array_keys($undecidedByGroup),
                array_values($undecidedByGroup),
            ));

            throw new DomainException(
                "Promotion refused: every live enrollment in the outgoing year needs a decision first - {$list}."
            );
        }

        /** @var list<object{enrollment_id: int|string, student_id: int|string, decision: string, target_class_group_key: string|null}> $rows */
        return $rows;
    }

    private function resolveTarget(string $key, int $toYearId): int
    {
        if (str_starts_with($key, 'group:')) {
            $name = substr($key, strlen('group:'));

            $ids = DB::table('class_groups')
                ->where('academic_year_id', $toYearId)
                ->where('name', $name)
                ->pluck('id')
                ->all();

            if (count($ids) === 1) {
                return (int) $ids[0];
            }

            throw new DomainException(
                count($ids) === 0
                    ? "Promotion target '{$key}': no class group named '{$name}' exists in the new year - run step 2 (copy class groups) first, or correct the decision."
                    : "Promotion target '{$key}' is ambiguous: several new-year class groups are named '{$name}'."
            );
        }

        if (str_starts_with($key, 'level:')) {
            $levelId = (int) substr($key, strlen('level:'));

            $ids = DB::table('class_groups')
                ->where('academic_year_id', $toYearId)
                ->where('class_level_id', $levelId)
                ->pluck('id')
                ->all();

            if (count($ids) === 1) {
                return (int) $ids[0];
            }

            throw new DomainException(
                count($ids) === 0
                    ? "Promotion target '{$key}': the new year has no class group on class level {$levelId}."
                    : "Promotion target '{$key}' is ambiguous: class level {$levelId} has several groups in the new year; decide 'group:<name>' instead."
            );
        }

        throw new DomainException(
            "Promotion target '{$key}' is not resolvable; expected 'group:<name>' or 'level:<class_level_id>'."
        );
    }

    private function lockRunAt(int $runId, RolloverStep $step): RolloverRun
    {
        /** @var RolloverRun $run */
        $run = RolloverRun::query()->lockForUpdate()->findOrFail($runId);

        if (! $run->status()->isResumable()) {
            throw new DomainException("Rollover run {$runId} is {$run->status} and cannot execute steps.");
        }

        if (! $step->isRunnableAt($run->currentStep())) {
            throw new DomainException(sprintf(
                'Rollover run %d stands at step %d (%s); step %d (%s) is not runnable now - steps execute strictly in order.',
                $runId,
                $run->current_step,
                $run->currentStep()->name,
                $step->value,
                $step->name,
            ));
        }

        if ($run->academic_year_to_id === null) {
            throw new DomainException("Rollover run {$runId} has no destination year yet; run step 1 first.");
        }

        return $run;
    }

    private function recordArtifact(RolloverRun $run, string $entityType, int $entityId): void
    {
        RolloverArtifact::query()->firstOrCreate([
            'rollover_run_id' => (int) $run->getKey(),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ], [
            'step' => RolloverStep::PromoteStudents->value,
        ]);
    }

    /**
     * @param  array<string, int>  $state
     */
    private function advance(RolloverRun $run, RolloverStep $step, array $state): void
    {
        $states = $run->step_states ?? [];
        $states[(string) $step->value] = $state + ['completed_at' => Carbon::now()->toIso8601String()];

        // Steps 6-9 always have a successor (the flip is step 10); the
        // coalesce is the defensive arm for a hypothetical last step.
        $next = $step->next() ?? $step;

        $run->forceFill([
            'step_states' => $states,
            'current_step' => $next->value,
            'status' => RolloverRunStatus::Running->value,
        ])->save();
    }
}
