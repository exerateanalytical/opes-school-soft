<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\Amendment;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/01-assessment.md 15 - C10, corrections are CLASS-WIDE.
 *
 * **The defect this Action exists to fix.** A post-publication mark correction
 * changes that student's average, which changes the class mean, min, max, pass
 * rate, standard deviation and every other student's rank - all of which are
 * already printed on 61 other cards. v1 treated a correction as a single-student
 * edit, which silently made 61 cards wrong and left the school unable to say
 * which ones. So this Action:
 *
 *   1. applies the approved mark changes, each through the optimistic lock and
 *      each audited (00-core 10.6);
 *   2. recomputes THE ENTIRE CLASS GROUP, not one student;
 *   3. increments `PeriodPublication.generation`;
 *   4. writes a new generation of snapshots for EVERY enrollment in the class
 *      group, and sets `superseded_by_snapshot_id` on the previous generation -
 *      which is **retained, never deleted**, because a parent holding a paper
 *      card printed from generation 1 must be able to have that exact document
 *      reproduced;
 *   5. returns the set of students whose PRINTED VALUES changed, so the school
 *      knows exactly which cards to recall.
 *
 * Point 5 is the deliverable. `affected_enrollment_ids` is routinely much larger
 * than one, and T15 asserts precisely that.
 *
 * **`rank_freeze_policy`.** `reissue_class` recomputes ranks and statistics -
 * correct, and expensive. `freeze_at_publication` updates the corrected
 * student's own numbers but reuses the generation-1 rank and class-statistics
 * blocks verbatim and prints "Classement figé au ...". That option is not a
 * compromise of convenience: a school will not recall 62 cards for a
 * 0.25-point correction, and a product that pretends otherwise produces
 * off-ledger manual edits it cannot see.
 */
final class AmendMarks
{
    public function __construct(
        private readonly RenderReportCard $renderer = new RenderReportCard,
        private readonly PublishPeriod $publisher = new PublishPeriod,
    ) {}

