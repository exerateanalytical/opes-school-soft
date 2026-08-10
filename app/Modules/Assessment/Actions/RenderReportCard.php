<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions;

use App\Modules\Assessment\Domain\ClassStatistics;
use App\Modules\Assessment\Domain\ComponentMark;
use App\Modules\Assessment\Domain\GradingPipeline;
use App\Modules\Assessment\Domain\MarkState;
use App\Modules\Assessment\Domain\Ranking;
use App\Modules\Assessment\Domain\Rounding;
use App\Modules\Assessment\Domain\SubjectInput;
use App\Modules\Assessment\Domain\SubjectResult;
use App\Modules\Assessment\Models\AssessmentFramework;
use App\Modules\Assessment\Models\GradeBand;
use App\Modules\Assessment\Models\PeriodResult;
use App\Modules\Assessment\Models\ReportCardConfig;
use App\Modules\Assessment\Models\ReportCardSnapshot;
use App\Support\Score\Score;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * docs/specs/01-assessment.md 12, 13.3, 13.5-13.7, 14 - the report card itself.
 *
 * **The class has two halves and they must not be confused, because T13 is the
 * difference between them.**
 *
 * `collect()` / `resolvePayload()` run ONCE, inside a publication or an
 * amendment, against live rows. They perform stage 1 (COLLECT) of 2.2 and hand
 * the result to `Assessment\Domain\GradingPipeline`; every number in the
 * returned document comes back out of that pipeline, `Ranking`,
 * `ClassStatistics`, `PassRule` or `ComputePeriodResults`. There is no
 * arithmetic in this file - 9.4 and T23 make a second implementation of the
 * average a review-blocking defect, and v1 shipped exactly that: the bulletin
 * printed 13.13 and the promotion list said 13.28 for one student in one run.
 *
 * `handle()` / `render()` run EVERY TIME A CARD IS PRINTED. They read the
 * snapshot's stored payload and the frozen config version it pinned, and they
 * touch `marks`, `subject_allocations`, `grade_bands` and the config head
 * NEVER. That is not an optimisation; it is the guarantee. T13 publishes,
 * mutates all four of those, re-renders and asserts the `pdf_hash` has not
 * moved. A single re-derivation anywhere in the render half fails it, which is
 * why the two halves do not share a code path.
 *
 * **On `pdf_hash` and the absence of a PDF library.** No PDF renderer is
 * installed in this repository, and adding one is a dependency decision the
 * owner has not made. So `pdf_hash` is the SHA-256 of the canonical
 * serialisation of the RENDERED CARD - the resolved payload projected through
 * the pinned layout, which is the complete content of the document and the
 * thing whose stability the guarantee is about. When a PDF renderer lands, it
 * consumes exactly this structure and the hash becomes a hash of its bytes; the
 * column name is already the spec's.
 */
final class RenderReportCard
{
    /**
     * 14. MINESEC bulletins carry heures d'absence justifiees / non justifiees
     * plus retards, consignes and exclusions, and DAILY attendance cannot
     * produce hours - which is why 14 moves per-lesson attendance capture into
     * Phase 3's dependency set. It is not built. The card therefore prints a
     * NAMED HOLE and the snapshot stores that hole, because the one thing that
     * must not happen is a bulletin carrying an absence figure the system
     * invented.
     */
    private const ATTENDANCE_UNAVAILABLE_NOTE =
        'Per-lesson attendance capture is not yet installed, so absence hours cannot be reported for this '
        .'period (01-assessment 14). No figure is shown because no figure exists.';

    public function __construct(private readonly GradingPipeline $pipeline = new GradingPipeline) {}

    /**
     * Print an issued card.
     *
     * @return array{snapshot_id: int, generation: int, issued_at: string, config_version_id: int, card: array<string, mixed>, pdf_hash: string}
     */
    public function handle(int $snapshotId): array
    {
        /** @var ReportCardSnapshot $snapshot */
        $snapshot = ReportCardSnapshot::query()->findOrFail($snapshotId);

        return $this->render($snapshot);
    }

    /**
     * @return array{snapshot_id: int, generation: int, issued_at: string, config_version_id: int, card: array<string, mixed>, pdf_hash: string}
     */
    public function render(ReportCardSnapshot $snapshot): array
    {
        $layout = ReportCardConfig::versionPayload($snapshot->report_card_config_version_id);

        $card = $this->project($snapshot->payload, $layout);

        return [
            'snapshot_id' => (int) $snapshot->getKey(),
            'generation' => $snapshot->generation,
            'issued_at' => $snapshot->issued_at->toIso8601String(),
            'config_version_id' => $snapshot->report_card_config_version_id,
            'card' => $card,
            'pdf_hash' => ReportCardSnapshot::hashOf($card),
        ];
    }

