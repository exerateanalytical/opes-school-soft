<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Assessment\Models\PeriodPublication;
use App\Modules\Assessment\Models\ReportCardConfig;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Modules\Operations\Actions\AssertEntitlement;
use App\Support\Audit\Actor;
use App\Support\Score\Score;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Throwable;

/**
 * docs/specs/01-assessment.md 13.2 - C8, publication per class group.
 *
 * **Why one Action publishes MANY class groups, each in its OWN transaction.**
 * v1 published per period and globally, so one teacher late with one subject in
 * one class blocked report cards for the entire school; in a 30-class secondary
 * school that is a guaranteed weekly deadlock. T14 states the requirement
 * directly: one blocked class group must not block the other 29. That is a
 * transaction-boundary decision, not an error-handling one - a single
 * transaction spanning 30 class groups would roll all 30 back on the first
 * failure however carefully the failure were caught. So `handle()` loops and
 * `publishOne()` owns the transaction, and the return value is a per-class-group
 * report: 25 published, 3 blocked, with reasons, 2 already published.
 *
 * **Why the claim is a conditional UPDATE and not a status assignment**
 * (00-core 10.4, 11; the idiom is Students\PromoteMatriculeToOfficial's). Two
 * publishers racing on one class group must produce ONE snapshot batch (T17).
 * Two mechanisms, and neither is redundant:
 *
 *   1. `SELECT ... FOR UPDATE` on the `PeriodPublication` row serialises them,
 *      so the second publisher does not begin until the first has committed;
 *   2. the claim is then `UPDATE ... WHERE status IN (...) AND version = ?`,
 *      and **0 affected rows is the rejection**. The database decides, not a
 *      value this process read a moment ago. A read-then-write would let both
 *      publishers observe `marks_closed` and both write a batch.
 *
 * The gates are evaluated INSIDE the lock and BEFORE the claim, so a blocked
 * class group leaves the row in the status it was already in with its
 * `blocking_report` filled in - and reports every failure at once, because a
 * registrar who fixes one gate and is then told about the next will publish
 * late every term.
 */
final class PublishPeriod
{
    /** The statuses a publication may be claimed from. */
    private const CLAIMABLE = [
        PeriodPublication::STATUS_DRAFT,
        PeriodPublication::STATUS_MARKS_OPEN,
        PeriodPublication::STATUS_MARKS_CLOSED,
        PeriodPublication::STATUS_UNPUBLISHED,
    ];

    public const OUTCOME_PUBLISHED = 'published';

    public const OUTCOME_BLOCKED = 'blocked';

    public const OUTCOME_ALREADY_PUBLISHED = 'already_published';

    public const OUTCOME_FAILED = 'failed';

    public function __construct(
        private readonly RenderReportCard $renderer = new RenderReportCard,
    ) {}

    /**
     * Bulk publication (13.2): a selected set of class groups in one Action
     * with per-class-group results, never all-or-nothing.
     *
     * @param  list<int>  $classGroupIds
     * @return array{
     *     assessment_period_id: int,
     *     published: int,
     *     blocked: int,
     *     results: list<array{class_group_id: int, outcome: string, failures: list<string>, snapshot_batch_id: string|null, snapshots: int, generation: int}>
     * }
     */
    public function handle(int $periodId, array $classGroupIds, int $reportCardConfigId): array
    {
        Gate::authorize(Permission::ReportsPublish->value);

        // Entitlement gate (08-operations §4.4): report-card publication is
        // one of the four annual/termly operations blocked when
        // expired-enforced. Sits OUTSIDE the per-class-group loop so the
        // refusal is one clear sentence, not thirty "failed" rows.
        app(AssertEntitlement::class)->handle('assessment.publish_period');

        $actor = $this->currentActor();
        $configVersionId = $this->pinConfigVersion($reportCardConfigId);

        $results = [];
        $published = 0;
        $blocked = 0;

        foreach ($classGroupIds as $classGroupId) {
            try {
                $result = $this->publishOne($periodId, $classGroupId, $configVersionId, $actor);
            } catch (Throwable $e) {
                // One class group's unexpected failure is reported beside the
                // others rather than aborting the run. T14 again: 29 schools'
                // worth of cards do not wait on one broken configuration.
                $result = [
                    'class_group_id' => $classGroupId,
                    'outcome' => self::OUTCOME_FAILED,
                    'failures' => [$e->getMessage()],
                    'snapshot_batch_id' => null,
                    'snapshots' => 0,
                    'generation' => 0,
                ];
            }

            if ($result['outcome'] === self::OUTCOME_PUBLISHED) {
                $published++;
            }

            if ($result['outcome'] === self::OUTCOME_BLOCKED || $result['outcome'] === self::OUTCOME_FAILED) {
                $blocked++;
            }

            $results[] = $result;
        }

        app(WriteAuditEntry::class)->handle(
            action: AuditAction::Updated,
            module: 'Assessment',
            auditableType: PeriodPublication::class,
            auditableId: $periodId,
            after: [
                'assessment_period_id' => $periodId,
                'class_groups' => count($classGroupIds),
                'published' => $published,
                'blocked' => $blocked,
                'report_card_config_version_id' => $configVersionId,
            ],
            actor: $actor,
        );

        return [
            'assessment_period_id' => $periodId,
            'published' => $published,
            'blocked' => $blocked,
            'results' => $results,
        ];
    }