    /**
     * @param  list<array{mark_id: int, version: int, state?: string, score?: string|null, comment?: string|null}>  $markChanges
     * @return array{
     *     amendment_id: int,
     *     from_generation: int,
     *     to_generation: int,
     *     affected_enrollment_ids: list<int>,
     *     snapshots_written: int,
     *     superseded: int,
     *     mark_changes: list<array<string, mixed>>
     * }
     */
    public function handle(
        int $periodPublicationId,
        array $markChanges,
        string $reason,
        string $rankFreezePolicy = Amendment::POLICY_REISSUE_CLASS,
    ): array {
        // 15.1: "approval is Principal-level". Amending reissues issued
        // documents, so the right that governs it is the right to publish them.
        Gate::authorize(Permission::ReportsPublish->value);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'An amendment must state its reason: an unexplained change to an issued report card is '
                    .'exactly what 01-assessment 15.1 makes impossible.',
            ]);
        }

        if (! in_array($rankFreezePolicy, [Amendment::POLICY_REISSUE_CLASS, Amendment::POLICY_FREEZE_AT_PUBLICATION], true)) {
            throw ValidationException::withMessages([
                'rank_freeze_policy' => "Unknown rank freeze policy `{$rankFreezePolicy}` (01-assessment 15.2).",
            ]);
        }

        if ($markChanges === []) {
            throw ValidationException::withMessages([
                'mark_changes' => 'An amendment with no mark changes would increment a generation and reissue 62 '
                    .'cards for no reason.',
            ]);
        }

        $actor = $this->currentActor();

        return DB::transaction(function () use ($periodPublicationId, $markChanges, $reason, $rankFreezePolicy, $actor): array {
            /** @var PeriodPublication $publication */
            $publication = PeriodPublication::query()
                ->whereKey($periodPublicationId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($publication->status !== PeriodPublication::STATUS_PUBLISHED) {
                throw ValidationException::withMessages([
                    'period_publication_id' => 'Only a published class group can be amended; nothing has been issued '
                        .'that would need superseding.',
                ]);
            }

            $fromGeneration = $publication->generation;
            $toGeneration = $fromGeneration + 1;
            $periodId = $publication->assessment_period_id;
            $classGroupId = $publication->class_group_id;

            // The generation-1 cards, as printed. Read BEFORE the marks move,
            // because the whole output of this Action is a comparison against
            // them.
            $previous = $this->snapshotsAt($periodPublicationId, $fromGeneration);
            $frozen = $rankFreezePolicy === Amendment::POLICY_FREEZE_AT_PUBLICATION
                ? $this->frozenBlocks($previous, $publication)
                : null;

            $applied = $this->applyMarkChanges($markChanges, $actor);

            // 15.2 step 2: recompute the ENTIRE class group.
            $collected = $this->renderer->collect($periodId, $classGroupId);

            // 15.2 step 3, as a conditional UPDATE with an affected-rows check:
            // two amendments racing on one publication must not both claim
            // generation 2 (00-core 10.4).
            $bumped = DB::table('period_publications')
                ->where('id', '=', $periodPublicationId)
                ->where('generation', '=', $fromGeneration)
                ->where('version', '=', $publication->version)
                ->update([
                    'generation' => $toGeneration,
                    'version' => $publication->version + 1,
                    'updated_at' => now(),
                ]);

            if ($bumped === 0) {
                throw ValidationException::withMessages([
                    'generation' => 'This publication moved to another generation while the amendment was being '
                        .'prepared; re-read it and re-approve.',
                ]);
            }

            $batchId = (string) Str::uuid();

            // 15.2 step 4, through the SAME snapshot writer publication uses.
            $written = $this->publisher->writeSnapshots(
                $periodId,
                $classGroupId,
                $publication,
                $collected,
                $publication->report_card_config_version_id ?? 0,
                $batchId,
                $toGeneration,
                $frozen,
            );

            $current = $this->snapshotsAt($periodPublicationId, $toGeneration);
            $superseded = $this->supersede($previous, $current);

            // 15.2 step 5: the students whose PRINTED VALUES changed.
            $affected = $this->affectedEnrollments($previous, $current);

            $amendment = new Amendment([
                'period_publication_id' => $periodPublicationId,
                'from_generation' => $fromGeneration,
                'to_generation' => $toGeneration,
                'reason' => $reason,
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'rank_freeze_policy' => $rankFreezePolicy,
                'affected_enrollment_ids' => $affected,
                'mark_changes' => $applied,
                'status' => Amendment::STATUS_APPLIED,
                'applied_at' => now(),
            ]);
            $amendment->save();

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: Amendment::class,
                auditableId: (int) $amendment->getKey(),
                before: ['generation' => $fromGeneration],
                after: [
                    'generation' => $toGeneration,
                    'reason' => $reason,
                    'rank_freeze_policy' => $rankFreezePolicy,
                    'marks_changed' => count($applied),
                    'cards_to_recall' => count($affected),
                    'snapshots_written' => $written,
                ],
                actor: $actor,
            );

            return [
                'amendment_id' => (int) $amendment->getKey(),
                'from_generation' => $fromGeneration,
                'to_generation' => $toGeneration,
                'affected_enrollment_ids' => $affected,
                'snapshots_written' => $written,
                'superseded' => $superseded,
                'mark_changes' => $applied,
            ];
        });
    }

    /**
     * 15.2 step 1. Each change goes through 00-core 10.6's optimistic lock, and
     * 0 affected rows is a rejection naming the conflicting value - never a
     * silent overwrite of whatever someone else wrote in the meantime.
     *
     * @param  list<array{mark_id: int, version: int, state?: string, score?: string|null, comment?: string|null}>  $markChanges
     * @return list<array<string, mixed>>
     */
    private function applyMarkChanges(array $markChanges, Actor $actor): array
    {
        $applied = [];

        foreach ($markChanges as $change) {
            $markId = $change['mark_id'];

            $before = DB::table('marks')->where('id', '=', $markId)->lockForUpdate()->first();

            if ($before === null) {
                throw ValidationException::withMessages([
                    'mark_id' => "Mark {$markId} does not exist.",
                ]);
            }

            $update = [
                'version' => (int) $before->version + 1,
                'updated_at' => now(),
            ];

            if (array_key_exists('state', $change)) {
                $update['state'] = $change['state'];
            }

            if (array_key_exists('score', $change)) {
                $update['score'] = $change['score'];
            }

            if (array_key_exists('comment', $change)) {
                $update['comment'] = $change['comment'];
            }

            $affected = DB::table('marks')
                ->where('id', '=', $markId)
                ->where('version', '=', $change['version'])
                ->update($update);

            if ($affected === 0) {
                throw ValidationException::withMessages([
                    'mark_id' => sprintf(
                        'Mark %d has moved on since it was read (stored version %d, amendment expected %d; its '
                        .'current value is %s). Re-read it before amending.',
                        $markId,
                        (int) $before->version,
                        $change['version'],
                        $before->score ?? $before->state,
                    ),
                ]);
            }

            $record = [
                'mark_id' => $markId,
                'enrollment_id' => (int) $before->enrollment_id,
                'subject_allocation_id' => (int) $before->subject_allocation_id,
                'component_id' => (int) $before->component_id,
                'before' => ['state' => $before->state, 'score' => $before->score],
                'after' => [
                    'state' => $update['state'] ?? $before->state,
                    'score' => array_key_exists('score', $update) ? $update['score'] : $before->score,
                ],
            ];

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: 'App\\Modules\\Assessment\\Models\\Mark',
                auditableId: $markId,
                before: $record['before'],
                after: $record['after'],
                actor: $actor,
            );

            $applied[] = $record;
        }

        return $applied;
    }

    /**
     * @return array<int, ReportCardSnapshot>  keyed by enrollment id
     */
    private function snapshotsAt(int $periodPublicationId, int $generation): array
    {
        /** @var array<int, ReportCardSnapshot> $snapshots */
        $snapshots = ReportCardSnapshot::query()
            ->where('period_publication_id', '=', $periodPublicationId)
            ->where('generation', '=', $generation)
            ->get()
            ->keyBy('enrollment_id')
            ->all();

        return $snapshots;
    }

    /**
     * 15.2 step 4. The superseded generation is RETAINED - only this one column
     * is written on it, which is exactly what the immutability trigger on
     * `report_card_snapshots` permits.
     *
     * @param  array<int, ReportCardSnapshot>  $previous
     * @param  array<int, ReportCardSnapshot>  $current
     */
    private function supersede(array $previous, array $current): int
    {
        $count = 0;

        foreach ($previous as $enrollmentId => $snapshot) {
            $successor = $current[$enrollmentId] ?? null;

            if ($successor === null) {
                continue;
            }

            DB::table('report_card_snapshots')
                ->where('id', '=', $snapshot->getKey())
                ->whereNull('superseded_by_snapshot_id')
                ->update([
                    'superseded_by_snapshot_id' => $successor->getKey(),
                    'updated_at' => now(),
                ]);

            $count++;
        }

        return $count;
    }

    /**
     * 15.2 step 5 - "the set of students whose PRINTED VALUES changed - average,
     * rank, mention, award, or any class statistic".
     *
     * Note what is compared: the printed projection, not the whole payload. A
     * generation-2 payload differs from generation 1 in its `issue` block for
     * every student by construction, and reporting all 62 as changed would tell
     * the school nothing.
     *
     * @param  array<int, ReportCardSnapshot>  $previous
     * @param  array<int, ReportCardSnapshot>  $current
     * @return list<int>
     */
    private function affectedEnrollments(array $previous, array $current): array
    {
        $affected = [];

        foreach ($current as $enrollmentId => $snapshot) {
            $before = $previous[$enrollmentId] ?? null;

            if ($before === null) {
                $affected[] = $enrollmentId;

                continue;
            }

            if ($this->printedValues($before) !== $this->printedValues($snapshot)) {
                $affected[] = $enrollmentId;
            }
        }

        sort($affected);

        return $affected;
    }

    /**
     * @return array<string, mixed>
     */
    private function printedValues(ReportCardSnapshot $snapshot): array
    {
        $payload = $snapshot->payload;

        return [
            'general_average' => $payload['general_average'] ?? null,
            'rank' => $payload['rank'] ?? null,
            'mention' => $payload['mention'] ?? null,
            'gpa' => $payload['gpa'] ?? null,
            'totals' => $payload['totals'] ?? null,
            'class_statistics' => $payload['class_statistics'] ?? null,
            'conseil' => $payload['conseil'] ?? null,
            'subjects' => $payload['subjects'] ?? null,
        ];
    }

    /**
     * 15.2 `freeze_at_publication`: ranks and class statistics stay at their
     * generation-1 values and the card says so.
     *
     * @param  array<int, ReportCardSnapshot>  $previous
     * @return array<int, array<string, mixed>>
     */
    private function frozenBlocks(array $previous, PeriodPublication $publication): array
    {
        $frozenAt = $publication->published_at?->toDateString();
        $blocks = [];

        foreach ($previous as $enrollmentId => $snapshot) {
            $payload = $snapshot->payload;

            $blocks[$enrollmentId] = [
                'rank' => $payload['rank'] ?? null,
                'class_statistics' => $payload['class_statistics'] ?? null,
                'frozen_at' => $frozenAt,
            ];
        }

        return $blocks;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