    /**
     * Project a stored payload through a stored layout.
     *
     * Every value read here comes from one of the two arguments. If this method
     * ever needs a third source, T13 has been broken.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public function project(array $payload, array $layout): array
    {
        $blocks = is_array($layout['blocks'] ?? null) ? $layout['blocks'] : [];
        $columns = is_array($layout['marks_columns'] ?? null) ? $layout['marks_columns'] : [];

        $card = [
            'layout' => $layout['layout'] ?? 'default',
            'locale' => $layout['locale'] ?? 'fr',
            'branding' => $layout['branding'] ?? [],
            'period' => $payload['period'] ?? [],
            'class_group' => $payload['class_group'] ?? [],
            'student' => $payload['student'] ?? [],
            'issue' => $payload['issue'] ?? [],
            'columns' => [],
            'rows' => [],
            'blocks' => [],
        ];

        $subjects = is_array($payload['subjects'] ?? null) ? $payload['subjects'] : [];

        foreach ($columns as $column) {
            if (! is_array($column) || ! is_string($column['key'] ?? null)) {
                continue;
            }

            $key = $column['key'];

            // 8.4 / T19. The nursery card does not print rank, average, mention
            // or Sigma-coef, and "does not print" means the column is not
            // there. A blank Rang box on a nursery card invites a bursar to
            // fill it in, so a configuration that asks for one against a
            // Family F payload is dropped rather than rendered empty.
            if ($this->columnIsSuppressed($key, $payload)) {
                continue;
            }

            $card['columns'][] = [
                'key' => $key,
                'label' => $column['label_fr'] ?? $column['label'] ?? $key,
                'width' => $column['width'] ?? null,
                'period_ref' => $column['period_ref'] ?? null,
                'component_ref' => $column['component_ref'] ?? null,
            ];
        }

        foreach ($subjects as $subject) {
            if (! is_array($subject)) {
                continue;
            }

            $row = [];

            foreach ($card['columns'] as $column) {
                $row[] = $this->cell($column, $subject);
            }

            $card['rows'][] = $row;
        }

        foreach (ReportCardConfig::BLOCK_KEYS as $block) {
            if (! $this->blockEnabled($blocks, $block)) {
                continue;
            }

            $content = $this->blockContent($block, $payload);

            // A block whose payload key is ABSENT is not rendered as an empty
            // block: 8.4 again. `false` is the sentinel for "this payload does
            // not carry this concept at all".
            if ($content === false) {
                continue;
            }

            $card['blocks'][$block] = $content;
        }

        return $card;
    }

    /**
     * COLLECT (stage 1 of 2.2) for one class group, then stages 2-4 through the
     * pipeline.
     *
     * @return array{
     *     subject_results: array<int, list<SubjectResult>>,
     *     allocations: array<int, stdClass>,
     *     enrollment_ids: list<int>,
     *     blocking: list<string>,
     *     policy_notes: array<int, list<array<string, mixed>>>
     * }
     */
    public function collect(int $periodId, int $classGroupId): array
    {
        $period = $this->periodRow($periodId);
        $group = $this->classGroupRow($classGroupId);
        $framework = $this->framework($period);

        $enrollmentIds = $this->enrollmentIdsOwning($classGroupId, (string) $period->ends_on);
        $allocations = $this->allocations($group, $period);
        $components = $this->components((int) $framework->getKey());
        $marks = $this->marks($periodId, $enrollmentIds, array_keys($allocations));

        $frameworkMax = Score::of($framework->max_score);
        $policy = $framework->missing_component_policy;

        $subjectResults = [];
        $policyNotes = [];
        $blocking = [];

        foreach ($enrollmentIds as $enrollmentId) {
            $results = [];

            foreach ($allocations as $allocationId => $allocation) {
                [$componentMarks, $weightProblem] = $this->componentMarksFor(
                    $allocation,
                    $components,
                    $marks[$enrollmentId][$allocationId] ?? [],
                    (int) $framework->getKey(),
                    $periodId,
                );

                if ($weightProblem !== null) {
                    $blocking[$weightProblem] = true;

                    continue;
                }

                if ($componentMarks === []) {
                    continue;
                }

                $override = $allocation->max_score_override;

                $results[] = $this->pipeline->subjectScore(
                    new SubjectInput(
                        key: $allocationId,
                        components: $componentMarks,
                        coefficientHundredths: $this->hundredths((string) $allocation->coefficient),
                        maxScoreOverride: is_string($override) ? Score::of($override) : null,
                        countsTowardAverage: (bool) $allocation->counts_toward_average,
                    ),
                    $frameworkMax,
                    $policy,
                );
            }

            foreach ($results as $result) {
                foreach ($result->componentOutcomes as $outcome) {
                    if ($outcome->appliedPolicy !== null) {
                        $policyNotes[$enrollmentId][] = [
                            'subject_allocation_id' => $result->key,
                            'component_code' => $outcome->componentCode,
                            'original_state' => $outcome->originalState->value,
                            'effective_state' => $outcome->effectiveState->value,
                            'policy' => $outcome->appliedPolicy->value,
                        ];
                    }
                }

                if ($result->isBlocked()) {
                    $blocking[sprintf(
                        'Subject allocation %s still has pending marks (%s) for enrollment %d.',
                        (string) $result->key,
                        implode(', ', $result->blockingComponentCodes),
                        $enrollmentId,
                    )] = true;
                }
            }

            $subjectResults[$enrollmentId] = $results;
        }

        return [
            'subject_results' => $subjectResults,
            'allocations' => $allocations,
            'enrollment_ids' => $enrollmentIds,
            'blocking' => array_keys($blocking),
            'policy_notes' => $policyNotes,
        ];
    }

