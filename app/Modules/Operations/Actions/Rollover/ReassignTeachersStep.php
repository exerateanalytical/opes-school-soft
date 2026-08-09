<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Assessment\Actions\AssignAllocationTeacher;
use App\Modules\Operations\Domain\RolloverRunStatus;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/08-operations.md §6.2 step 9 - "Teacher reassignment".
 *
 * The new year's subject allocations (copied by step 3) start with no teacher
 * rows. This step fills `subject_allocation_teachers` - the assignment source
 * Mark::mayEnter() resolves (01-assessment §7.5) - through the Assessment
 * module's own door, AssignAllocationTeacher, never by writing the table
 * directly.
 *
 * Defaulting rule: each new-year allocation inherits the teachers of the
 * outgoing year's allocation with the same (class_level, stream, subject)
 * scope, EXCEPT teachers whose user account is no longer active - departed
 * staff are FLAGGED, not carried. The `$overrides` grid (the wizard's
 * reassignment screen) replaces the inherited set per allocation.
 *
 * Guard (spec, verbatim): "Refuses to leave a required allocation
 * unassigned." Required = active and not optional. The refusal happens
 * BEFORE any write and names each unassigned allocation - and the departed
 * teacher whose absence caused it, where that is the reason.
 *
 * The §7.5 delegation rule already covers any of the OLD year's unvalidated
 * marks: delegations attach to the old-year allocation and are untouched
 * here; a departed teacher's marks are validated through DelegateMarkEntry,
 * not by rewriting history.
 */
final class ReassignTeachersStep
{
    /** See PromoteStudentsStep::PERMISSION. */
    public const PERMISSION = 'rollover.run';

    public function __construct(private readonly AssignAllocationTeacher $assign) {}

    /**
     * @param  array<int, list<int>>  $overrides  new-year subject_allocation_id => user ids
     * @return array{assigned: int, inherited: int, overridden: int, departed_flagged: list<array{subject_allocation_id: int, user_id: int}>}
     */
    public function handle(int $runId, array $overrides, Actor $actor): array
    {
        Gate::authorize(self::PERMISSION);

        return DB::transaction(function () use ($runId, $overrides, $actor): array {
            $run = $this->lockRunAt($runId, RolloverStep::ReassignTeachers);

            $fromYearId = (int) $run->academic_year_from_id;
            $toYearId = (int) $run->academic_year_to_id;

            /** @var list<object{id: int|string, class_level_id: int|string, stream_id: int|string, subject_id: int|string, is_optional: int|bool, subject_code: string}> $allocations */
            $allocations = DB::table('subject_allocations as sa')
                ->join('subjects as s', 's.id', '=', 'sa.subject_id')
                ->where('sa.academic_year_id', $toYearId)
                ->where('sa.is_active', true)
                ->orderBy('sa.id')
                ->get(['sa.id', 'sa.class_level_id', 'sa.stream_id', 'sa.subject_id', 'sa.is_optional', 's.code as subject_code'])
                ->all();

            // Plan first, write second: the refusal must leave nothing half-assigned.
            $plan = [];
            $departedFlagged = [];
            $unassigned = [];
            $counts = ['assigned' => 0, 'inherited' => 0, 'overridden' => 0];

            foreach ($allocations as $allocation) {
                $allocationId = (int) $allocation->id;

                $existing = DB::table('subject_allocation_teachers')
                    ->where('subject_allocation_id', $allocationId)
                    ->pluck('user_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                if ($existing !== []) {
                    // Resumed run, or assigned by hand - idempotent skip.
                    continue;
                }

                if (array_key_exists($allocationId, $overrides)) {
                    $userIds = array_values(array_unique($overrides[$allocationId]));
                    $source = 'overridden';
                } else {
                    [$userIds, $departed] = $this->inheritedTeachers(
                        $fromYearId,
                        (int) $allocation->class_level_id,
                        (int) $allocation->stream_id,
                        (int) $allocation->subject_id,
                    );

                    foreach ($departed as $userId) {
                        $departedFlagged[] = ['subject_allocation_id' => $allocationId, 'user_id' => $userId];
                    }

                    $source = 'inherited';
                }

                if ($userIds === []) {
                    if (! (bool) $allocation->is_optional) {
                        $unassigned[] = sprintf(
                            '%s (allocation %d, level %d%s)',
                            $allocation->subject_code,
                            $allocationId,
                            (int) $allocation->class_level_id,
                            $this->departedNote($departedFlagged, $allocationId),
                        );
                    }

                    continue;
                }

                $plan[] = ['allocation_id' => $allocationId, 'user_ids' => $userIds, 'source' => $source];
            }

            if ($unassigned !== []) {
                throw new DomainException(
                    'Teacher reassignment refused - a required allocation may not be left unassigned (08-operations §6.2 step 9): '
                    .implode('; ', $unassigned)
                    .'. Assign a replacement in the reassignment grid.'
                );
            }

            foreach ($plan as $entry) {
                foreach ($entry['user_ids'] as $userId) {
                    $this->assign->handle($entry['allocation_id'], $userId, $actor);

                    $rowId = DB::table('subject_allocation_teachers')
                        ->where('subject_allocation_id', $entry['allocation_id'])
                        ->where('user_id', $userId)
                        ->value('id');

                    if (is_numeric($rowId)) {
                        $this->recordArtifact($run, 'subject_allocation_teachers', (int) $rowId);
                    }

                    $counts['assigned']++;
                }

                $counts[$entry['source']]++;
            }

            $this->advance($run, RolloverStep::ReassignTeachers, [
                'assigned' => $counts['assigned'],
                'inherited' => $counts['inherited'],
                'overridden' => $counts['overridden'],
                'departed_flagged' => count($departedFlagged),
            ]);

            return [
                'assigned' => $counts['assigned'],
                'inherited' => $counts['inherited'],
                'overridden' => $counts['overridden'],
                'departed_flagged' => $departedFlagged,
            ];
        });
    }

    /**
     * The outgoing year's teachers for the same allocation scope, split into
     * still-active (carried) and departed (flagged, never carried).
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function inheritedTeachers(int $fromYearId, int $classLevelId, int $streamId, int $subjectId): array
    {
        /** @var list<object{user_id: int|string, status: string|null}> $rows */
        $rows = DB::table('subject_allocations as sa')
            ->join('subject_allocation_teachers as sat', 'sat.subject_allocation_id', '=', 'sa.id')
            ->join('users as u', 'u.id', '=', 'sat.user_id')
            ->where('sa.academic_year_id', $fromYearId)
            ->where('sa.class_level_id', $classLevelId)
            ->where('sa.stream_id', $streamId)
            ->where('sa.subject_id', $subjectId)
            ->orderBy('sat.user_id')
            ->get(['sat.user_id', 'u.status'])
            ->all();

        $active = [];
        $departed = [];

        foreach ($rows as $row) {
            if ($row->status === 'active') {
                $active[] = (int) $row->user_id;
            } else {
                $departed[] = (int) $row->user_id;
            }
        }

        return [array_values(array_unique($active)), array_values(array_unique($departed))];
    }

    /**
     * @param  list<array{subject_allocation_id: int, user_id: int}>  $departedFlagged
     */
    private function departedNote(array $departedFlagged, int $allocationId): string
    {
        $userIds = [];

        foreach ($departedFlagged as $flag) {
            if ($flag['subject_allocation_id'] === $allocationId) {
                $userIds[] = (string) $flag['user_id'];
            }
        }

        return $userIds === []
            ? ''
            : ', previous teacher departed: user '.implode(', user ', $userIds);
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
            'step' => RolloverStep::ReassignTeachers->value,
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
