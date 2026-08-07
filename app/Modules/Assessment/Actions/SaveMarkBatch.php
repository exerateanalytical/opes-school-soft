<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\EffectiveMaximum;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\WorkflowState;
use App\Modules\Assessment\Models\Mark;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use App\Support\Clock\BusinessDate;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The ONE write path for marks entry (docs/specs/01-assessment.md §17).
 *
 * The entry grid is 62 students wide and the screen keeps its keystrokes in a
 * local Alpine store precisely so that a save is **one request, one
 * transaction** — 62 rows × 2 components at one round trip per cell is 124
 * requests, which is unusable on school Wi-Fi. So this Action:
 *
 *   - reads the window ONCE, at transaction start, in `Africa/Douala` (§7.6);
 *   - reads the whole batch of marks in ONE SELECT (no per-row lookup);
 *   - resolves the effective maximum ONCE per component (§6.3);
 *   - applies each row under the optimistic lock (§7.7);
 *   - writes ONE audit entry for the batch;
 *
 * and returns a per-row outcome so the grid can show conflicts inline.
 *
 * On a conflict nothing is overwritten. The row comes back with the OTHER
 * party's value and the OTHER party's NAME, because "someone else changed this
 * to 14 while you had it open" is the only message a teacher can act on;
 * silent last-write-wins — v1's behaviour — loses an afternoon of work with no
 * trace.
 */