    /**
     * Build the fully-resolved printable document for every enrollment in a
     * class group.
     *
     * Called only from PublishPeriod and AmendMarks, inside their transaction,
     * after `ComputePeriodResults` and `ComputeRanking` have run - so the
     * average, rank, denominator, band and GPA below are READ from
     * `period_results`, which is where the pipeline put them. This class does
     * not recompute them; that is the whole of 9.4.
     *
     * @param  array{subject_results: array<int, list<SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @param  array<int, array<string, mixed>>|null  $frozenStatistics  15.2 `freeze_at_publication`: the generation-1 rank and statistics blocks, reused verbatim
     * @return array<int, array<string, mixed>>  payload per enrollment id
     */
    public function resolvePayloads(
        int $periodId,
        int $classGroupId,
        array $collected,
        ?array $frozenStatistics = null,
    ): array {
        $period = $this->periodRow($periodId);
        $group = $this->classGroupRow($classGroupId);
        $framework = $this->framework($period);
        $precision = $framework->score_precision;
        $passScore = Score::of($framework->pass_score);

        /** @var array<int, PeriodResult> $results */
        $results = PeriodResult::query()
            ->where('assessment_period_id', '=', $periodId)
            ->whereIn('enrollment_id', $collected['enrollment_ids'])
            ->get()
            ->keyBy('enrollment_id')
            ->all();

        // 10.7: statistics are computed INSIDE the publication transaction and
        // snapshotted, so a card printed a month later shows the class mean as
        // it was at publication. `ClassStatistics::of` is C1's pure domain -
        // population stdev, divisor n, stated in the field name.
        $averages = [];
        foreach ($collected['enrollment_ids'] as $enrollmentId) {
            $result = $results[$enrollmentId] ?? null;
            $rounded = $result === null ? null : $result->general_average_rounded;

            // 10.2 / 10.5: a NULL or NC student is excluded from every class
            // statistic, not entered as a zero. v1 entered 0.00 and printed the
            // student as the worst in the class.
            $averages[] = is_string($rounded) && $result !== null && $result->is_ranked
                ? Score::of($rounded)
                : null;
        }

        $classStats = ClassStatistics::of($averages, $passScore, $precision);
        $subjectStats = $this->subjectStatistics($collected, $passScore, $precision);
        $subjectRanks = $this->subjectRanks($collected, $precision);
        $students = $this->students($collected['enrollment_ids']);
        $subjectNames = $this->subjectNames($collected['allocations']);
        $bands = $this->bandsFor((int) $framework->getKey());

        $payloads = [];

        foreach ($collected['enrollment_ids'] as $enrollmentId) {
            $payloads[$enrollmentId] = $this->buildPayload(
                $enrollmentId,
                $period,
                $group,
                $framework,
                $collected,
                $results[$enrollmentId] ?? null,
                $classStats,
                $subjectStats,
                $subjectRanks,
                $students[$enrollmentId] ?? null,
                $subjectNames,
                $bands,
                $precision,
                $frozenStatistics[$enrollmentId] ?? null,
            );
        }

        return $payloads;
    }

    // -----------------------------------------------------------------------
    // Payload construction
    // -----------------------------------------------------------------------

    /**
     * @param  array{subject_results: array<int, list<SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @param  array<int|string, ClassStatistics>  $subjectStats
     * @param  array<int|string, array{denominator: int, by_enrollment: array<int, int|null>}>  $subjectRanks
     * @param  array<int|string, array{name: string, name_fr: string}>  $subjectNames
     * @param  list<GradeBand>  $bands
     * @param  array<string, mixed>|null  $frozen
     * @return array<string, mixed>
     */
    private function buildPayload(
        int $enrollmentId,
        stdClass $period,
        stdClass $group,
        AssessmentFramework $framework,
        array $collected,
        ?PeriodResult $result,
        ClassStatistics $classStats,
        array $subjectStats,
        array $subjectRanks,
        ?stdClass $student,
        array $subjectNames,
        array $bands,
        int $precision,
        ?array $frozen,
    ): array {
        $usesCoefficients = (bool) $framework->uses_coefficients;
        $usesRank = (bool) $framework->uses_rank;
        $isCompetencyOnly = $framework->family->isCompetencyOnly();

        $payload = [
            'schema' => 'opes.report_card.v1',
            'period' => [
                'id' => (int) $period->id,
                'code' => (string) $period->code,
                'name' => (string) $period->name,
                'name_fr' => (string) $period->name_fr,
                'type' => (string) $period->type,
                'starts_on' => (string) $period->starts_on,
                'ends_on' => (string) $period->ends_on,
            ],
            'class_group' => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
            ],
            'framework' => [
                'id' => (int) $framework->getKey(),
                'code' => $framework->code,
                'family' => $framework->family->value,
                'max_score' => $framework->max_score,
                'pass_score' => $framework->pass_score,
                'score_precision' => $precision,
                'uses_rank' => $usesRank,
                'uses_coefficients' => $usesCoefficients,
                'rank_cohort_rule' => $framework->rank_cohort_rule,
            ],
            'student' => $this->studentBlock($enrollmentId, $student),
            // 12.6 rule 3: the card lists prior segments in the period, e.g.
            // "Transfere de 5eA le 14/11/2025".
            'segments' => $this->segmentHistory($enrollmentId, $period),
            'subjects' => [],
            'attendance' => $this->attendanceHole(),
            'conduct' => $this->conductBlock($enrollmentId, (int) $period->id),
            'remarks' => $this->remarksBlock($enrollmentId, (int) $period->id),
            'conseil' => $this->conseilBlock($enrollmentId, (int) $group->id, (int) $period->id),
            'policy_notes' => $collected['policy_notes'][$enrollmentId] ?? [],
        ];

        $sumCoefficientHundredths = 0;
        $sumTimesCoefThousandths = 0;

        foreach ($collected['subject_results'][$enrollmentId] ?? [] as $subject) {
            $names = $subjectNames[$subject->key] ?? ['name' => 'Subject', 'name_fr' => 'Matière'];
            $stats = $subjectStats[$subject->key] ?? null;
            $band = $subject->score === null ? null : $this->bandFor($bands, $subject->score);

            $row = [
                'subject_allocation_id' => $subject->key,
                'subject_name' => $names['name'],
                'subject_name_fr' => $names['name_fr'],
                'subject_score' => $subject->score === null
                    ? null
                    : Rounding::halfUp($subject->score, $precision)->toDisplayString(),
                'is_unassessed' => $subject->isUnassessed(),
                'components' => $this->componentBlock($subject),
                'appreciation' => $band?->mention,
                'grade_letter' => $band?->label,
                'grade_point' => $band?->grade_point,
                'class_average_subject' => $stats?->mean?->toDisplayString(),
                'cote_min' => $stats?->min?->toDisplayString(),
                'cote_max' => $stats?->max?->toDisplayString(),
                'subject_rank' => isset($subjectRanks[$subject->key])
                    ? $subjectRanks[$subject->key]['by_enrollment'][$enrollmentId] ?? null
                    : null,
                'subject_rank_denominator' => isset($subjectRanks[$subject->key])
                    ? $subjectRanks[$subject->key]['denominator']
                    : null,
                // 13.5's `teacher_visa` column. The teacher's initials are a
                // manual signature on the printed card; the column is declared
                // so the layout reserves the space.
                'teacher_visa' => null,
                'teacher_name' => null,
            ];

            if ($usesCoefficients) {
                $row['coefficient'] = $this->decimal($subject->coefficientHundredths);
                $row['score_times_coef'] = $subject->score === null
                    ? null
                    : $this->timesCoefficient($subject->score, $subject->coefficientHundredths, $precision);

                if ($subject->contributesToAverage() && $subject->score !== null && $subject->coefficientHundredths > 0) {
                    $sumCoefficientHundredths += $subject->coefficientHundredths;
                    $sumTimesCoefThousandths += intdiv(
                        Rounding::halfUp($subject->score, $precision)->thousandths() * $subject->coefficientHundredths,
                        100,
                    );
                }
            }

            $payload['subjects'][] = $row;
        }