    /**
     * @return array{class_group_id: int, outcome: string, failures: list<string>, snapshot_batch_id: string|null, snapshots: int, generation: int}
     */
    private function publishOne(int $periodId, int $classGroupId, int $configVersionId, Actor $actor): array
    {
        return DB::transaction(function () use ($periodId, $classGroupId, $configVersionId, $actor): array {
            $publication = $this->lockPublication($periodId, $classGroupId, $actor);

            if ($publication->status === PeriodPublication::STATUS_PUBLISHED
                || $publication->status === PeriodPublication::STATUS_PUBLISHING) {
                // The second of two racing publishers arrives here, having
                // waited on the row lock the first held. It writes nothing, so
                // the publication keeps exactly ONE snapshot_batch_id (T17).
                return [
                    'class_group_id' => $classGroupId,
                    'outcome' => self::OUTCOME_ALREADY_PUBLISHED,
                    'failures' => [],
                    'snapshot_batch_id' => $publication->snapshot_batch_id,
                    'snapshots' => 0,
                    'generation' => $publication->generation,
                ];
            }

            $collected = $this->renderer->collect($periodId, $classGroupId);
            $failures = $this->gateFailures($periodId, $classGroupId, $configVersionId, $collected);

            if ($failures !== []) {
                DB::table('period_publications')
                    ->where('id', '=', $publication->getKey())
                    ->update([
                        'blocking_report' => json_encode(
                            ['evaluated_at' => now()->toIso8601String(), 'failures' => $failures],
                            JSON_THROW_ON_ERROR,
                        ),
                        'updated_at' => now(),
                    ]);

                return [
                    'class_group_id' => $classGroupId,
                    'outcome' => self::OUTCOME_BLOCKED,
                    'failures' => $failures,
                    'snapshot_batch_id' => null,
                    'snapshots' => 0,
                    'generation' => $publication->generation,
                ];
            }

            $batchId = (string) Str::uuid();

            // THE CLAIM (00-core 10.4). The WHERE clause is the guard and 0
            // affected rows is the rejection.
            $claimed = DB::table('period_publications')
                ->where('id', '=', $publication->getKey())
                ->whereIn('status', self::CLAIMABLE)
                ->where('version', '=', $publication->version)
                ->update([
                    'status' => PeriodPublication::STATUS_PUBLISHING,
                    'snapshot_batch_id' => $batchId,
                    'report_card_config_version_id' => $configVersionId,
                    'blocking_report' => null,
                    'version' => $publication->version + 1,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                return [
                    'class_group_id' => $classGroupId,
                    'outcome' => self::OUTCOME_ALREADY_PUBLISHED,
                    'failures' => [],
                    'snapshot_batch_id' => null,
                    'snapshots' => 0,
                    'generation' => $publication->generation,
                ];
            }

            $written = $this->writeSnapshots(
                $periodId,
                $classGroupId,
                $publication,
                $collected,
                $configVersionId,
                $batchId,
                $publication->generation,
                null,
            );

            DB::table('period_publications')
                ->where('id', '=', $publication->getKey())
                ->where('status', '=', PeriodPublication::STATUS_PUBLISHING)
                ->update([
                    'status' => PeriodPublication::STATUS_PUBLISHED,
                    'published_by' => $actor->id,
                    'published_at' => now(),
                    'version' => $publication->version + 2,
                    'updated_at' => now(),
                ]);

            return [
                'class_group_id' => $classGroupId,
                'outcome' => self::OUTCOME_PUBLISHED,
                'failures' => [],
                'snapshot_batch_id' => $batchId,
                'snapshots' => $written,
                'generation' => $publication->generation,
            ];
        });
    }

    /**
     * Compute the whole class group through the pipeline and write one snapshot
     * per enrollment.
     *
     * Shared verbatim with `AmendMarks`, which is the point: 15.2 step 4
     * requires an amendment to write "a new generation of snapshots for every
     * enrollment in the class group", and a second snapshot writer would be a
     * second chance to write them differently.
     *
     * @param  array{subject_results: array<int, list<\App\Modules\Assessment\Domain\SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @param  array<int, array<string, mixed>>|null  $frozenStatistics
     */
    public function writeSnapshots(
        int $periodId,
        int $classGroupId,
        PeriodPublication $publication,
        array $collected,
        int $configVersionId,
        string $batchId,
        int $generation,
        ?array $frozenStatistics,
    ): int {
        // Stage 5 and stage 6, in the module's single implementation of each
        // (2.2, 9.4, T23). This class computes nothing itself.
        app(ComputePeriodResults::class)->handle($periodId, $collected['subject_results']);
        app(ComputeRanking::class)->handle($periodId);

        $payloads = $this->renderer->resolvePayloads($periodId, $classGroupId, $collected, $frozenStatistics);
        $layout = ReportCardConfig::versionPayload($configVersionId);
        $issuedAt = now();
        $written = 0;

        foreach ($payloads as $enrollmentId => $payload) {
            // 13.7 / 15.2: "Version 2 - Emis le 14/03/2026" is printed on the
            // card, so it is part of the document and part of its hash.
            $payload['issue'] = [
                'generation' => $generation,
                'issued_at' => $issuedAt->toIso8601String(),
                'snapshot_batch_id' => $batchId,
            ];

            $card = $this->renderer->project($payload, $layout);

            $snapshot = new ReportCardSnapshot([
                'enrollment_id' => $enrollmentId,
                'assessment_period_id' => $periodId,
                'class_group_id' => $classGroupId,
                'period_publication_id' => (int) $publication->getKey(),
                'generation' => $generation,
                'snapshot_batch_id' => $batchId,
                'report_card_config_version_id' => $configVersionId,
                'payload' => $payload,
                'payload_hash' => ReportCardSnapshot::hashOf($payload),
                'issued_at' => $issuedAt,
                'pdf_hash' => ReportCardSnapshot::hashOf($card),
                'applied_policy_notes' => $collected['policy_notes'][$enrollmentId] ?? [],
            ]);

            $snapshot->save();
            $written++;
        }

        // 13.1: the version this batch pinned is now referenced by an issued
        // document and must never change again. ConfigureReportCard would
        // freeze it on the next edit; freezing it HERE closes the window in
        // between, in which an edit would still have found it unreferenced-
        // looking if the snapshot insert had not yet committed.
        DB::table('report_card_config_versions')
            ->where('id', '=', $configVersionId)
            ->whereNull('frozen_at')
            ->update(['frozen_at' => now(), 'updated_at' => now()]);

        return $written;
    }

    /**
     * 13.2's seven publication gates. All blocking, ALL REPORTED TOGETHER.
     *
     * @param  array{subject_results: array<int, list<\App\Modules\Assessment\Domain\SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @return list<string>
     */
    public function gateFailures(int $periodId, int $classGroupId, int $configVersionId, array $collected): array
    {
        $failures = [];
        $framework = $this->frameworkFor($periodId);
        $layout = ReportCardConfig::versionPayload($configVersionId);
        $blocks = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];

        if ($collected['enrollment_ids'] === []) {
            $failures[] = sprintf(
                'Class group %d has no enrollment whose segment covers the end of this period, so there is '
                .'nobody to issue a card to (01-assessment 12.6 rule 1).',
                $classGroupId,
            );
        }

        // Gates 1 and 4 come straight out of the pipeline: a component still
        // `pending` after `missing_component_policy` has been applied, and a
        // component-weight set that does not resolve to exactly 100.
        foreach ($collected['blocking'] as $blocker) {
            $failures[] = $blocker;
        }

        // Gate 2 - requires_hod_validation (7.4).
        if ($framework->requires_hod_validation && $collected['enrollment_ids'] !== []) {
            $unvalidated = DB::table('marks')
                ->where('assessment_period_id', '=', $periodId)
                ->whereIn('enrollment_id', $collected['enrollment_ids'])
                ->where('workflow_state', '!=', 'validated')
                ->count();

            if ($unvalidated > 0) {
                $failures[] = sprintf(
                    '%d mark(s) in this class group are not yet validated, and this framework requires HOD '
                    .'validation before publication (01-assessment 7.4).',
                    $unvalidated,
                );
            }
        }

        // Gate 3 - requires_conseil (13.4).
        if ($framework->requires_conseil && ! $this->conseilClosed($classGroupId, $periodId)) {
            $failures[] = 'This framework requires a conseil de classe, and no closed ConseilDeClasse exists for '
                .'this class group and period (01-assessment 13.4).';
        }

        // Gate 5 - GradeBand coverage (3.3). Validated by C1's own validator,
        // called rather than restated: two coverage checks that disagree at a
        // boundary is the defect 3.3 exists to end.
        $failures = [...$failures, ...$this->bandCoverageFailures($framework)];

        // Gates 6 and 7 - the class-master remark and the conduct block, each
        // only where the configuration enables AND requires it.
        $failures = [...$failures, ...$this->contentBlockFailures($blocks, $periodId, $collected['enrollment_ids'])];

        return array_values(array_unique($failures));
    }