final class SaveMarkBatch
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{mark_id: int, version: int, state: string, score?: string|null, comment?: string|null}>  $rows
     * @return array{saved: list<int>, conflicts: list<array{mark_id: int, expected_version: int, current_version: int, their_score: string|null, their_state: string, their_actor_id: int|null, their_actor_name: string, changed_at: string|null, message: string}>}
     */
    public function handle(
        int $subjectAllocationId,
        int $assessmentPeriodId,
        array $rows,
        ?string $idempotencyKey = null,
    ): array {
        // The outer gate. It is NOT the whole check: §7.5 scopes entry to the
        // allocations the actor teaches or has been delegated, and T22 asserts
        // that a Teacher can neither read nor write anything else.
        Gate::authorize(Permission::MarksEnter->value);
        Mark::assertMayEnter($subjectAllocationId);

        if ($rows === []) {
            return ['saved' => [], 'conflicts' => []];
        }

        $actor = auth()->user()?->toAuditActor() ?? Actor::system();
        $actorId = auth()->id();

        return DB::transaction(function () use (
            $subjectAllocationId,
            $assessmentPeriodId,
            $rows,
            $idempotencyKey,
            $actor,
            $actorId,
        ): array {
            // §7.6: evaluated ONCE, at transaction start, against the business
            // clock — never per row, or a batch straddling midnight would
            // half-save.
            $now = Carbon::now(BusinessDate::TIMEZONE);
            $this->assertEntryWindowOpen($assessmentPeriodId, $now);

            $maxByComponent = $this->effectiveMaxima($subjectAllocationId, $assessmentPeriodId);

            /** @var array<int, array{version: int, state: string, score: string|null}> $intent */
            $intent = [];

            foreach ($rows as $row) {
                $intent[$row['mark_id']] = [
                    'version' => $row['version'],
                    'state' => $row['state'],
                    'score' => $row['score'] ?? null,
                ];
            }

            // ONE select for the whole grid. Scoped to the allocation and the
            // period as well as the ids, so a crafted mark_id from another
            // class cannot ride in on an authorised batch.
            $marks = Mark::query()
                ->whereIn('id', array_keys($intent))
                ->where('subject_allocation_id', $subjectAllocationId)
                ->where('assessment_period_id', $assessmentPeriodId)
                ->get()
                ->keyBy('id');

            $saved = [];
            $conflicts = [];
            $changes = [];

            foreach ($rows as $row) {
                $markId = $row['mark_id'];
                $mark = $marks->get($markId);

                if (! $mark instanceof Mark) {
                    throw new DomainException(
                        "Mark {$markId} does not belong to this subject allocation and period."
                    );
                }

                $state = MarkState::from($row['state']);
                $score = $this->coerceScore($state, $row['score'] ?? null);

                $this->assertScoreInRange($mark->component_id, $score, $maxByComponent);

                if ($state->requiresReason() && trim((string) ($row['comment'] ?? $mark->comment)) === '') {
                    throw new DomainException(
                        'Certifying an absence or granting an exemption requires a reason (§6.4).'
                    );
                }

                // §7.4: a validated mark is not editable. It must be returned
                // first, by someone holding the validating role.
                if (! $mark->workflow_state->isEditable()) {
                    throw new DomainException(
                        "Mark {$markId} has been validated and can no longer be edited; it must be returned first."
                    );
                }

                // Editing a submitted mark un-submits it rather than silently
                // amending a batch that is already under review (§7.4).
                $workflow = WorkflowState::Draft;

                // §7.7, 00-core §10.4/§10.6: conditional UPDATE with an
                // affected-rows check. Never read-then-write.
                $affected = DB::table('marks')
                    ->where('id', $markId)
                    ->where('version', $row['version'])
                    ->update([
                        'score' => $score?->toString(),
                        'state' => $state->value,
                        'workflow_state' => $workflow->value,
                        'submitted_by' => null,
                        'submitted_at' => null,
                        'comment' => $row['comment'] ?? $mark->comment,
                        'entered_by' => $actorId,
                        'entered_at' => $now,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => $now,
                    ]);

                if ($affected === 0) {
                    $conflicts[] = $markId;

                    continue;
                }

                $saved[] = $markId;
                $changes[$markId] = [
                    'before' => ['score' => $mark->score, 'state' => $mark->state->value],
                    'after' => ['score' => $score?->toString(), 'state' => $state->value],
                ];
            }

            $resolved = $conflicts === [] ? [] : $this->describeConflicts($conflicts, $intent);

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: Mark::class,
                // A batch has no single id. The scope is carried in `after`.
                auditableId: null,
                before: ['changes' => array_map(
                    static fn (array $c): mixed => $c['before'],
                    $changes,
                )],
                after: [
                    'subject_allocation_id' => $subjectAllocationId,
                    'assessment_period_id' => $assessmentPeriodId,
                    'idempotency_key' => $idempotencyKey,
                    'saved_count' => count($saved),
                    'conflict_count' => count($resolved),
                    'changes' => array_map(static fn (array $c): mixed => $c['after'], $changes),
                ],
                actor: $actor,
            );

            return ['saved' => $saved, 'conflicts' => $resolved];
        });
    }

    /**
     * §7.6. `marks_entry_closes_at` names the last moment of entry. When it
     * carries no time of day the operator meant the closing DATE, so the whole
     * of that local day is inside the window — which is exactly the 00:30 case
     * T18 names. Evaluating this against UTC would place that save on the
     * previous calendar day and refuse it (00-core §7.5).
     */
    private function assertEntryWindowOpen(int $assessmentPeriodId, Carbon $now): void
    {
        /** @var object{marks_entry_opens_at: string|null, marks_entry_closes_at: string|null}|null $period */
        $period = DB::table('assessment_periods')
            ->select('marks_entry_opens_at', 'marks_entry_closes_at')
            ->where('id', $assessmentPeriodId)
            ->first();

        if ($period === null) {
            throw new DomainException("Assessment period {$assessmentPeriodId} does not exist.");
        }

        if (is_string($period->marks_entry_opens_at)) {
            $opens = Carbon::parse($period->marks_entry_opens_at, BusinessDate::TIMEZONE);

            if ($now->lessThan($opens)) {
                throw new DomainException(
                    'Marks entry for this period opens on '.$opens->format('d/m/Y H:i').' (Africa/Douala).'
                );
            }
        }

        if (is_string($period->marks_entry_closes_at)) {
            $closes = Carbon::parse($period->marks_entry_closes_at, BusinessDate::TIMEZONE);

            if ($closes->format('H:i:s') === '00:00:00') {
                $closes = $closes->endOfDay();
            }

            if ($now->greaterThan($closes)) {
                throw new DomainException(
                    'Marks entry for this period closed on '.$closes->format('d/m/Y H:i').' (Africa/Douala).'
                );
            }
        }
    }

    /**
     * §6.3, resolved once per component for the whole batch.
     *
     * @return array<int, Score>
     */
    private function effectiveMaxima(int $subjectAllocationId, int $assessmentPeriodId): array
    {
        /** @var object{max_score_override: string|null}|null $allocation */
        $allocation = DB::table('subject_allocations')
            ->select('max_score_override')
            ->where('id', $subjectAllocationId)
            ->first();

        if ($allocation === null) {
            throw new DomainException("Subject allocation {$subjectAllocationId} does not exist.");
        }

        $override = is_string($allocation->max_score_override)
            ? Score::of($allocation->max_score_override)
            : null;

        /** @var object{framework_id: int|null}|null $period */
        $period = DB::table('assessment_periods')
            ->select('framework_id')
            ->where('id', $assessmentPeriodId)
            ->first();

        $frameworkId = $period?->framework_id;

        $frameworkMaxRaw = $frameworkId === null
            ? null
            : DB::table('assessment_frameworks')->where('id', $frameworkId)->value('max_score');

        // A framework is mandatory before marks can be entered, but the column
        // is nullable while Phase 1 periods exist without one; fall back to the
        // component's own maximum rather than guessing a scale.
        $frameworkMax = is_string($frameworkMaxRaw) ? Score::of($frameworkMaxRaw) : null;

        $maxima = [];

        $componentQuery = DB::table('assessment_components')->select('id', 'code', 'max_score');

        if ($frameworkId !== null) {
            $componentQuery->where('framework_id', $frameworkId);
        }

        /** @var iterable<object{id: int, code: string, max_score: string}> $components */
        $components = $componentQuery->get();

        foreach ($components as $component) {
            $componentMax = Score::of($component->max_score);

            $maxima[(int) $component->id] = EffectiveMaximum::resolve(
                $override,
                $componentMax,
                $frameworkMax ?? $componentMax,
                $component->code,
            );
        }

        return $maxima;
    }

    /**
     * @param  array<int, Score>  $maxByComponent
     */
    private function assertScoreInRange(int $componentId, ?Score $score, array $maxByComponent): void
    {
        if ($score === null) {
            return;
        }

        $max = $maxByComponent[$componentId] ?? null;

        if ($max === null) {
            throw new DomainException(
                "No assessment component {$componentId} is configured, so no maximum can be resolved."
            );
        }

        if ($score->isGreaterThan($max)) {
            throw new DomainException(
                'A mark of '.$score->toDisplayString().' exceeds the maximum of '.$max->toDisplayString().'.'
            );
        }
    }

    /**
     * Invariant 4: (state = scored) <=> (score IS NOT NULL). A missing
     * component is not a zero, so a score arriving with `absent_justified` is
     * rejected rather than quietly dropped.
     */
    private function coerceScore(MarkState $state, ?string $raw): ?Score
    {
        if ($state->requiresScore()) {
            if ($raw === null || trim($raw) === '') {
                throw new DomainException('A scored mark must carry a number.');
            }

            return Score::of($raw);
        }

        if ($raw !== null && trim($raw) !== '') {
            throw new DomainException(
                "State '{$state->value}' cannot carry a score; a missing component is not a zero (§6.4)."
            );
        }

        return null;
    }

    /**
     * The conflict payload the grid shows inline: what the other party set,
     * and who they are.
     *
     * `users` is read through the query builder, never Identity's Model
     * (00-core §6.2 rule 2) — the precedent Phase 2's Enrollment documents.
     *
     * @param  list<int>  $markIds
     * @param  array<int, array{version: int, state: string, score: string|null}>  $intent
     * @return list<array{mark_id: int, expected_version: int, current_version: int, their_score: string|null, their_state: string, their_actor_id: int|null, their_actor_name: string, changed_at: string|null, message: string}>
     */
    private function describeConflicts(array $markIds, array $intent): array
    {
        /** @var iterable<object{id: int, version: int, score: string|null, state: string, entered_by: int|null, entered_at: string|null, actor_name: string|null}> $current */
        $current = DB::table('marks')
            ->leftJoin('users', 'users.id', '=', 'marks.entered_by')
            ->whereIn('marks.id', $markIds)
            ->select(
                'marks.id',
                'marks.version',
                'marks.score',
                'marks.state',
                'marks.entered_by',
                'marks.entered_at',
                'users.name as actor_name',
            )
            ->get();

        $out = [];

        foreach ($current as $row) {
            $markId = (int) $row->id;
            $name = $row->actor_name ?? 'another user';
            $value = $row->score ?? MarkState::from($row->state)->printedMarker() ?? $row->state;

            $out[] = [
                'mark_id' => $markId,
                'expected_version' => $intent[$markId]['version'] ?? 0,
                'current_version' => (int) $row->version,
                'their_score' => $row->score,
                'their_state' => $row->state,
                'their_actor_id' => $row->entered_by === null ? null : (int) $row->entered_by,
                'their_actor_name' => $name,
                'changed_at' => $row->entered_at,
                'message' => "{$name} changed this to {$value} while you had it open; your value was not saved.",
            ];
        }

        return $out;
    }
}