        if ($isCompetencyOnly) {
            // 8.4 and T19. Family F: rank, average, mention and Sigma-coef are
            // ABSENT - not zero, not blank-but-present. Everything below this
            // point is the numeric card, and the nursery card is not one.
            $payload['competency_note'] = 'Nursery reporting is observation-based (01-assessment 8).';

            return $payload;
        }

        if ($usesCoefficients) {
            // 13.6: mandatory when uses_coefficients. The totals row and the
            // stated derivation are where a Cameroonian reader - parent, class
            // master, inspector - checks the school's arithmetic by hand. A card
            // that prints only the final average cannot be verified and will be
            // rejected.
            $payload['totals'] = [
                'sum_coefficient' => $this->decimal($sumCoefficientHundredths),
                'sum_score_times_coef' => Score::ofThousandths($sumTimesCoefThousandths)->toDisplayString(),
                'derivation' => $this->derivation(
                    $sumTimesCoefThousandths,
                    $sumCoefficientHundredths,
                    $result?->general_average_rounded,
                ),
            ];
        }

        $rounded = $result === null ? null : $result->general_average_rounded;

        $payload['general_average'] = [
            'raw' => $result === null ? null : $result->general_average,
            'rounded' => $rounded,
            'display' => is_string($rounded) ? Score::of($rounded)->toDisplayString() : null,
            'is_pass' => $result === null ? null : $result->is_pass,
            'is_assessed' => $rounded !== null,
            'subjects_counted' => $result === null ? 0 : $result->subjects_counted,
        ];

        $payload['mention'] = is_string($rounded) ? $this->bandFor($bands, Score::of($rounded))?->mention : null;
        $payload['gpa'] = $result === null ? null : $result->gpa;

        if ($usesRank) {
            $payload['rank'] = $frozen['rank'] ?? [
                'position' => $result === null ? null : $result->rank_position,
                'denominator' => $result === null ? null : $result->rank_denominator,
                'is_ranked' => $result !== null && $result->is_ranked,
                'nc_reason' => $result === null ? null : $result->nc_reason,
                'cohort_rule' => $framework->rank_cohort_rule,
            ];
        }

        $payload['class_statistics'] = $frozen['class_statistics'] ?? [
            'n' => $classStats->n,
            'mean' => $classStats->mean?->toDisplayString(),
            'min' => $classStats->min?->toDisplayString(),
            'max' => $classStats->max?->toDisplayString(),
            'median' => $classStats->median?->toDisplayString(),
            // 10.7: "population standard deviation, divisor n - stated
            // explicitly on the report and in the API field name".
            'stdev_population' => $classStats->stdevPopulation?->toDisplayString(),
            'pass_count' => $classStats->passCount,
            'pass_rate_basis_points' => $classStats->passRate?->basisPoints(),
        ];

        if ($frozen !== null) {
            // 15.2: the card must SAY the ranking is frozen, otherwise a reader
            // comparing two cards from the same class sees an inconsistency
            // with no explanation.
            $payload['rank_frozen_at'] = $frozen['frozen_at'] ?? null;
        }