    /**
     * 13.2 un-publication: explicit, permission-gated, reasoned, and it revokes
     * portal visibility immediately. **Snapshots are retained, never deleted** -
     * the card is simply no longer issuable, and a parent holding a printed copy
     * can still have it explained.
     *
     * @return array{publication_id: int, printed_copies: int}
     */
    public function unpublish(int $periodId, int $classGroupId, string $reason): array
    {
        Gate::authorize(Permission::ReportsPublish->value);

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Un-publication requires a reason: a card withdrawn from 62 families with no recorded '
                    .'justification is not an auditable act (01-assessment 13.2).',
            ]);
        }

        $actor = $this->currentActor();

        return DB::transaction(function () use ($periodId, $classGroupId, $reason, $actor): array {
            $publication = $this->lockPublication($periodId, $classGroupId, $actor);

            $affected = DB::table('period_publications')
                ->where('id', '=', $publication->getKey())
                ->where('status', '=', PeriodPublication::STATUS_PUBLISHED)
                ->update([
                    'status' => PeriodPublication::STATUS_UNPUBLISHED,
                    'unpublished_by' => $actor->id,
                    'unpublished_at' => now(),
                    'unpublish_reason' => $reason,
                    'version' => $publication->version + 1,
                    'updated_at' => now(),
                ]);

            if ($affected === 0) {
                throw ValidationException::withMessages([
                    'status' => "This class group's results for the period are not published, so there is nothing "
                        .'to un-publish.',
                ]);
            }

            // 13.2: "Un-publication of a period with printed cards raises a
            // warning naming the DocumentPrintLog count."
            $printed = DB::getSchemaBuilder()->hasTable('document_print_logs')
                ? DB::table('document_print_logs')
                    ->whereIn(
                        'snapshot_id',
                        DB::table('report_card_snapshots')
                            ->where('period_publication_id', '=', $publication->getKey())
                            ->select('id'),
                    )
                    ->count()
                : 0;

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'Assessment',
                auditableType: PeriodPublication::class,
                auditableId: (int) $publication->getKey(),
                before: ['status' => PeriodPublication::STATUS_PUBLISHED],
                after: [
                    'status' => PeriodPublication::STATUS_UNPUBLISHED,
                    'reason' => $reason,
                    'printed_copies' => $printed,
                ],
                actor: $actor,
            );

            return [
                'publication_id' => (int) $publication->getKey(),
                'printed_copies' => $printed,
            ];
        });
    }

    /**
     * `SELECT ... FOR UPDATE` on the contested row, creating it first if this is
     * the first publication attempt for the pair.
     *
     * The create races against another publisher's create; UNIQUE(period,
     * class_group) settles it and the loser re-reads under the lock.
     */
    public function lockPublication(int $periodId, int $classGroupId, Actor $actor): PeriodPublication
    {
        $publication = PeriodPublication::query()
            ->where('assessment_period_id', '=', $periodId)
            ->where('class_group_id', '=', $classGroupId)
            ->lockForUpdate()
            ->first();

        if ($publication instanceof PeriodPublication) {
            return $publication;
        }

        try {
            $created = new PeriodPublication([
                'assessment_period_id' => $periodId,
                'class_group_id' => $classGroupId,
                'status' => PeriodPublication::STATUS_MARKS_CLOSED,
                'generation' => 1,
                'version' => 1,
                'created_by' => $actor->id,
            ]);
            $created->save();
        } catch (UniqueConstraintViolationException) {
            // Another publisher created it between the SELECT and the INSERT.
        }

        $publication = PeriodPublication::query()
            ->where('assessment_period_id', '=', $periodId)
            ->where('class_group_id', '=', $classGroupId)
            ->lockForUpdate()
            ->first();

        if (! $publication instanceof PeriodPublication) {
            throw ValidationException::withMessages([
                'class_group_id' => "No publication row could be established for class group {$classGroupId}.",
            ]);
        }

        return $publication;
    }

    /**
     * @return list<string>
     */
    private function bandCoverageFailures(AssessmentFramework $framework): array
    {
        $bands = GradeBand::query()
            ->where('framework_id', '=', $framework->getKey())
            ->where('purpose', '=', GradeBand::PURPOSE_INTERNAL)
            ->where('scale_basis', '=', GradeBand::BASIS_OUT_OF_MAX)
            ->orderBy('min_score')
            ->get()
            ->map(static fn (GradeBand $band): array => [
                'min_score' => $band->min_score,
                'max_score' => $band->max_score,
                'label' => $band->label,
                'label_fr' => $band->label_fr,
                'mention' => $band->mention,
                'grade_point' => $band->grade_point,
                'is_pass' => $band->is_pass,
                'colour' => $band->colour,
            ])
            ->all();

        try {
            ConfigureGradeBands::validateCoverage(array_values($bands), Score::of($framework->max_score));
        } catch (DomainException $e) {
            return ['Grade band coverage is invalid, so a score could band to nothing: '.$e->getMessage()];
        }

        return [];
    }

    /**
     * @param  array<array-key, mixed>  $blocks
     * @param  list<int>  $enrollmentIds
     * @return list<string>
     */
    private function contentBlockFailures(array $blocks, int $periodId, array $enrollmentIds): array
    {
        $failures = [];

        // Gate 6 - the class-master remark, if the block is enabled and marked
        // required.
        $remarks = $blocks['remarks'] ?? null;

        if (is_array($remarks) && ($remarks['enabled'] ?? false) === true && ($remarks['class_master_required'] ?? false) === true) {
            if (! DB::getSchemaBuilder()->hasTable('report_card_remarks')) {
                $failures[] = 'The configuration requires a class-master remark on every card, but remark capture '
                    .'is not installed in this build (01-assessment 12.1).';
            } else {
                $missing = count($enrollmentIds) - DB::table('report_card_remarks')
                    ->where('assessment_period_id', '=', $periodId)
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->where('scope', '=', 'class_master')
                    ->distinct()
                    ->count('enrollment_id');

                if ($missing > 0) {
                    $failures[] = sprintf('%d student(s) have no class-master remark, which this configuration requires.', $missing);
                }
            }
        }

        // Gate 7 - the conduct block, same conditions.
        $conduct = $blocks['conduct'] ?? null;

        if (is_array($conduct) && ($conduct['enabled'] ?? false) === true && ($conduct['required'] ?? false) === true) {
            if (! DB::getSchemaBuilder()->hasTable('conduct_assessments')) {
                $failures[] = 'The configuration requires a conduct assessment on every card, but conduct capture '
                    .'is not installed in this build (01-assessment 12.3).';
            } else {
                $missing = count($enrollmentIds) - DB::table('conduct_assessments')
                    ->where('assessment_period_id', '=', $periodId)
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->distinct()
                    ->count('enrollment_id');

                if ($missing > 0) {
                    $failures[] = sprintf('%d student(s) have no conduct assessment, which this configuration requires.', $missing);
                }
            }
        }

        return $failures;
    }

    private function conseilClosed(int $classGroupId, int $periodId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('conseil_de_classes')) {
            return false;
        }

        return DB::table('conseil_de_classes')
            ->where('class_group_id', '=', $classGroupId)
            ->where('assessment_period_id', '=', $periodId)
            ->where('status', '=', 'closed')
            ->exists();
    }

    private function pinConfigVersion(int $reportCardConfigId): int
    {
        /** @var ReportCardConfig $config */
        $config = ReportCardConfig::query()->findOrFail($reportCardConfigId);

        $versionId = $config->currentVersionId();

        if ($versionId === null) {
            throw ValidationException::withMessages([
                'report_card_config_id' => 'This report card configuration has no version. Publication pins a '
                    .'version, never the config head, because a re-render must reproduce the layout the card was '
                    .'issued in (01-assessment 13.1).',
            ]);
        }

        return $versionId;
    }

    private function frameworkFor(int $periodId): AssessmentFramework
    {
        $frameworkId = DB::table('assessment_periods')->where('id', '=', $periodId)->value('framework_id');

        if (! is_numeric($frameworkId)) {
            throw ValidationException::withMessages([
                'framework_id' => 'This assessment period has no assessment framework; publication gates cannot be '
                    .'evaluated (01-assessment 3.1).',
            ]);
        }

        /** @var AssessmentFramework $framework */
        $framework = AssessmentFramework::query()->findOrFail((int) $frameworkId);

        return $framework;
    }

    private function currentActor(): Actor
    {
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