        return $payload;
    }

    // -----------------------------------------------------------------------
    // Stage 1 - COLLECT
    // -----------------------------------------------------------------------

    /**
     * @param  array<int, stdClass>  $components  keyed by component id
     * @param  array<int, stdClass>  $marks  keyed by component id
     * @return array{0: list<ComponentMark>, 1: string|null}  the marks, or a blocking reason
     */
    private function componentMarksFor(
        stdClass $allocation,
        array $components,
        array $marks,
        int $frameworkId,
        int $periodId,
    ): array {
        /** @var mixed $declared */
        $declared = json_decode((string) $allocation->required_components, true);
        $declaredIds = [];

        if (is_array($declared)) {
            foreach ($declared as $id) {
                if (is_numeric($id)) {
                    $declaredIds[] = (int) $id;
                }
            }
        }

        if ($declaredIds === []) {
            return [[], null];
        }

        $weights = $this->componentWeights($frameworkId, $periodId, (int) $allocation->id, $declaredIds);

        if ($weights === null) {
            return [[], sprintf(
                'Component weights for subject allocation %d are not configured, and 01-assessment 5.4 '
                .'requires them to sum to exactly 100 over the declared components. Nothing is guessed.',
                (int) $allocation->id,
            )];
        }

        $componentMarks = [];

        foreach ($declaredIds as $componentId) {
            $component = $components[$componentId] ?? null;

            if ($component === null) {
                return [[], sprintf(
                    'Subject allocation %d declares component %d, which is not a component of this framework.',
                    (int) $allocation->id,
                    $componentId,
                )];
            }

            $mark = $marks[$componentId] ?? null;
            $state = $mark === null
                ? MarkState::Pending
                : MarkState::from((string) $mark->state);
            $componentMax = is_string($component->max_score) ? Score::of($component->max_score) : null;
            $weight = $weights[$componentId];

            if ($state === MarkState::Scored && is_string($mark?->score)) {
                $componentMarks[] = ComponentMark::scored(
                    (string) $component->code,
                    Score::of($mark->score),
                    $componentMax,
                    $weight,
                );

                continue;
            }

            $componentMarks[] = ComponentMark::inState(
                (string) $component->code,
                $state === MarkState::Scored ? MarkState::Pending : $state,
                $componentMax,
                $weight,
            );
        }

        return [$componentMarks, null];
    }

    /**
     * 5.4 - `ComponentWeight`, resolved most-specific-first:
     * (period, allocation) -> (period, any) -> (any, allocation) -> (any, any),
     * with 0 as the "any" sentinel.
     *
     * **The `component_weights` table is not yet on disk.** It is 5.4's entity
     * and belongs to the marks-entry workstream, not to this one, so this
     * method reads it when it exists and falls back in exactly ONE case: a
     * subject declaring a SINGLE component, whose weight is then not a guess -
     * it is the only assignment satisfying 5.4's "Sigma weight = exactly 100".
     * Two or more declared components with no configured weights returns null
     * and BLOCKS publication, which is what 5.4 means by "nothing is guessed".
     * v1 accepted 30 + 60 = 90 and quietly marked a whole class out of 90 % of
     * the intended scale.
     *
     * @param  list<int>  $componentIds
     * @return array<int, int>|null  weight per component id, or null when unresolvable
     */
    private function componentWeights(int $frameworkId, int $periodId, int $allocationId, array $componentIds): ?array
    {
        if (Schema::hasTable('component_weights')) {
            foreach ([[$periodId, $allocationId], [$periodId, 0], [0, $allocationId], [0, 0]] as [$p, $a]) {
                $rows = DB::table('component_weights')
                    ->where('framework_id', '=', $frameworkId)
                    ->where('assessment_period_id', '=', $p)
                    ->where('subject_allocation_id', '=', $a)
                    ->whereIn('component_id', $componentIds)
                    ->pluck('weight', 'component_id');

                if ($rows->count() !== count($componentIds)) {
                    continue;
                }

                $weights = [];
                $sum = 0;

                foreach ($componentIds as $componentId) {
                    $weight = (int) $rows->get($componentId);
                    $weights[$componentId] = $weight;
                    $sum += $weight;
                }

                return $sum === 100 ? $weights : null;
            }

            return null;
        }

        if (count($componentIds) === 1) {
            return [$componentIds[0] => 100];
        }

        return null;
    }

    /**
     * 12.6 rule 1: the class group of the segment covering
     * `AssessmentPeriod.ends_on`. Read through the query builder because
     * `enrollment_segments` belongs to Students and
     * tests/Architecture/ModuleBoundaryTest.php is absolute.
     *
     * @return list<int>
     */
    public function enrollmentIdsOwning(int $classGroupId, string $endsOn): array
    {
        $rows = DB::table('enrollment_segments')
            ->where('class_group_id', '=', $classGroupId)
            ->where('starts_on', '<=', $endsOn)
            ->where(function (QueryBuilder $query) use ($endsOn): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $endsOn);
            })
            ->orderBy('enrollment_id')
            ->pluck('enrollment_id')
            ->all();

        $ids = [];

        foreach ($rows as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * 5.1: the allocation set is scoped to the YEAR and to a period range, so
     * deactivating a subject mid-year cannot rewrite an already-published card
     * (T3) and editing a coefficient cannot rewrite every historical bulletin.
     *
     * @return array<int, stdClass>  keyed by allocation id
     */
    private function allocations(stdClass $group, stdClass $period): array
    {
        $streamId = is_numeric($group->stream_id ?? null) ? (int) $group->stream_id : 0;
        $order = (int) $period->order_index;

        $rows = DB::table('subject_allocations')
            ->where('academic_year_id', '=', (int) $group->academic_year_id)
            ->where('class_level_id', '=', (int) $group->class_level_id)
            ->whereIn('stream_id', array_unique([0, $streamId]))
            ->where('is_active', '=', true)
            ->orderBy('id')
            ->get();

        $effective = [];

        foreach ($rows as $row) {
            if (! $this->allocationCoversPeriod($row, $order)) {
                continue;
            }

            $effective[(int) $row->id] = $row;
        }

        return $effective;
    }

    private function allocationCoversPeriod(stdClass $allocation, int $periodOrder): bool
    {
        foreach ([['effective_from_period_id', true], ['effective_to_period_id', false]] as [$column, $isFrom]) {
            $boundary = $allocation->{$column} ?? null;

            if (! is_numeric($boundary)) {
                continue;
            }

            $order = DB::table('assessment_periods')->where('id', '=', (int) $boundary)->value('order_index');

            if (! is_numeric($order)) {
                continue;
            }

            if ($isFrom && $periodOrder < (int) $order) {
                return false;
            }

            // `effective_to_period_id` is INCLUSIVE (5.1).
            if (! $isFrom && $periodOrder > (int) $order) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, stdClass>
     */
    private function components(int $frameworkId): array
    {
        $components = [];

        foreach (DB::table('assessment_components')->where('framework_id', '=', $frameworkId)->orderBy('order_index')->get() as $row) {
            $components[(int) $row->id] = $row;
        }

        return $components;
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @param  list<int>  $allocationIds
     * @return array<int, array<int, array<int, stdClass>>>  [enrollment][allocation][component]
     */
    private function marks(int $periodId, array $enrollmentIds, array $allocationIds): array
    {
        if ($enrollmentIds === [] || $allocationIds === []) {
            return [];
        }

        $marks = [];

        $rows = DB::table('marks')
            ->where('assessment_period_id', '=', $periodId)
            ->whereIn('enrollment_id', $enrollmentIds)
            ->whereIn('subject_allocation_id', $allocationIds)
            // 16.6: a re-sit is a second legitimate row; the latest attempt is
            // the one the card prints.
            ->orderBy('attempt_no')
            ->get();

        foreach ($rows as $row) {
            $marks[(int) $row->enrollment_id][(int) $row->subject_allocation_id][(int) $row->component_id] = $row;
        }

        return $marks;
    }

    // -----------------------------------------------------------------------
    // Per-subject statistics and ranks - both through C1's pure domain
    // -----------------------------------------------------------------------

    /**
     * 10.7, per subject allocation.
     *
     * @param  array{subject_results: array<int, list<SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @return array<int|string, ClassStatistics>
     */
    private function subjectStatistics(array $collected, Score $passScore, int $precision): array
    {
        $stats = [];

        foreach ($this->scoresByAllocation($collected) as $allocationId => $scores) {
            $stats[$allocationId] = ClassStatistics::of(array_values($scores), $passScore, $precision);
        }

        return $stats;
    }

    /**
     * 10.6. Population: the cohort restricted to students with a NON-NULL score
     * in that subject. Value: the stage-4 framework-scaled score, never the raw
     * component mark, which is not comparable across override maxima. Tie rule:
     * competition ranking, via C1's `Ranking`.
     *
     * @param  array{subject_results: array<int, list<SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @return array<int|string, array{denominator: int, by_enrollment: array<int, int|null>}>
     */
    private function subjectRanks(array $collected, int $precision): array
    {
        $ranks = [];

        foreach ($this->scoresByAllocation($collected) as $allocationId => $scores) {
            $table = Ranking::rank($scores, $precision);
            $byEnrollment = [];

            foreach ($collected['enrollment_ids'] as $enrollmentId) {
                $byEnrollment[$enrollmentId] = $table->rankOf($enrollmentId);
            }

            $ranks[$allocationId] = [
                'denominator' => $table->denominator,
                'by_enrollment' => $byEnrollment,
            ];
        }

        return $ranks;
    }

    /**
     * @param  array{subject_results: array<int, list<SubjectResult>>, allocations: array<int, stdClass>, enrollment_ids: list<int>, blocking: list<string>, policy_notes: array<int, list<array<string, mixed>>>}  $collected
     * @return array<int|string, array<int, Score|null>>
     */
    private function scoresByAllocation(array $collected): array
    {
        $byAllocation = [];

        foreach ($collected['subject_results'] as $enrollmentId => $subjects) {
            foreach ($subjects as $subject) {
                $byAllocation[$subject->key][$enrollmentId] = $subject->score;
            }
        }

        return $byAllocation;
    }

    // -----------------------------------------------------------------------
    // 12's report-card content entities, and the two documented holes
    // -----------------------------------------------------------------------

    /**
     * 14. Never a fabricated figure.
     *
     * @return array<string, mixed>
     */
    private function attendanceHole(): array
    {
        return [
            'available' => false,
            'note' => self::ATTENDANCE_UNAVAILABLE_NOTE,
            'hours_absent_justified' => null,
            'hours_absent_unjustified' => null,
            'late_count' => null,
            'consignes' => null,
            'exclusions' => null,
        ];
    }

    /**
     * 12.3 `ConductAssessment`, 12.1 `ReportCardRemark`, 12.4/12.5 the conseil.
     *
     * These three tables belong to another author's slice of this same phase.
     * Where a table is absent the block reports itself unavailable rather than
     * being silently omitted, so the difference between "the school recorded no
     * conduct" and "this build cannot record conduct" stays visible on the
     * card.
     *
     * @return array<string, mixed>
     */
    private function conductBlock(int $enrollmentId, int $periodId): array
    {
        if (! Schema::hasTable('conduct_assessments')) {
            return ['available' => false, 'note' => 'Conduct capture is not installed in this build (01-assessment 12.3).'];
        }

        $row = DB::table('conduct_assessments')
            ->where('enrollment_id', '=', $enrollmentId)
            ->where('assessment_period_id', '=', $periodId)
            ->first();

        if ($row === null) {
            return ['available' => true, 'recorded' => false];
        }

        // Resolve the five level ids to their printable labels. A bulletin
        // prints "Tres bien", not level id 3, and the label is read from the
        // scale rather than mapped here so a school that renames a level sees
        // its own wording on the card.
        $levels = DB::table('conduct_scale_levels')
            ->where('conduct_scale_id', '=', $row->conduct_scale_id)
            ->get()
            ->keyBy('id');

        $dimensions = [];

        foreach (['conduite', 'travail', 'assiduite', 'discipline', 'tenue'] as $dimension) {
            $levelId = $row->{$dimension.'_level_id'} ?? null;
            $level = $levelId === null ? null : ($levels[$levelId] ?? null);

            $dimensions[$dimension] = $level === null ? null : [
                'code' => (string) $level->code,
                'label' => (string) $level->label,
                'label_fr' => (string) $level->label_fr,
            ];
        }

        return [
            'available' => true,
            'recorded' => true,
            'dimensions' => $dimensions,
            'notes' => $row->notes,
            'values' => (array) $row,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function remarksBlock(int $enrollmentId, int $periodId): array
    {
        if (! Schema::hasTable('report_card_remarks')) {
            return ['available' => false, 'note' => 'Remark capture is not installed in this build (01-assessment 12.1).'];
        }

        $rows = DB::table('report_card_remarks')
            ->where('enrollment_id', '=', $enrollmentId)
            ->where('assessment_period_id', '=', $periodId)
            ->orderBy('scope')
            ->get()
            ->all();

        return [
            'available' => true,
            'entries' => array_values(array_map(static fn (stdClass $row): array => (array) $row, $rows)),
        ];
    }

    /**
     * 12.2, C6: an award appears on a card ONLY because a `ConseilDecision`
     * row exists with `decided_at` set. It is never derived from a grade band -
     * that fabricates an award on a permanent record, showing a student as
     * having received Felicitations from a body that never met.
     *
     * @return array<string, mixed>
     */
    private function conseilBlock(int $enrollmentId, int $classGroupId, int $periodId): array
    {
        if (! Schema::hasTable('conseil_decisions') || ! Schema::hasTable('conseil_de_classes')) {
            return ['available' => false, 'note' => 'Conseil de classe capture is not installed in this build (01-assessment 12.4).'];
        }

        $row = DB::table('conseil_decisions')
            ->join('conseil_de_classes', 'conseil_de_classes.id', '=', 'conseil_decisions.conseil_id')
            ->where('conseil_decisions.enrollment_id', '=', $enrollmentId)
            ->where('conseil_de_classes.class_group_id', '=', $classGroupId)
            ->where('conseil_de_classes.assessment_period_id', '=', $periodId)
            ->whereNotNull('conseil_decisions.decided_at')
            ->select('conseil_decisions.*')
            ->first();

        return $row === null
            ? ['available' => true, 'decided' => false]
            : ['available' => true, 'decided' => true, 'decision' => (array) $row];
    }

    // -----------------------------------------------------------------------
    // Small helpers
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function studentBlock(int $enrollmentId, ?stdClass $student): array
    {
        if ($student === null) {
            return ['enrollment_id' => $enrollmentId];
        }

        return [
            'enrollment_id' => $enrollmentId,
            'student_id' => (int) $student->student_id,
            'matricule' => $student->matricule,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'date_of_birth' => $student->date_of_birth,
            'gender' => $student->gender ?? null,
            'is_repeat' => (bool) $student->is_repeat,
            'boarding_status' => $student->boarding_status,
        ];
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, stdClass>
     */
    private function students(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $rows = DB::table('enrollments')
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->whereIn('enrollments.id', $enrollmentIds)
            ->select([
                'enrollments.id as enrollment_id',
                'enrollments.student_id',
                'enrollments.is_repeat',
                'enrollments.boarding_status',
                'students.matricule',
                'students.first_name',
                'students.last_name',
                'students.date_of_birth',
                'students.gender',
            ])
            ->get();

        $students = [];

        foreach ($rows as $row) {
            $students[(int) $row->enrollment_id] = $row;
        }

        return $students;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function segmentHistory(int $enrollmentId, stdClass $period): array
    {
        $rows = DB::table('enrollment_segments')
            ->where('enrollment_id', '=', $enrollmentId)
            ->where('starts_on', '<=', (string) $period->ends_on)
            ->where(function (QueryBuilder $query) use ($period): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', (string) $period->starts_on);
            })
            ->orderBy('starts_on')
            ->get()
            ->all();

        return array_values(array_map(static fn (stdClass $row): array => [
            'class_group_id' => (int) $row->class_group_id,
            'starts_on' => $row->starts_on,
            'ends_on' => $row->ends_on,
            'reason' => $row->reason,
        ], $rows));
    }

    /**
     * @param  array<int, stdClass>  $allocations
     * @return array<int|string, array{name: string, name_fr: string}>
     */
    private function subjectNames(array $allocations): array
    {
        if ($allocations === []) {
            return [];
        }

        $subjectIds = array_map(static fn (stdClass $a): int => (int) $a->subject_id, $allocations);
        $subjects = DB::table('subjects')->whereIn('id', $subjectIds)->get()->keyBy('id');

        $names = [];

        foreach ($allocations as $allocationId => $allocation) {
            $subject = $subjects->get((int) $allocation->subject_id);

            $names[$allocationId] = [
                'name' => is_object($subject) && is_string($subject->name ?? null) ? $subject->name : 'Subject',
                'name_fr' => is_object($subject) && is_string($subject->name_fr ?? null) ? $subject->name_fr : 'Matière',
            ];
        }

        return $names;
    }

    /**
     * @return list<GradeBand>
     */
    private function bandsFor(int $frameworkId): array
    {
        /** @var list<GradeBand> $bands */
        $bands = GradeBand::query()
            ->where('framework_id', '=', $frameworkId)
            ->where('purpose', '=', GradeBand::PURPOSE_INTERNAL)
            ->where('scale_basis', '=', GradeBand::BASIS_OUT_OF_MAX)
            ->orderBy('min_score')
            ->get()
            ->all();

        return $bands;
    }

    /**
     * 3.3: half-open [min, max) except the top band, which is closed, so a
     * perfect score bands.
     *
     * @param  list<GradeBand>  $bands
     */
    private function bandFor(array $bands, Score $score): ?GradeBand
    {
        $top = count($bands) - 1;

        foreach ($bands as $index => $band) {
            if ($band->contains($score, $index === $top)) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function componentBlock(SubjectResult $subject): array
    {
        $components = [];

        foreach ($subject->componentOutcomes as $outcome) {
            $components[] = [
                'code' => $outcome->componentCode,
                'original_state' => $outcome->originalState->value,
                'effective_state' => $outcome->effectiveState->value,
                'effective_max' => $outcome->effectiveMaximum->toString(),
                'weight' => $outcome->weight,
                'weight_retained' => $outcome->weightRetained,
                'printed_marker' => $outcome->effectiveState->printedMarker(),
            ];
        }

        return $components;
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $subject
     */
    private function cell(array $column, array $subject): mixed
    {
        $key = is_string($column['key'] ?? null) ? $column['key'] : '';

        return match ($key) {
            'subject_name' => $subject['subject_name_fr'] ?? $subject['subject_name'] ?? null,
            'subject_score' => $subject['subject_score'] ?? null,
            'coefficient' => $subject['coefficient'] ?? null,
            'score_times_coef' => $subject['score_times_coef'] ?? null,
            'subject_rank' => $subject['subject_rank'] ?? null,
            'cote_min_max' => $this->cote($column, $subject),
            'class_average_subject' => $subject['class_average_subject'] ?? null,
            'appreciation' => $subject['appreciation'] ?? null,
            'grade_letter' => $subject['grade_letter'] ?? null,
            'grade_point' => $subject['grade_point'] ?? null,
            'teacher_name' => $subject['teacher_name'] ?? null,
            'teacher_visa' => $subject['teacher_visa'] ?? null,
            'competencies_assessed' => $subject['components'] ?? null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $column
     * @param  array<string, mixed>  $subject
     */
    private function cote(array $column, array $subject): ?string
    {
        $min = $subject['cote_min'] ?? null;
        $max = $subject['cote_max'] ?? null;

        if (! is_string($min) || ! is_string($max)) {
            return null;
        }

        $format = is_string($column['format'] ?? null) ? $column['format'] : '[{min}–{max}]';

        return str_replace(['{min}', '{max}'], [$min, $max], $format);
    }

    /**
     * 8.4 / T19 - a payload that carries no average carries no average COLUMN
     * either.
     *
     * @param  array<string, mixed>  $payload
     */
    private function columnIsSuppressed(string $key, array $payload): bool
    {
        return match ($key) {
            'coefficient', 'score_times_coef' => ! array_key_exists('totals', $payload),
            'subject_rank' => ! array_key_exists('rank', $payload),
            'appreciation', 'grade_letter', 'grade_point' => ! array_key_exists('mention', $payload),
            default => false,
        };
    }

    /**
     * @param  array<array-key, mixed>  $blocks
     */
    private function blockEnabled(array $blocks, string $block): bool
    {
        $setting = $blocks[$block] ?? false;

        if (is_bool($setting)) {
            return $setting;
        }

        return is_array($setting) && ($setting['enabled'] ?? false) === true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|false  false = this payload has no such concept (8.4)
     */
    private function blockContent(string $block, array $payload): array|false
    {
        return match ($block) {
            'totals_row' => $this->present($payload, 'totals'),
            'general_average_and_rank' => $this->averageAndRank($payload),
            'mention' => array_key_exists('mention', $payload) && is_string($payload['mention'])
                ? ['mention' => $payload['mention']]
                : false,
            'gpa' => array_key_exists('gpa', $payload) && is_string($payload['gpa'])
                ? ['gpa' => $payload['gpa']]
                : false,
            'class_statistics' => $this->present($payload, 'class_statistics'),
            'conduct' => $this->present($payload, 'conduct'),
            'absence_hours' => $this->present($payload, 'attendance'),
            'remarks' => $this->present($payload, 'remarks'),
            'conseil_award' => $this->present($payload, 'conseil'),
            'student_identity' => $this->present($payload, 'student'),
            'version_and_issue_date' => $this->present($payload, 'issue'),
            // 13.7 blocks with no payload of their own: they are layout
            // instructions (letterhead, signatures, the QR target) and carry
            // nothing this document resolves.
            'state_header', 'subject_table', 'signatures', 'qr_verification' => ['enabled' => true],
            // 04-fees, Phase 4. Same discipline as attendance: a named hole, not
            // an invented balance.
            'fee_balance' => [
                'available' => false,
                'note' => 'The fee-balance block is provided by 04-fees, which is a later phase.',
            ],
            'previous_period_average', 'annual_average' => $this->present($payload, $block),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|false
     */
    private function present(array $payload, string $key): array|false
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|false
     */
    private function averageAndRank(array $payload): array|false
    {
        if (! array_key_exists('general_average', $payload)) {
            return false;
        }

        $block = ['general_average' => $payload['general_average']];

        if (array_key_exists('rank', $payload)) {
            $block['rank'] = $payload['rank'];
        }

        if (array_key_exists('rank_frozen_at', $payload)) {
            $block['rank_frozen_at'] = $payload['rank_frozen_at'];
        }

        return $block;
    }

    private function periodRow(int $periodId): stdClass
    {
        $row = DB::table('assessment_periods')->where('id', '=', $periodId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'assessment_period_id' => "Assessment period {$periodId} does not exist.",
            ]);
        }

        return $row;
    }

    private function classGroupRow(int $classGroupId): stdClass
    {
        $row = DB::table('class_groups')->where('id', '=', $classGroupId)->first();

        if ($row === null) {
            throw ValidationException::withMessages([
                'class_group_id' => "Class group {$classGroupId} does not exist.",
            ]);
        }

        return $row;
    }

    private function framework(stdClass $period): AssessmentFramework
    {
        if (! is_numeric($period->framework_id)) {
            throw ValidationException::withMessages([
                'framework_id' => 'This assessment period has no assessment framework; a report card cannot be '
                    .'rendered until one is configured (01-assessment 3.1).',
            ]);
        }

        /** @var AssessmentFramework $framework */
        $framework = AssessmentFramework::query()->findOrFail((int) $period->framework_id);

        return $framework;
    }

    /** "4.00" => 400, with no float in the middle (00-core 7.1). */
    private function hundredths(string $decimal): int
    {
        if (preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', trim($decimal), $m) !== 1) {
            throw ValidationException::withMessages([
                'coefficient' => "Coefficient `{$decimal}` is not a two-decimal value.",
            ]);
        }

        return (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
    }

    /** 400 => "4.00". */
    private function decimal(int $hundredths): string
    {
        return sprintf('%d.%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private function timesCoefficient(Score $score, int $coefficientHundredths, int $precision): string
    {
        return Score::ofThousandths(
            intdiv(Rounding::halfUp($score, $precision)->thousandths() * $coefficientHundredths, 100),
        )->toDisplayString();
    }

    /**
     * 13.6: the card states the derivation beneath the totals row -
     * "Moyenne = 234,25 / 18 = 13,01" - because that is where a Cameroonian
     * reader checks the arithmetic by hand.
     */
    private function derivation(int $sumTimesCoefThousandths, int $sumCoefficientHundredths, ?string $average): string
    {
        if ($sumCoefficientHundredths === 0 || $average === null) {
            // 10.2, C3: Sigma-coef = 0 is NULL, never 0.00, and never a blank
            // that reads as zero.
            return 'Non évalué / Not assessed';
        }

        return sprintf(
            'Moyenne = %s / %s = %s',
            str_replace('.', ',', Score::ofThousandths($sumTimesCoefThousandths)->toDisplayString()),
            str_replace('.', ',', $this->decimal($sumCoefficientHundredths)),
            str_replace('.', ',', Score::of($average)->toDisplayString()),
        );
    }

}
